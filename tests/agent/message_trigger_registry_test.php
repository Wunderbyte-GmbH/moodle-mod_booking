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
 * Tests for trigger catalog and normalization.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\booking\tasks\add_price_category_task;
use mod_booking\local\wbagent\booking\tasks\bulk_update_options_task;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\interfaces\task_provider_interface;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\local\wbagent\message_trigger_registry;
use mod_booking\local\wbagent\booking\tasks\search_options_task;
use mod_booking\local\wbagent\task_registry;
use mod_booking\local\wbagent\booking\tasks\update_option_task;

/**
 * Trigger registry tests.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class message_trigger_registry_test extends advanced_testcase {
    /**
     * Unknown trigger ids returned by the LLM must be dropped.
     */
    public function test_normalize_used_triggers_filters_unknown_ids(): void {
        $registry = new task_registry();
        $registry->register($this->make_provider('local_trigger', [
            $this->make_task_with_trigger('booking.trigger_task', false, 'booking.custom_trigger'),
        ]));

        $triggerregistry = new message_trigger_registry($registry);
        $normalized = $triggerregistry->normalize_used_triggers([
            'core.is_lookup_request',
            'booking.custom_trigger',
            'unknown.trigger',
            'core.is_lookup_request',
        ]);

        $this->assertSame(['core.is_lookup_request', 'booking.custom_trigger'], $normalized);
    }

    /**
     * Task-provided triggers should be exposed via registry.
     */
    public function test_available_triggers_include_task_contributions(): void {
        $registry = new task_registry();
        $registry->register($this->make_provider('local_trigger', [
            $this->make_task_with_trigger('booking.trigger_task', false, 'booking.custom_trigger'),
        ]));

        $triggerregistry = new message_trigger_registry($registry);
        $triggers = $triggerregistry->get_available_triggers();
        $ids = array_values(array_map(static fn(array $trigger): string => (string)$trigger['id'], $triggers));

        $this->assertContains('core.is_confirmation_message', $ids);
        $this->assertContains('booking.custom_trigger', $ids);
    }

    /**
     * High/medium-priority mod_booking tasks should expose dedicated trigger ids.
     */
    public function test_selected_booking_tasks_expose_message_triggers(): void {
        $tasks = [
            new create_option_task(),
            new update_option_task(),
            new bulk_update_options_task(),
            new add_price_category_task(),
            new search_options_task(),
        ];

        $allids = [];
        foreach ($tasks as $task) {
            $this->assertInstanceOf(task_trigger_provider_interface::class, $task);
            foreach ($task->get_message_triggers() as $trigger) {
                $allids[] = (string)($trigger['id'] ?? '');
            }
        }

        $this->assertContains('booking.force_create_duplicate_title', $allids);
        $this->assertContains('booking.use_preview_context_for_update', $allids);
        $this->assertContains('booking.bulk_update_apply_to_all_confirmed', $allids);
        $this->assertContains('booking.confirm_duplicate_price_category', $allids);
        $this->assertContains('booking.search_options_exact_title_match', $allids);
    }

    /**
     * Build a task that contributes one custom trigger.
     *
     * @param string $name
     * @param bool $readonly
     * @param string $triggerid
     * @return task_interface
     */
    private function make_task_with_trigger(string $name, bool $readonly, string $triggerid): task_interface {
        return new class ($name, $readonly, $triggerid) implements task_interface, task_trigger_provider_interface {
            /** @var string */
            private string $name;
            /** @var bool */
            private bool $readonly;
            /** @var string */
            private string $triggerid;

            public function __construct(string $name, bool $readonly, string $triggerid) {
                $this->name = $name;
                $this->readonly = $readonly;
                $this->triggerid = $triggerid;
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

            public function get_message_triggers(): array {
                return [[
                    'id' => $this->triggerid,
                    'description' => 'Custom trigger emitted by task.',
                ]];
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
