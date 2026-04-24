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
 * Tests for task registry behavior.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\interfaces\task_provider_interface;
use mod_booking\local\wbagent\task_registry;

/**
 * Tests for task registry duplicate handling.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_registry_test extends advanced_testcase {
    /**
     * Duplicate task names should not throw and should keep the first registered task.
     */
    public function test_duplicate_task_name_keeps_first_registered_task(): void {
        $registry = new task_registry();

        $firsttask = $this->make_task('booking.duplicate_task', true);
        $secondtask = $this->make_task('booking.duplicate_task', false);

        $registry->register($this->make_provider('local_first', [$firsttask]));
        $registry->register($this->make_provider('local_second', [$secondtask]));

        $resolved = $registry->get_task('booking.duplicate_task');
        $this->assertSame($firsttask, $resolved);
        $this->assertTrue($registry->is_read_only_task('booking.duplicate_task'));
    }

    /**
     * Empty task names should be ignored.
     */
    public function test_empty_task_name_is_ignored(): void {
        $registry = new task_registry();

        $registry->register($this->make_provider('local_empty', [
            $this->make_task('', true),
            $this->make_task('booking.valid_task', true),
        ]));

        $this->assertNull($registry->get_task(''));
        $this->assertNotNull($registry->get_task('booking.valid_task'));
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
