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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Task validation contract matrix tests.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\task_registry;

/**
 * Baseline validation contracts per task.
 *
 * @coversNothing
 */
final class task_validation_matrix_test extends advanced_testcase {
    /** @var int */
    private int $cmid;

    /** @var task_registry */
    private task_registry $registry;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Task Matrix Booking',
        ]);

        $this->cmid = (int)$booking->cmid;
        $this->registry = task_registry::make_default();
    }

    /**
     * Every registered task must expose stable schema + validation keys.
     */
    public function test_every_task_exposes_schema_and_validation_contract(): void {
        foreach ($this->registry->get_task_names() as $taskname) {
            $task = $this->registry->get_task($taskname);
            $this->assertNotNull($task, 'Task missing in registry: ' . $taskname);

            $schema = $task->get_schema();
            $this->assertIsArray($schema, 'Schema must be array for task: ' . $taskname);
            $this->assertNotEmpty($schema, 'Schema must not be empty for task: ' . $taskname);

            $validation = $task->validate([], $this->cmid);
            $this->assertIsArray($validation, 'Validation must return array for task: ' . $taskname);
            $this->assertArrayHasKey('valid', $validation, 'Missing valid key for task: ' . $taskname);
            $this->assertArrayHasKey('errors', $validation, 'Missing errors key for task: ' . $taskname);
            $this->assertArrayHasKey('ambiguities', $validation, 'Missing ambiguities key for task: ' . $taskname);
            $this->assertIsArray($validation['errors'], 'Errors must be array for task: ' . $taskname);
            $this->assertIsArray($validation['ambiguities'], 'Ambiguities must be array for task: ' . $taskname);
        }
    }

    /**
     * Tasks should honor expected validity for minimal contract matrix cases.
     *
     * @dataProvider provide_task_minimal_contract_cases
     * @param string $taskname
     * @param array $input
     * @param bool $expectvalid
     */
    public function test_task_minimal_input_contract_matrix(string $taskname, array $input, bool $expectvalid): void {
        $task = $this->registry->get_task($taskname);
        $this->assertNotNull($task, 'Task missing: ' . $taskname);

        $validation = $task->validate($input, $this->cmid);
        $this->assertSame($expectvalid, (bool)($validation['valid'] ?? false));
    }

    /**
     * Minimal contract matrix for core tasks.
     *
     * @return array
     */
    public static function provide_task_minimal_contract_cases(): array {
        return [
            'create_option_minimal_valid' => [
                'booking.create_option',
                [
                    'text' => 'Matrix Option',
                    'maxanswers' => 5,
                    'coursestarttime' => '2036-01-01T10:00:00',
                    'duration' => 3600,
                    'location' => 'Room A',
                    'teacherquery' => '__current_user__',
                ],
                true,
            ],
            'create_option_missing_required' => [
                'booking.create_option',
                ['text' => 'Matrix Incomplete'],
                false,
            ],
            'update_option_missing_target' => [
                'booking.update_option',
                ['maxanswers' => 8],
                false,
            ],
            'bulk_update_missing_target' => [
                'booking.bulk_update_options',
                ['maxanswers' => 10],
                false,
            ],
            'search_options_blank_query_is_valid' => [
                'booking.search_options',
                ['query' => ''],
                true,
            ],
            'search_users_blank_query_invalid' => [
                'booking.search_users',
                ['query' => ''],
                false,
            ],
            'search_courses_blank_query_invalid' => [
                'booking.search_courses',
                ['query' => ''],
                false,
            ],
            'list_actions_valid' => [
                'booking.list_actions',
                ['scope' => 'all'],
                true,
            ],
            'list_option_properties_valid' => [
                'booking.list_option_properties',
                ['scope' => 'all'],
                true,
            ],
            'get_current_user_valid' => [
                'booking.get_current_user',
                [],
                true,
            ],
            'add_price_category_missing_name_invalid' => [
                'booking.add_price_category',
                [],
                false,
            ],
        ];
    }
}
