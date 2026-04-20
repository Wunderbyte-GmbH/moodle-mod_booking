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
 * Tests for the dual-output (usermessage + debugmessage) contract of agent tasks.
 *
 * Each task that supports the dual-output pattern must return:
 *   - usermessage  : always present, human-readable, no technical identifiers.
 *   - debugmessage : technical detail used only when debug mode is active.
 *
 * The tests verify that:
 *   1. Both fields exist and are non-empty strings.
 *   2. usermessage contains no raw task identifiers (e.g. "booking.list_actions").
 *   3. debugmessage contains technical details (task name, scope, counts).
 *   4. execution_feedback_service passes usermessage through as detail
 *      when a task provides it.
 *   5. The aiready debug_mode flag is derived from both bookingdebugmode and
 *      the core CFG->debug level.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

require_once __DIR__ . '/abstract_agent_testcase.php';

use mod_booking\local\wbagent\aiready;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\execution_feedback_service;

/**
 * Dual-output contract tests for agent tasks.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wbagent\booking\tasks\list_actions_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\search_options_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\search_users_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\search_courses_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\get_current_user_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\list_option_properties_task
 * @covers     \mod_booking\local\wbagent\execution_feedback_service
 * @covers     \mod_booking\local\wbagent\aiready
 */
final class agent_task_dual_output_test extends abstract_agent_testcase {

    // -------------------------------------------------------------------------
    // list_actions_task — usermessage
    // -------------------------------------------------------------------------

    /**
     * list_actions returns a non-empty usermessage field.
     */
    public function test_list_actions_returns_usermessage(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $this->assertArrayHasKey('usermessage', $result, 'usermessage key must be present in task result.');
        $this->assertIsString($result['usermessage']);
        $this->assertNotEmpty(trim($result['usermessage']), 'usermessage must not be empty.');
    }

    /**
     * list_actions usermessage contains no raw task identifiers.
     */
    public function test_list_actions_usermessage_has_no_technical_identifiers(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $usermessage = (string)($result['usermessage'] ?? '');
        $this->assertStringNotContainsString('booking.', $usermessage,
            'usermessage must not contain raw task namespace identifiers like "booking."');
        $this->assertStringNotContainsString('_task', $usermessage,
            'usermessage must not contain class-style identifiers like "_task"');
    }

    /**
     * list_actions usermessage contains human-readable content matching capabilities.
     */
    public function test_list_actions_usermessage_reflects_capabilities(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $this->assertArrayHasKey('capabilities', $result);
        $this->assertNotEmpty($result['capabilities']);

        $usermessage = (string)($result['usermessage'] ?? '');

        // The usermessage should reference at least one capability title.
        $firstcapability = (string)($result['capabilities'][0]['title'] ?? '');
        $this->assertNotEmpty($firstcapability);
        $this->assertStringContainsString(
            $firstcapability,
            $usermessage,
            'usermessage should contain at least the first capability title.'
        );
    }

    // -------------------------------------------------------------------------
    // list_actions_task — debugmessage
    // -------------------------------------------------------------------------

    /**
     * list_actions returns a non-empty debugmessage field.
     */
    public function test_list_actions_returns_debugmessage(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $this->assertArrayHasKey('debugmessage', $result, 'debugmessage key must be present in task result.');
        $this->assertIsString($result['debugmessage']);
        $this->assertNotEmpty(trim($result['debugmessage']), 'debugmessage must not be empty.');
    }

    /**
     * list_actions debugmessage contains technical task-level details.
     */
    public function test_list_actions_debugmessage_contains_technical_details(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $debugmessage = (string)($result['debugmessage'] ?? '');
        $this->assertStringContainsString('booking.list_actions', $debugmessage,
            'debugmessage must contain the task identifier.');
        $this->assertStringContainsString('Scope:', $debugmessage,
            'debugmessage must mention the scope that was used.');
        $this->assertStringContainsString('Returned actions:', $debugmessage,
            'debugmessage must include a count of returned actions.');
        $this->assertStringContainsString('Derived capabilities:', $debugmessage,
            'debugmessage must include a count of derived capabilities.');
    }

    /**
     * list_actions debugmessage shows action and capability counts (no per-task lines).
     */
    public function test_list_actions_debugmessage_lists_task_names(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $debugmessage = (string)($result['debugmessage'] ?? '');
        $this->assertStringContainsString('Returned actions:', $debugmessage,
            'debugmessage must show a count of returned actions.');
        $this->assertStringContainsString('Derived capabilities:', $debugmessage,
            'debugmessage must show a count of derived capabilities.');
        $this->assertStringNotContainsString('booking.create_option', $debugmessage,
            'debugmessage must not list individual task names (brief overview only).');
    }

    /**
     * list_actions debugmessage scope line matches the requested scope.
     */
    public function test_list_actions_debugmessage_scope_reflects_input(): void {
        $readonly = $this->exec_command('booking.list_actions', ['scope' => 'readonly']);
        $mutating = $this->exec_command('booking.list_actions', ['scope' => 'mutating']);

        $this->assertStringContainsString('Scope: readonly', (string)($readonly['debugmessage'] ?? ''));
        $this->assertStringContainsString('Scope: mutating', (string)($mutating['debugmessage'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Both fields are distinct
    // -------------------------------------------------------------------------

    /**
     * usermessage and debugmessage must differ from each other.
     */
    public function test_list_actions_usermessage_and_debugmessage_differ(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $usermessage  = trim((string)($result['usermessage'] ?? ''));
        $debugmessage = trim((string)($result['debugmessage'] ?? ''));

        $this->assertNotEquals(
            $usermessage,
            $debugmessage,
            'usermessage and debugmessage must carry different content.'
        );
    }

    // -------------------------------------------------------------------------
    // execution_feedback_service — usermessage pass-through
    // -------------------------------------------------------------------------

    /**
     * execution_feedback_service::sanitize_results_for_client passes usermessage through.
     */
    public function test_feedback_service_passes_usermessage_through(): void {
        $store = new conversation_store();
        $service = new execution_feedback_service($store);

        $rawresults = [
            [
                'status'       => 'executed',
                'detail'       => 'some internal detail',
                'usermessage'  => 'This is the friendly user message.',
                'debugmessage' => 'Task: foo [debug info]',
                'resultid'     => null,
            ],
        ];

        // Use reflection to call the private sanitize_results_for_client method.
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('sanitize_results_for_client');
        $method->setAccessible(true);

        $sanitized = $method->invoke($service, $rawresults);

        $this->assertCount(1, $sanitized);
        $this->assertArrayHasKey('usermessage', $sanitized[0],
            'sanitized result must contain usermessage.');
        $this->assertEquals(
            'This is the friendly user message.',
            $sanitized[0]['usermessage']
        );
    }

    /**
     * execution_feedback_service::sanitize_results_for_client passes debugmessage through.
     */
    public function test_feedback_service_passes_debugmessage_through(): void {
        $store = new conversation_store();
        $service = new execution_feedback_service($store);

        $rawresults = [
            [
                'status'       => 'executed',
                'detail'       => '',
                'usermessage'  => 'User sees this.',
                'debugmessage' => 'Task: booking.list_actions [readonly]',
                'resultid'     => null,
            ],
        ];

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('sanitize_results_for_client');
        $method->setAccessible(true);

        $sanitized = $method->invoke($service, $rawresults);

        $this->assertArrayHasKey('debugmessage', $sanitized[0],
            'sanitized result must contain debugmessage.');
        $this->assertStringContainsString('booking.list_actions', $sanitized[0]['debugmessage']);
    }

    /**
     * execution_feedback_service prefers usermessage over generated detail.
     */
    public function test_feedback_service_detail_uses_usermessage(): void {
        $store = new conversation_store();
        $service = new execution_feedback_service($store);

        $rawresults = [
            [
                'status'       => 'executed',
                'detail'       => 'old generic detail',
                'usermessage'  => 'The preferred friendly message.',
                'debugmessage' => 'debug info',
                'resultid'     => null,
            ],
        ];

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('sanitize_results_for_client');
        $method->setAccessible(true);

        $sanitized = $method->invoke($service, $rawresults);

        $this->assertEquals(
            'The preferred friendly message.',
            $sanitized[0]['detail'],
            'detail in sanitized result must be taken from usermessage when present.'
        );
    }

    // -------------------------------------------------------------------------
    // aiready debug_mode flag
    // -------------------------------------------------------------------------

    /**
     * aiready::export_for_template sets debug_mode true when bookingdebugmode is on.
     */
    public function test_aiready_debug_mode_from_booking_setting(): void {
        set_config('bookingdebugmode', 1, 'booking');

        $aiready = new aiready((int)$this->booking->cmid, (int)$this->teacher->id, (int)$this->booking->id);
        $data = $aiready->export_for_template();

        $this->assertTrue((bool)$data['debug_mode'],
            'debug_mode must be true when bookingdebugmode config is enabled.');
    }

    /**
     * aiready::export_for_template sets debug_mode true when CFG->debug is DEBUG_DEVELOPER.
     */
    public function test_aiready_debug_mode_from_cfg_debug_developer(): void {
        global $CFG;

        set_config('bookingdebugmode', 0, 'booking');
        $CFG->debug = DEBUG_DEVELOPER;

        $aiready = new aiready((int)$this->booking->cmid, (int)$this->teacher->id, (int)$this->booking->id);
        $data = $aiready->export_for_template();

        $this->assertTrue((bool)$data['debug_mode'],
            'debug_mode must be true when CFG->debug is DEBUG_DEVELOPER.');
    }

    /**
     * aiready::export_for_template sets debug_mode false when neither flag is active.
     */
    public function test_aiready_debug_mode_false_when_neither_flag(): void {
        global $CFG;

        set_config('bookingdebugmode', 0, 'booking');
        $CFG->debug = 0;

        $aiready = new aiready((int)$this->booking->cmid, (int)$this->teacher->id, (int)$this->booking->id);
        $data = $aiready->export_for_template();

        $this->assertFalse((bool)$data['debug_mode'],
            'debug_mode must be false when both bookingdebugmode and CFG->debug are inactive.');
    }

    // -------------------------------------------------------------------------
    // search_options_task — debugmessage
    // -------------------------------------------------------------------------

    /**
     * search_options returns a debugmessage containing task name and params.
     */
    public function test_search_options_debugmessage_contains_task_and_params(): void {
        $result = $this->exec_command('booking.search_options', ['query' => 'test', 'limit' => 5]);

        $this->assertArrayHasKey('debugmessage', $result, 'search_options must return debugmessage.');
        $debugmessage = (string)($result['debugmessage'] ?? '');
        $this->assertStringContainsString('booking.search_options', $debugmessage);
        $this->assertStringContainsString('query=test', $debugmessage);
        $this->assertStringContainsString('Results:', $debugmessage);
    }

    // -------------------------------------------------------------------------
    // search_users_task — debugmessage
    // -------------------------------------------------------------------------

    /**
     * search_users returns a debugmessage containing task name, params and result count.
     */
    public function test_search_users_debugmessage_contains_task_and_params(): void {
        $result = $this->exec_command('booking.search_users', ['query' => $this->teacher->firstname]);

        $this->assertArrayHasKey('debugmessage', $result, 'search_users must return debugmessage.');
        $debugmessage = (string)($result['debugmessage'] ?? '');
        $this->assertStringContainsString('booking.search_users', $debugmessage);
        $this->assertStringContainsString('Results:', $debugmessage);
    }

    // -------------------------------------------------------------------------
    // search_courses_task — debugmessage
    // -------------------------------------------------------------------------

    /**
     * search_courses returns a debugmessage containing task name and result count.
     */
    public function test_search_courses_debugmessage_contains_task_and_params(): void {
        $result = $this->exec_command('booking.search_courses', ['query' => $this->course->fullname]);

        $this->assertArrayHasKey('debugmessage', $result, 'search_courses must return debugmessage.');
        $debugmessage = (string)($result['debugmessage'] ?? '');
        $this->assertStringContainsString('booking.search_courses', $debugmessage);
        $this->assertStringContainsString('Results:', $debugmessage);
    }

    // -------------------------------------------------------------------------
    // get_current_user_task — debugmessage
    // -------------------------------------------------------------------------

    /**
     * get_current_user returns a debugmessage with resolved user info.
     */
    public function test_get_current_user_debugmessage_contains_user_info(): void {
        $result = $this->exec_command('booking.get_current_user', []);

        $this->assertArrayHasKey('debugmessage', $result, 'get_current_user must return debugmessage.');
        $debugmessage = (string)($result['debugmessage'] ?? '');
        $this->assertStringContainsString('booking.get_current_user', $debugmessage);
        $this->assertStringContainsString('Resolved user:', $debugmessage);
    }

    // -------------------------------------------------------------------------
    // list_option_properties_task — debugmessage
    // -------------------------------------------------------------------------

    /**
     * list_option_properties returns a debugmessage with property count.
     */
    public function test_list_option_properties_debugmessage_contains_count(): void {
        $result = $this->exec_command('booking.list_option_properties', ['scope' => 'all']);

        $this->assertArrayHasKey('debugmessage', $result, 'list_option_properties must return debugmessage.');
        $debugmessage = (string)($result['debugmessage'] ?? '');
        $this->assertStringContainsString('booking.list_option_properties', $debugmessage);
        $this->assertStringContainsString('Properties returned:', $debugmessage);
    }

    // -------------------------------------------------------------------------
    // build_task_debug_message — helper contract
    // -------------------------------------------------------------------------

    /**
     * build_task_debug_message omits empty/null parameter values.
     */
    public function test_debug_message_helper_omits_empty_params(): void {
        $result = $this->exec_command('booking.search_options', ['query' => 'foo', 'when' => '']);

        $debugmessage = (string)($result['debugmessage'] ?? '');
        // 'when' is empty so it must not appear in Params line.
        $this->assertStringNotContainsString('when=', $debugmessage,
            'Empty params must not appear in debugmessage.');
    }
}
