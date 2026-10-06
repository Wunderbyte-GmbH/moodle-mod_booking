<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace bookingextension_agent;

use advanced_testcase;
use context_system;
use bookingextension_agent\event\action_confirmed;
use bookingextension_agent\event\action_denied;
use bookingextension_agent\event\skill_executed;
use bookingextension_agent\event\skill_write_executed;
use bookingextension_agent\local\wizard\services\telemetry\audit_logger;

/**
 * Unit tests for the audit event facade: gating, CRUD derivation, redaction, outcomes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\telemetry\audit_logger
 * @covers     \bookingextension_agent\event\skill_executed
 * @covers     \bookingextension_agent\event\skill_write_executed
 * @covers     \bookingextension_agent\event\action_denied
 * @covers     \bookingextension_agent\event\action_confirmed
 */
final class audit_logger_test extends advanced_testcase {
    /**
     * Build a duck-typed fake skill for the facade.
     *
     * @param array $opts readonly, crud, risk, sensitive[], related[], properties[]
     * @return object
     */
    private function fake_skill(array $opts): object {
        return new class ($opts) {
            /** @var array */
            private array $opts;

            /**
             * Store the behaviour options.
             *
             * @param array $opts
             */
            public function __construct(array $opts) {
                $this->opts = $opts;
            }

            /**
             * Whether the fake skill is read-only.
             *
             * @return bool
             */
            public function is_read_only(): bool {
                return (bool)($this->opts['readonly'] ?? true);
            }

            /**
             * Declared audit CRUD letter.
             *
             * @return string
             */
            public function get_log_crud(): string {
                return (string)($this->opts['crud'] ?? ($this->is_read_only() ? 'r' : 'u'));
            }

            /**
             * Declared risk class.
             *
             * @return string
             */
            public function get_risk_class(): string {
                return (string)($this->opts['risk'] ?? 'read_only');
            }

            /**
             * Minimal schema exposing the configured properties.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['properties' => (array)($this->opts['properties'] ?? [])];
            }

            /**
             * Fields to redact from the audit payload.
             *
             * @return array
             */
            public function get_sensitive_input_fields(): array {
                return (array)($this->opts['sensitive'] ?? []);
            }

            /**
             * Related user ids the action concerns.
             *
             * @param array $input
             * @return array
             */
            public function get_related_userids(array $input): array {
                return (array)($this->opts['related'] ?? []);
            }
        };
    }

    /**
     * Trigger an execution and return the resulting audit events (read or write class).
     *
     * @param object $skill
     * @param array  $input
     * @param mixed  $result
     * @return \core\event\base[]
     */
    private function capture_executed(object $skill, array $input, $result): array {
        $ctxid = (int)context_system::instance()->id;
        $sink = $this->redirectEvents();
        audit_logger::skill_executed($skill, 'x.skill', $input, $result, $ctxid, 2, 0, 0, 'chat', 12.7);
        $sink->close();
        return array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof skill_executed || $e instanceof skill_write_executed
        ));
    }

    /**
     * A read-only skill raises skill_executed with CRUD 'r' and success outcome.
     */
    public function test_readonly_skill_logs_crud_r(): void {
        $this->resetAfterTest();
        $events = $this->capture_executed($this->fake_skill(['readonly' => true]), ['a' => 'b'], ['status' => 'ok']);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(skill_executed::class, $events[0]);
        $this->assertSame('r', $events[0]->other['crud']);
        $this->assertTrue($events[0]->other['readonly']);
        $this->assertSame('chat', $events[0]->other['channel']);
        $this->assertSame('success', $events[0]->other['outcome']);
    }

    /**
     * A writing skill raises skill_write_executed, carrying its precise CRUD and derived outcome.
     */
    public function test_write_skill_crud_and_error_outcome(): void {
        $this->resetAfterTest();
        $skill = $this->fake_skill(['readonly' => false, 'crud' => 'c', 'risk' => 'scoped_write']);
        $events = $this->capture_executed($skill, [], ['status' => 'error']);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(skill_write_executed::class, $events[0]);
        $this->assertSame('c', $events[0]->other['crud']);
        $this->assertFalse($events[0]->other['readonly']);
        $this->assertSame('scoped_write', $events[0]->other['riskclass']);
        $this->assertSame('error', $events[0]->other['outcome']);
    }

    /**
     * The master switch suppresses every audit event when off.
     */
    public function test_master_switch_off_suppresses_all(): void {
        $this->resetAfterTest();
        set_config('logskillexecution', 0, 'bookingextension_agent');
        $this->assertCount(0, $this->capture_executed($this->fake_skill(['readonly' => false]), [], ['status' => 'ok']));
    }

    /**
     * logreadskills off suppresses read-only executions but not writes.
     */
    public function test_logreadskills_off_suppresses_reads_only(): void {
        $this->resetAfterTest();
        set_config('logreadskills', 0, 'bookingextension_agent');
        $this->assertCount(0, $this->capture_executed($this->fake_skill(['readonly' => true]), [], ['status' => 'ok']));
        $this->assertCount(1, $this->capture_executed($this->fake_skill(['readonly' => false]), [], ['status' => 'ok']));
    }

    /**
     * Sensitive and non-schema fields are dropped; long strings truncated.
     */
    public function test_args_are_redacted(): void {
        $this->resetAfterTest();
        $skill = $this->fake_skill([
            'readonly' => true,
            'properties' => ['keep' => [], 'password' => []],
            'sensitive' => ['password'],
        ]);
        $input = ['keep' => str_repeat('x', 300), 'password' => 'topsecret', 'notinschema' => 'z'];
        $events = $this->capture_executed($skill, $input, ['status' => 'ok']);
        $args = $events[0]->other['args'];
        $this->assertArrayHasKey('keep', $args);
        $this->assertArrayNotHasKey('password', $args);
        $this->assertArrayNotHasKey('notinschema', $args);
        $this->assertStringEndsWith('…', $args['keep']);
        $this->assertSame(201, \core_text::strlen($args['keep']));
        $this->assertStringNotContainsString('topsecret', json_encode($args));
    }

    /**
     * Related user ids surface as relateduserid plus the full list in other.
     */
    public function test_related_userids_are_recorded(): void {
        $this->resetAfterTest();
        $events = $this->capture_executed($this->fake_skill(['related' => [7, 9]]), [], ['status' => 'ok']);
        $this->assertSame(7, (int)$events[0]->relateduserid);
        $this->assertSame([7, 9], $events[0]->other['relateduserids']);
    }

    /**
     * action_confirmed records the confirmed skill and channel.
     */
    public function test_action_confirmed_event(): void {
        $this->resetAfterTest();
        $ctxid = (int)context_system::instance()->id;
        $sink = $this->redirectEvents();
        audit_logger::action_confirmed('course.update_activity', $ctxid, 2, 5, 9, 'mcp');
        $sink->close();
        $events = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof action_confirmed));
        $this->assertCount(1, $events);
        $this->assertSame('course.update_activity', $events[0]->other['skill']);
        $this->assertSame('mcp', $events[0]->other['channel']);
        $this->assertSame('u', $events[0]->get_data()['crud']);
    }

    /**
     * action_denied records the gate, reason and channel.
     */
    public function test_action_denied_event(): void {
        $this->resetAfterTest();
        $ctxid = (int)context_system::instance()->id;
        $sink = $this->redirectEvents();
        audit_logger::action_denied('x.write', 'guard', 'EXECUTION_GUARD_MISSING', $ctxid, 2, 0, 0, 'mcp');
        $sink->close();
        $events = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof action_denied));
        $this->assertCount(1, $events);
        $this->assertSame('guard', $events[0]->other['gate']);
        $this->assertSame('EXECUTION_GUARD_MISSING', $events[0]->other['reason']);
        $this->assertSame('mcp', $events[0]->other['channel']);
    }
}
