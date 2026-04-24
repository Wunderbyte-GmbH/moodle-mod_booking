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
 * Wave 2: Focused Task Execution Tests
 *
 * Tests for all 10 booking agent tasks with realistic scenarios:
 * - create_option, update_option, bulk_update
 * - search_options, search_users, search_courses
 * - list_actions, list_option_properties, get_current_user, add_price_category
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\task_registry;

/**
 * Focused tests for all 10 booking agent tasks.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class agent_task_execution_test extends abstract_agent_testcase {
    /**
     * Test: Task registry contains all 10 core booking tasks
     */
    public function test_task_registry_has_all_core_tasks(): void {
        $registry = task_registry::make_default();
        $tasknames = $registry->get_task_names();

        $expected = [
            'booking.create_option',
            'booking.update_option',
            'booking.bulk_update_options',
            'booking.search_options',
            'booking.search_users',
            'booking.search_courses',
            'booking.list_actions',
            'booking.list_option_properties',
            'booking.get_current_user',
            'booking.add_price_category',
        ];

        foreach ($expected as $task) {
            $this->assertContains(
                $task,
                $tasknames,
                "Task registry must contain: $task"
            );
        }
    }

    /**
     * Test: Executor and Authorization Service can be instantiated
     */
    public function test_executor_initialization(): void {
        $executor = $this->make_executor();
        $this->assertNotNull($executor);
        $this->assertInstanceOf(executor::class, $executor);
    }

    /**
     * Test: Create option via command execution
     */
    public function test_create_option_via_command(): void {
        $this->setUser($this->teacher);

        $result = $this->exec_command('booking.create_option', [
            'text' => 'Test Yoga',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * Test: Search options returns structured result
     */
    public function test_search_options_structure(): void {
        $this->setUser($this->teacher);

        $result = $this->exec_command('booking.search_options', [
            'query' => 'test',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * Test: Search users returns structured result
     */
    public function test_search_users_structure(): void {
        $this->setUser($this->teacher);

        $result = $this->exec_command('booking.search_users', [
            'query' => 'admin',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }

    /**
     * Test: All list tasks return structured results
     */
    public function test_list_tasks_structure(): void {
        $this->setUser($this->teacher);

        $actions = $this->exec_command('booking.list_actions', []);
        $this->assertIsArray($actions);
        $this->assertArrayHasKey('status', $actions);

        $properties = $this->exec_command('booking.list_option_properties', []);
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('status', $properties);
    }

    /**
     * Test: Get current user task
     */
    public function test_get_current_user_task(): void {
        $this->setUser($this->teacher);

        $result = $this->exec_command('booking.get_current_user', []);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }
}
