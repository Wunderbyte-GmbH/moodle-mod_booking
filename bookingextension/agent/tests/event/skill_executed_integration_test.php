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

use bookingextension_agent\event\action_denied;
use bookingextension_agent\event\skill_executed;
use bookingextension_agent\event\skill_write_executed;

/**
 * Executor-chokepoint audit events, exercised end-to-end through real skills.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\executor
 * @covers     \bookingextension_agent\event\skill_executed
 * @covers     \bookingextension_agent\event\skill_write_executed
 * @covers     \bookingextension_agent\event\action_denied
 */
final class skill_executed_integration_test extends abstract_agent_testcase {
    /**
     * Return the execution audit events (read or write class) for a given skill from a sink.
     *
     * @param \core\event\base[] $events
     * @param string             $skillname
     * @return \core\event\base[]
     */
    private function executed_for(array $events, string $skillname): array {
        return array_values(array_filter(
            $events,
            static fn($e) => ($e instanceof skill_executed || $e instanceof skill_write_executed)
                && ($e->other['skill'] ?? '') === $skillname
        ));
    }

    /**
     * A read-only skill run through the executor emits skill_executed (crud r, channel chat).
     */
    public function test_read_skill_emits_skill_executed(): void {
        $sink = $this->redirectEvents();
        $result = $this->exec_command('core.get_current_user', []);
        $sink->close();

        $this->assertNotSame('error', $result['status'] ?? '');
        $events = $this->executed_for($sink->get_events(), 'core.get_current_user');
        $this->assertCount(1, $events);
        $this->assertInstanceOf(skill_executed::class, $events[0]);
        $this->assertSame('r', $events[0]->other['crud']);
        $this->assertTrue($events[0]->other['readonly']);
        $this->assertSame('chat', $events[0]->other['channel']);
        $this->assertSame('success', $events[0]->other['outcome']);
    }

    /**
     * A writing skill emits skill_executed with a write CRUD and success outcome.
     */
    public function test_write_skill_emits_skill_executed(): void {
        $sink = $this->redirectEvents();
        $this->create_option('Audit Trail Option');
        $sink->close();

        $events = $this->executed_for($sink->get_events(), 'mod_booking.create_option');
        $this->assertCount(1, $events);
        $this->assertInstanceOf(skill_write_executed::class, $events[0]);
        $this->assertFalse($events[0]->other['readonly']);
        $this->assertContains($events[0]->other['crud'], ['c', 'u']);
        $this->assertSame('success', $events[0]->other['outcome']);
        $this->assertArrayHasKey('text', $events[0]->other['args']);
    }

    /**
     * A mutating command without a guard token is denied at the guard gate (and never executes).
     */
    public function test_missing_guard_token_emits_action_denied(): void {
        $command = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => [
                'text' => 'No Guard Option',
                'maxanswers' => 10,
                'coursestarttime' => '2045-03-15T09:00:00',
                'courseendtime' => '2045-03-15T17:00:00',
                'teacherquery' => '__current_user__',
            ],
        ];
        $sink = $this->redirectEvents();
        $result = $this->execute_command($command);
        $sink->close();

        $this->assertSame('error', $result['status'] ?? '');
        $this->assertContains('EXECUTION_GUARD_MISSING', (array)($result['issue_codes'] ?? []));

        $events = $sink->get_events();
        $denials = array_values(array_filter(
            $events,
            static fn($e) => $e instanceof action_denied && ($e->other['skill'] ?? '') === 'mod_booking.create_option'
        ));
        $this->assertCount(1, $denials);
        $this->assertSame('guard', $denials[0]->other['gate']);
        $this->assertSame('EXECUTION_GUARD_MISSING', $denials[0]->other['reason']);
        // The skill was refused before running, so there is no execution event.
        $this->assertCount(0, $this->executed_for($events, 'mod_booking.create_option'));
    }

    /**
     * With the master switch off, no skill_executed event is emitted (execution still happens).
     */
    public function test_master_switch_off_suppresses_event(): void {
        set_config('logskillexecution', 0, 'bookingextension_agent');
        $sink = $this->redirectEvents();
        $result = $this->exec_command('core.get_current_user', []);
        $sink->close();

        $this->assertNotSame('error', $result['status'] ?? '');
        $this->assertCount(0, $this->executed_for($sink->get_events(), 'core.get_current_user'));
    }
}
