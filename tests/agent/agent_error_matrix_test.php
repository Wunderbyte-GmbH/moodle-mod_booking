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
 * Error and security matrix tests for the AI agent pipeline.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\task_registry;
use mod_booking\local\wbagent\booking\booking_task_provider;

/**
 * Comprehensive error and edge-case matrix for the interpreter + executor.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class agent_error_matrix_test extends abstract_agent_testcase {
    /** @var interpreter */
    private interpreter $interpreter;

    /**
     * Build interpreter from default registry.
     */
    protected function setUp(): void {
        parent::setUp();
        $registry = task_registry::make_default();
        $this->interpreter = new interpreter($registry);
    }

    // Interpreter - malformed / invalid input.

    /**
     * Completely invalid JSON → error response_type.
     */
    public function test_invalid_json_to_interpreter(): void {
        $result = $this->interpreter->interpret('not json at all', 1, 1);
        $this->assertEquals('error', $result['response_type']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Valid JSON but unknown response_type → error.
     */
    public function test_unknown_response_type_to_interpreter(): void {
        $raw = json_encode(['response_type' => 'totally_made_up', 'message' => 'hi']);
        $result = $this->interpreter->interpret($raw, 1, 1);
        $this->assertEquals('error', $result['response_type']);
    }

    /**
     * command_proposal with a task not in the registry → error.
     */
    public function test_disallowed_task_rejected_by_interpreter(): void {
        $raw = json_encode([
            'response_type' => 'command_proposal',
            'commands' => [[
                'task'    => 'system.delete_everything',
                'version' => 1,
                'input'   => [],
            ]],
        ]);

        $result = $this->interpreter->interpret($raw, 1, 1);
        $this->assertEquals('error', $result['response_type']);
    }

    /**
     * Markdown fence stripping: ```json...``` wrapping should be removed.
     */
    public function test_interpreter_strips_markdown_fences(): void {
        $inner = json_encode([
            'response_type' => 'clarification',
            'message'       => 'Could you clarify?',
        ]);
        $raw = "```json\n{$inner}\n```";

        $result = $this->interpreter->interpret($raw, 1, 1);
        $this->assertEquals('clarification', $result['response_type']);
    }

    /**
     * HTML tags in LLM output → stripped before parsing (no XSS injection).
     */
    public function test_interpreter_strips_html_tags(): void {
        $inner = json_encode([
            'response_type' => 'clarification',
            'message'       => 'Clarify date?',
        ]);
        $raw = '<div>' . $inner . '</div>';

        $result = $this->interpreter->interpret($raw, 1, 1);
        $this->assertEquals('clarification', $result['response_type']);
    }

    // Executor - validation failures bubbling up.

    /**
     * create_option without text → stale-validation error in executor.
     */
    public function test_executor_create_without_text_returns_error(): void {
        $result = $this->exec_command('booking.create_option', []);
        $this->assertEquals('error', $result['status']);
        $this->assertNull($result['resultid']);
    }

    /**
     * update_option without optionid → validation error.
     */
    public function test_executor_update_without_optionid_returns_error(): void {
        $result = $this->exec_command('booking.update_option', ['maxanswers' => 5]);
        $this->assertEquals('error', $result['status']);
    }

    /**
     * bulk_update_options with no target fields → error.
     */
    public function test_executor_bulk_without_target_returns_error(): void {
        $result = $this->exec_command('booking.bulk_update_options', ['maxanswers' => 5]);
        $this->assertEquals('error', $result['status']);
    }

    /**
     * bulk_update_options with bookusersquery → error.
     */
    public function test_executor_bulk_bookusersquery_forbidden(): void {
        $this->create_option('Forbidden');
        $result = $this->exec_command('booking.bulk_update_options', [
            'apply_to_all'   => true,
            'bookusersquery' => 'user:query',
        ]);
        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('bookusersquery', $result['detail']);
    }

    /**
     * search_users with empty query → error.
     */
    public function test_executor_search_users_empty_query_returns_error(): void {
        $result = $this->exec_command('booking.search_users', ['query' => '']);
        $this->assertEquals('error', $result['status']);
    }

    /**
     * search_courses with empty query → error.
     */
    public function test_executor_search_courses_empty_query_returns_error(): void {
        $result = $this->exec_command('booking.search_courses', ['query' => '']);
        $this->assertEquals('error', $result['status']);
    }

    /**
     * list_option_properties with invalid scope → error.
     */
    public function test_executor_list_properties_invalid_scope(): void {
        $result = $this->exec_command('booking.list_option_properties', ['scope' => 'invalid_scope']);
        $this->assertEquals('error', $result['status']);
    }

    /**
     * list_actions with invalid scope → error.
     */
    public function test_executor_list_actions_invalid_scope(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'nope']);
        $this->assertEquals('error', $result['status']);
    }

    // Interpreter - bulk_update_options schema.

    /**
     * bulk_update_options schema declares optionids, optionquery, apply_to_all.
     */
    public function test_bulk_update_schema_has_target_fields(): void {
        $provider = new booking_task_provider();
        $schema   = $provider->get_task_schema('booking.bulk_update_options');

        $this->assertArrayHasKey('properties', $schema);
        $props = $schema['properties'];
        $this->assertArrayHasKey('optionids', $props);
        $this->assertArrayHasKey('optionquery', $props);
        $this->assertArrayHasKey('apply_to_all', $props);
    }

    /**
     * bulk_update_options is in the list of known task names.
     */
    public function test_bulk_update_is_in_provider_task_names(): void {
        $provider = new booking_task_provider();
        $this->assertContains('booking.bulk_update_options', $provider->get_task_names());
    }

    /**
     * The interpreter rejects a command_proposal that has no 'commands' key.
     */
    public function test_missing_commands_key_returns_error(): void {
        $raw = json_encode(['response_type' => 'command_proposal']);
        $result = $this->interpreter->interpret($raw, 1, 1);
        $this->assertEquals('error', $result['response_type']);
    }
}
