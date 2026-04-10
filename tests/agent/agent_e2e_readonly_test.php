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
 * End-to-end tests: read-only agent tasks (search, list) via executor.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

require_once __DIR__ . '/abstract_agent_testcase.php';

use mod_booking\local\wbagent\task_registry;

/**
 * E2E tests for read-only agent tasks.
 *
 * search_options, list_actions and list_option_properties must all be flagged
 * as read-only and must execute without a real LLM.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_e2e_readonly_test extends abstract_agent_testcase {

    // -------------------------------------------------------------------------
    // search_options
    // -------------------------------------------------------------------------

    /**
     * search_options with a blank query returns all options in the instance.
     */
    public function test_search_options_blank_query_returns_all(): void {
        $this->create_option('Search Alpha');
        $this->create_option('Search Beta');

        $result = $this->exec_command('booking.search_options', ['query' => '']);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
        $this->assertArrayHasKey('options', $result);
        $this->assertGreaterThanOrEqual(2, count($result['options']));
    }

    /**
     * search_options with a keyword returns only matching options.
     */
    public function test_search_options_keyword_filters_results(): void {
        $this->create_option('Yoga Morning');
        $this->create_option('Pilates Evening');

        $result = $this->exec_command('booking.search_options', ['query' => 'Yoga']);

        $this->assertEquals('executed', $result['status']);
        $this->assertNotEmpty($result['options']);

        $names = array_column($result['options'], 'name');
        // All returned options should contain 'yoga' (case-insensitive).
        foreach ($names as $name) {
            $this->assertStringContainsStringIgnoringCase('yoga', $name);
        }
    }

    /**
     * search_options on an empty instance returns executed with empty detail.
     */
    public function test_search_options_empty_instance(): void {
        // No options created.
        $result = $this->exec_command('booking.search_options', ['query' => '']);

        $this->assertEquals('executed', $result['status']);
    }

    /**
     * search_options result includes previewoptionids.
     */
    public function test_search_options_includes_previewoptionids(): void {
        $opt = $this->create_option('Preview Search Option');

        $result = $this->exec_command('booking.search_options', ['query' => '']);

        $this->assertEquals('executed', $result['status']);
        $this->assertArrayHasKey('previewoptionids', $result);
        $this->assertContains((int)$opt->id, $result['previewoptionids']);
    }

    // -------------------------------------------------------------------------
    // list_actions
    // -------------------------------------------------------------------------

    /**
     * list_actions (all scope) returns at least the expected core tasks.
     */
    public function test_list_actions_all_scope(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
        $this->assertArrayHasKey('actions', $result);
        $this->assertNotEmpty($result['actions']);

        $tasknames = array_column($result['actions'], 'task');
        $this->assertContains('booking.create_option', $tasknames);
        $this->assertContains('booking.update_option', $tasknames);
        $this->assertContains('booking.list_actions', $tasknames);
    }

    /**
     * list_actions readonly scope returns only read-only tasks.
     */
    public function test_list_actions_readonly_scope_contains_only_readonly(): void {
        $registry = task_registry::make_default();

        $result = $this->exec_command('booking.list_actions', ['scope' => 'readonly']);

        $this->assertEquals('executed', $result['status']);
        $this->assertNotEmpty($result['actions']);

        foreach ($result['actions'] as $action) {
            $this->assertTrue(
                (bool)$action['readonly'],
                "Task '{$action['task']}' should be read-only but is not"
            );
        }
    }

    /**
     * list_actions mutating scope contains only non-readonly tasks.
     */
    public function test_list_actions_mutating_scope_contains_only_mutating(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'mutating']);

        $this->assertEquals('executed', $result['status']);
        $this->assertNotEmpty($result['actions']);

        foreach ($result['actions'] as $action) {
            $this->assertFalse(
                (bool)$action['readonly'],
                "Task '{$action['task']}' should be mutating but is flagged read-only"
            );
        }
    }

    /**
     * list_actions mutating scope includes bulk_update_options.
     */
    public function test_list_actions_mutating_includes_bulk_update(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'mutating']);

        $this->assertEquals('executed', $result['status']);
        $tasknames = array_column($result['actions'], 'task');
        $this->assertContains('booking.bulk_update_options', $tasknames);
    }

    // -------------------------------------------------------------------------
    // list_option_properties
    // -------------------------------------------------------------------------

    /**
     * list_option_properties all scope returns common fields.
     */
    public function test_list_option_properties_all_scope(): void {
        $result = $this->exec_command('booking.list_option_properties', ['scope' => 'all']);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
        $this->assertArrayHasKey('properties', $result);
        $this->assertNotEmpty($result['properties']);

        $fieldnames = array_column($result['properties'], 'name');
        $this->assertContains('text', $fieldnames);
        $this->assertContains('maxanswers', $fieldnames);
        $this->assertContains('maxoverbooking', $fieldnames);
    }

    /**
     * list_option_properties create scope only returns fields present in create_option schema.
     */
    public function test_list_option_properties_create_scope(): void {
        $result = $this->exec_command('booking.list_option_properties', ['scope' => 'create']);

        $this->assertEquals('executed', $result['status']);
        $this->assertNotEmpty($result['properties']);

        foreach ($result['properties'] as $prop) {
            $this->assertTrue(
                (bool)$prop['increate'],
                "Property '{$prop['name']}' flagged increate=false in create scope"
            );
        }
    }

    // -------------------------------------------------------------------------
    // is_read_only flag in registry
    // -------------------------------------------------------------------------

    /**
     * Search/list tasks are always flagged as read-only in the registry.
     */
    public function test_read_only_tasks_are_flagged_in_registry(): void {
        $registry = task_registry::make_default();

        $readonlytasks = ['booking.search_options', 'booking.list_actions', 'booking.list_option_properties'];
        foreach ($readonlytasks as $name) {
            $this->assertTrue(
                $registry->is_read_only_task($name),
                "Expected '$name' to be read-only"
            );
        }
    }

    /**
     * Mutation tasks are NOT flagged as read-only.
     */
    public function test_mutation_tasks_are_not_read_only(): void {
        $registry = task_registry::make_default();

        $mutatingtasks = ['booking.create_option', 'booking.update_option', 'booking.bulk_update_options'];
        foreach ($mutatingtasks as $name) {
            $this->assertFalse(
                $registry->is_read_only_task($name),
                "Expected '$name' to be mutating, not read-only"
            );
        }
    }
}
