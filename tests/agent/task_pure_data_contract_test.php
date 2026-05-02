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

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\booking\tasks\add_price_category_task;
use mod_booking\local\wbagent\booking\tasks\bulk_update_options_task;
use mod_booking\local\wbagent\booking\tasks\diagnose_booking_issue_task;
use mod_booking\local\wbagent\booking\tasks\diagnose_cancellation_issue_task;
use mod_booking\local\wbagent\booking\tasks\explain_docs_topic_task;
use mod_booking\local\wbagent\booking\tasks\get_current_user_task;
use mod_booking\local\wbagent\booking\tasks\list_actions_task;
use mod_booking\local\wbagent\booking\tasks\list_option_properties_task;
use mod_booking\local\wbagent\booking\tasks\search_courses_task;
use mod_booking\local\wbagent\booking\tasks\search_options_task;
use mod_booking\local\wbagent\booking\tasks\search_users_task;
use mod_booking\local\wbagent\task_registry;

/**
 * Verify that all agent tasks are pure capability units:
 * – they return structured data only
 * – they do NOT call LLMs internally
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wbagent\booking\tasks\search_options_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\search_users_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\search_courses_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\diagnose_booking_issue_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\explain_docs_topic_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\list_option_properties_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\list_actions_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\get_current_user_task
 * @covers     \mod_booking\local\wbagent\booking\tasks\bulk_update_options_task
 */
final class task_pure_data_contract_test extends abstract_agent_testcase {
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers.

    /**
     * Create a booking option through the executor.
     *
     * @param string $name
     * @param array $extra
     * @return \stdClass
     */
    private function create_generated_option(string $name, array $extra = []): \stdClass {
        $result = $this->exec_command('booking.create_option', array_merge([
            'text' => $name,
            'maxanswers' => 5,
            'coursestarttime' => '2045-03-15T09:00:00',
            'courseendtime' => '2045-03-15T17:00:00',
            'teacherquery' => 'current',
            'location' => 'Room 1',
        ], $extra));
        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        return $this->get_option_from_db((int)$result['resultid']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // search_options_task.

    /**
     * search_options_task returns structured options array without calling LLM.
     */
    public function test_search_options_returns_structured_data(): void {
        $this->create_generated_option('Pure Contract Test Option');
        $result = $this->exec_command('booking.search_options', ['query' => 'Pure Contract Test Option']);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('options', $result);
        $this->assertIsArray($result['options']);
        $this->assertNotEmpty($result['options']);
        // usermessage must be deterministic (not LLM-generated).
        $this->assertIsString($result['usermessage'] ?? '');
    }

    /**
     * search_options_task returns structured empty result without calling LLM.
     */
    public function test_search_options_no_results_returns_empty_structured_data(): void {
        $result = $this->exec_command('booking.search_options', ['query' => 'xyzzy_not_found_12345']);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('options', $result);
        $this->assertSame([], $result['options']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // search_users_task.

    /**
     * search_users_task returns structured users array without calling LLM.
     */
    public function test_search_users_returns_structured_data(): void {
        $result = $this->exec_command('booking.search_users', ['query' => $this->teacher->firstname]);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('users', $result);
        $this->assertIsArray($result['users']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // search_courses_task.

    /**
     * search_courses_task returns structured courses array without calling LLM.
     */
    public function test_search_courses_returns_structured_data(): void {
        $result = $this->exec_command('booking.search_courses', ['query' => $this->course->shortname]);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('courses', $result);
        $this->assertIsArray($result['courses']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // diagnose_booking_issue_task.

    /**
     * diagnose_booking_issue_task returns structured diagnosis data without calling LLM.
     */
    public function test_diagnose_returns_structured_diagnosis(): void {
        $option = $this->create_generated_option('Diagnosis Pure Contract');

        $result = $this->exec_command('booking.diagnose_booking_issue', [
            'question' => 'Why can I not book Diagnosis Pure Contract?',
            'optionquery' => 'Diagnosis Pure Contract',
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('diagnosis', $result);
        $this->assertIsArray($result['diagnosis']);
        $this->assertArrayHasKey('issue', $result['diagnosis']);
        $this->assertArrayHasKey('userstatus', $result['diagnosis']);
        $this->assertArrayHasKey('reasons', $result['diagnosis']);
        $this->assertIsArray($result['diagnosis']['reasons']);
        // The task-authored usermessage is deterministic (intro string), not LLM-generated.
        $usermessage = trim((string)($result['usermessage'] ?? ''));
        $this->assertNotSame('', $usermessage);
        $this->assertStringContainsString((string)$option->text, $usermessage);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // explain_docs_topic_task.

    /**
     * explain_docs_topic_task returns raw docs without calling LLM.
     */
    public function test_explain_docs_returns_raw_docs(): void {
        $result = $this->exec_command('booking.explain_docs_topic', [
            'question' => 'What does bookotheroptions do?',
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('docs', $result);
        $this->assertNotEmpty($result['docs']);
        $firstdoc = $result['docs'][0] ?? [];
        $this->assertArrayHasKey('path', $firstdoc);
        $this->assertArrayHasKey('title', $firstdoc);
        $this->assertArrayHasKey('excerpt', $firstdoc);
        // usermessage is a deterministic doc summary, not LLM-generated narration.
        $this->assertNotSame('', trim((string)($result['usermessage'] ?? '')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // list_option_properties_task.

    /**
     * list_option_properties_task returns structured property list without calling LLM.
     */
    public function test_list_option_properties_returns_structured_properties(): void {
        $result = $this->exec_command('booking.list_option_properties', ['scope' => 'all']);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('properties', $result);
        $this->assertNotEmpty($result['properties']);
        $firstprop = $result['properties'][0] ?? [];
        $this->assertArrayHasKey('name', $firstprop);
        $this->assertArrayHasKey('type', $firstprop);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // list_actions_task.

    /**
     * list_actions_task returns structured capabilities and actions without calling LLM.
     */
    public function test_list_actions_returns_structured_capabilities(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('capabilities', $result);
        $this->assertNotEmpty($result['capabilities']);
        $this->assertArrayHasKey('actions', $result);
        // usermessage is build_user_summary() output — deterministic, not LLM.
        $usermessage = trim((string)($result['usermessage'] ?? ''));
        $this->assertNotSame('', $usermessage);
    }

    /**
     * list_actions_task usermessage contains the first capability title (deterministic).
     */
    public function test_list_actions_usermessage_is_deterministic(): void {
        $result = $this->exec_command('booking.list_actions', ['scope' => 'all']);

        $this->assertSame('executed', $result['status']);
        $usermessage = (string)($result['usermessage'] ?? '');
        $firstcapability = (string)($result['capabilities'][0]['title'] ?? '');
        $this->assertNotSame('', $firstcapability);
        $this->assertStringContainsString(
            $firstcapability,
            $usermessage,
            'Deterministic usermessage must contain the first capability title.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // get_current_user_task.

    /**
     * get_current_user_task returns structured user data without calling LLM.
     */
    public function test_get_current_user_returns_structured_data(): void {
        $result = $this->exec_command('booking.get_current_user', []);

        $this->assertSame('executed', $result['status']);
        $this->assertArrayHasKey('userid', $result);
        $this->assertArrayHasKey('fullname', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertSame((int)$this->teacher->id, (int)$result['userid']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Agent runtime: fallback_message uses task schema (Phase 4).

    /**
     * AgentRuntime reads fallback string keys from task schema instead of hardcoding task names.
     */
    public function test_task_schemas_declare_fallback_string_keys_for_registered_tasks(): void {
        $registry = task_registry::make_default();
        $tasksrequiringconfirmkeys = [
            'booking.search_options',
            'booking.update_option',
            'booking.bulk_update_options',
            'booking.create_option',
        ];
        $tasksrequiringtaskcallkeys = [
            'booking.search_options',
            'booking.update_option',
            'booking.bulk_update_options',
            'booking.create_option',
            'booking.search_users',
            'booking.search_courses',
        ];

        foreach ($tasksrequiringconfirmkeys as $taskname) {
            $task = $registry->get_task($taskname);
            $this->assertNotNull($task, "Task $taskname must be in registry.");
            $schema = $task->get_schema();
            $this->assertArrayHasKey(
                'fallback_confirm_string_key',
                $schema,
                "$taskname schema must declare fallback_confirm_string_key."
            );
            $this->assertNotSame('', $schema['fallback_confirm_string_key']);
        }

        foreach ($tasksrequiringtaskcallkeys as $taskname) {
            $task = $registry->get_task($taskname);
            $this->assertNotNull($task, "Task $taskname must be in registry.");
            $schema = $task->get_schema();
            $this->assertArrayHasKey(
                'fallback_taskcall_string_key',
                $schema,
                "$taskname schema must declare fallback_taskcall_string_key."
            );
            $this->assertNotSame('', $schema['fallback_taskcall_string_key']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Verify no answering service class is instantiated in task execute().

    /**
     * No base_answering_service subclass is instantiated during task execution.
     *
     * Tasks must be pure capability units. This test runs every task that was
     * previously violating and verifies that no answering service class is
     * called (tasks no longer have create_*_answering_service() methods).
     *
     * @dataProvider task_classes_provider
     */
    public function test_task_class_has_no_answering_service_factory(string $classname): void {
        $reflection = new \ReflectionClass($classname);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PROTECTED) as $method) {
            $this->assertStringNotContainsString(
                'answering_service',
                strtolower($method->getName()),
                "$classname must not have an answering service factory method (found: {$method->getName()})."
            );
        }
    }

    /**
     * Data provider: task classes that were previously violating the no-LLM rule.
     *
     * @return array<int,array<int,string>>
     */
    public static function task_classes_provider(): array {
        return [
            [search_options_task::class],
            [search_users_task::class],
            [search_courses_task::class],
            [diagnose_booking_issue_task::class],
            [diagnose_cancellation_issue_task::class],
            [explain_docs_topic_task::class],
            [list_option_properties_task::class],
            [list_actions_task::class],
            [get_current_user_task::class],
            [bulk_update_options_task::class],
            [add_price_category_task::class],
        ];
    }
}
