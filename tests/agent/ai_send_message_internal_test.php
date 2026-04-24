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

/**
 * Internal tests for ai_send_message helper logic.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\external\ai_send_message;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\interfaces\task_provider_interface;
use mod_booking\local\wbagent\task_registry;

/**
 * Tests for internal ai_send_message helpers used by mixed command handling.
 *
 * @runTestsInSeparateProcesses
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_send_message_internal_test extends advanced_testcase {
    /**
     * split_commands_by_mutability should classify unknown/malformed entries as mutating.
     */
    public function test_split_commands_by_mutability_is_safety_first(): void {
        $registry = new task_registry();
        $registry->register($this->make_provider('local_split', [
            $this->make_task('booking.read_task', true),
            $this->make_task('booking.write_task', false),
        ]));

        $commands = [
            ['task' => 'booking.read_task', 'input' => []],
            ['task' => 'booking.write_task', 'input' => []],
            'broken-command',
            ['task' => 'booking.unknown_task', 'input' => []],
        ];

        $split = $this->invoke_private_static(
            ai_send_message::class,
            'split_commands_by_mutability',
            [$commands, $registry]
        );

        $this->assertCount(1, $split['readonly']);
        $this->assertCount(3, $split['mutating']);
        $this->assertSame('booking.read_task', $split['readonly'][0]['task']);
    }

    /**
     * execution_result_has_failures should detect failing statuses and explicit error responses.
     */
    public function test_execution_result_has_failures_detects_failures(): void {
        $this->assertTrue($this->invoke_private_static(
            ai_send_message::class,
            'execution_result_has_failures',
            [['response_type' => 'error']]
        ));

        $this->assertTrue($this->invoke_private_static(
            ai_send_message::class,
            'execution_result_has_failures',
            [['response_type' => 'execution_result', 'results' => [['status' => 'error']]]]
        ));

        $this->assertFalse($this->invoke_private_static(
            ai_send_message::class,
            'execution_result_has_failures',
            [['response_type' => 'execution_result', 'results' => [['status' => 'executed']]]]
        ));
    }

    /**
     * Duplicate-title prompt detection must rely on structured issue codes, not localized message text.
     */
    public function test_has_recent_duplicate_title_prompt_uses_issue_codes(): void {
        $message = new \stdClass();
        $message->role = 'assistant';
        $message->content = 'Bitte bestaetigen Sie...';
        $message->structuredjson = json_encode([
            'response_type' => 'confirmation_request',
            'issue_codes' => ['DUPLICATE_TITLE_CONFIRM_REQUIRED'],
        ]);

        $store = $this->createMock(conversation_store::class);
        $store->method('get_recent_messages')->willReturn([$message]);

        $result = $this->invoke_private_static(
            ai_send_message::class,
            'has_recent_duplicate_title_prompt',
            [$store, 123]
        );

        $this->assertTrue($result);
    }

    /**
     * Trigger checks must rely on structured used_triggers data.
     */
    public function test_result_has_trigger_reads_structured_trigger_ids(): void {
        $result = $this->invoke_private_static(
            ai_send_message::class,
            'result_has_trigger',
            [[
                'used_triggers' => ['core.is_lookup_request', 'core.is_preview_request'],
            ], 'core.is_preview_request']
        );

        $this->assertTrue($result);
    }

    /**
     * Confirmation commands must be revalidated before confirm button is shown.
     */
    public function test_prevalidate_confirmation_commands_detects_invalid_input(): void {
        $registry = new task_registry();
        $registry->register($this->make_provider('local_validation', [
            $this->make_task_with_custom_validate('booking.write_task', false, static function (array $input): array {
                return [
                    'valid' => false,
                    'errors' => ['Missing required test field.'],
                    'ambiguities' => [],
                    'issues' => [
                        ['code' => 'MISSING_TEST_FIELD'],
                    ],
                ];
            }),
        ]));

        $result = $this->invoke_private_static(
            ai_send_message::class,
            'prevalidate_confirmation_commands',
            [[['task' => 'booking.write_task', 'input' => []]], $registry, 1]
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required test field.', $result['errors']);
        $this->assertContains('booking.write_task', $result['attempted_tasks']);
        $this->assertContains('MISSING_TEST_FIELD', $result['issue_codes']);
    }

    /**
     * Invoke a private static method via reflection.
     *
     * @param string $classname
     * @param string $method
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function invoke_private_static(string $classname, string $method, array $args) {
        $reflection = new \ReflectionClass($classname);
        $target = $reflection->getMethod($method);
        $target->setAccessible(true);
        return $target->invokeArgs(null, $args);
    }

    /**
     * Create a lightweight task double.
     *
     * @param string $name
     * @param bool $readonly
     * @return task_interface
     */
    private function make_task(string $name, bool $readonly): task_interface {
        return new class ($name, $readonly) implements task_interface {
            /** @var string */
            private string $name;
            /** @var bool */
            private bool $readonly;

            public function __construct(string $name, bool $readonly) {
                $this->name = $name;
                $this->readonly = $readonly;
            }

            public function get_name(): string {
                return $this->name;
            }

            public function get_schema(): array {
                return [];
            }

            public function validate(array $input, int $cmid): array {
                return ['valid' => true, 'errors' => [], 'ambiguities' => []];
            }

            public function execute(array $input, int $cmid, int $userid): array {
                return ['status' => 'executed', 'detail' => 'ok', 'resultid' => null];
            }

            public function is_read_only(): bool {
                return $this->readonly;
            }
        };
    }

    /**
     * Create a task double with custom validation callback.
     *
     * @param string $name
     * @param bool $readonly
     * @param callable $validator
     * @return task_interface
     */
    private function make_task_with_custom_validate(string $name, bool $readonly, callable $validator): task_interface {
        return new class ($name, $readonly, $validator) implements task_interface {
            /** @var string */
            private string $name;
            /** @var bool */
            private bool $readonly;
            /** @var callable */
            private $validator;

            public function __construct(string $name, bool $readonly, callable $validator) {
                $this->name = $name;
                $this->readonly = $readonly;
                $this->validator = $validator;
            }

            public function get_name(): string {
                return $this->name;
            }

            public function get_schema(): array {
                return [];
            }

            public function validate(array $input, int $cmid): array {
                return ($this->validator)($input);
            }

            public function execute(array $input, int $cmid, int $userid): array {
                return ['status' => 'executed', 'detail' => 'ok', 'resultid' => null];
            }

            public function is_read_only(): bool {
                return $this->readonly;
            }
        };
    }

    /**
     * Create a lightweight provider double.
     *
     * @param string $component
     * @param array<int,task_interface> $tasks
     * @return task_provider_interface
     */
    private function make_provider(string $component, array $tasks): task_provider_interface {
        return new class ($component, $tasks) implements task_provider_interface {
            /** @var string */
            private string $component;
            /** @var array<int,task_interface> */
            private array $tasks;

            public function __construct(string $component, array $tasks) {
                $this->component = $component;
                $this->tasks = $tasks;
            }

            public function get_component(): string {
                return $this->component;
            }

            public function get_tasks(): array {
                return $this->tasks;
            }

            public function get_contextual_prompt_packs(): array {
                return [];
            }
        };
    }
}
