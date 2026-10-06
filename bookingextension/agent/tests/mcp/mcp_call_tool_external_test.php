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

use advanced_testcase;
use bookingextension_agent\external\mcp_call_tool;
use bookingextension_agent\external\mcp_list_tools;
use bookingextension_agent\event\action_denied;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\mcp\mcp_execution_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use context_course;
use context_system;

/**
 * End-to-end tests for the MCP web service facade (list + call).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\external\mcp_list_tools
 * @covers     \bookingextension_agent\external\mcp_call_tool
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service
 */
final class mcp_call_tool_external_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Create a course + editing teacher holding the mcpaccess capability.
     *
     * @return array [teacher record, course record, course context id]
     */
    private function create_mcp_teacher(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'MCP Facade Testing Course']);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');

        // A dedicated role so ONLY this user holds mcpaccess (not every editing teacher).
        $systemcontext = context_system::instance();
        $roleid = create_role('MCP client', 'mcpclient' . $teacher->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('bookingextension/agent:mcpaccess', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $teacher->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        return [$teacher, $course, (int)context_course::instance($course->id)->id];
    }

    /**
     * Decode the resultjson payload of mcp_call_tool.
     *
     * @param array $wsresult
     * @return array
     */
    private function decode_result(array $wsresult): array {
        $this->assertArrayHasKey('resultjson', $wsresult);
        $decoded = json_decode((string)$wsresult['resultjson'], true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Listing returns tool definitions for an authorised teacher.
     */
    public function test_list_tools_returns_definitions(): void {
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mcp_teacher();
        $this->setUser($teacher);

        $result = mcp_list_tools::execute($contextid);
        $payload = json_decode((string)$result['toolsjson'], true);

        $this->assertNull($payload['error']);
        $names = array_column((array)$payload['tools'], 'name');
        $this->assertContains('course_search_courses', $names);
        $this->assertNotContains('core_search_users', $names);
    }

    /**
     * A read-only skill executes end-to-end and returns MCP-shaped output.
     */
    public function test_call_readonly_tool_executes(): void {
        $this->resetAfterTest();
        [$teacher, $course, $contextid] = $this->create_mcp_teacher();
        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        $result = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_search_courses',
            json_encode(['query' => 'MCP Facade Testing']),
            'phpunitkey0001'
        ));
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($result['isError'], 'Unexpected MCP error: ' . json_encode($result));
        $this->assertNotEmpty($result['content'][0]['text']);
        $this->assertSame('executed', $result['structuredContent']['status']);
        $courses = (array)($result['structuredContent']['courses'] ?? []);
        $this->assertNotEmpty($courses);
        $courseids = array_map(static fn($c) => (int)($c['courseid'] ?? 0), $courses);
        $this->assertContains((int)$course->id, $courseids);

        // The unified audit event fired with the canonical skill name and the MCP channel.
        $mcpevents = array_values(array_filter(
            $events,
            static fn($e) => $e instanceof \bookingextension_agent\event\skill_executed
        ));
        $this->assertCount(1, $mcpevents);
        $this->assertSame('course.search_courses', $mcpevents[0]->other['skill']);
        $this->assertSame('mcp', $mcpevents[0]->other['channel']);
        $this->assertSame('r', $mcpevents[0]->other['crud']);
    }

    /**
     * Reusing an idempotency key acknowledges instead of re-executing.
     */
    public function test_idempotent_replay_is_not_reexecuted(): void {
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mcp_teacher();
        $this->setUser($teacher);

        $args = json_encode(['query' => 'MCP Facade Testing']);
        $first = $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', $args, 'replaykey00001'));
        $this->assertFalse($first['isError']);

        $second = $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', $args, 'replaykey00001'));
        $this->assertTrue($second['isError']);
        $this->assertContains('MCP_DUPLICATE_REQUEST', $second['structuredContent']['issue_codes']);
    }

    /**
     * Unknown tools and malformed args produce structured MCP errors.
     */
    public function test_unknown_tool_and_invalid_args(): void {
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mcp_teacher();
        $this->setUser($teacher);

        $unknown = $this->decode_result(mcp_call_tool::execute($contextid, 'no_such_tool', '{}', ''));
        $this->assertTrue($unknown['isError']);
        $this->assertContains('MCP_UNKNOWN_TOOL', $unknown['structuredContent']['issue_codes']);

        $badargs = $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', '"not an object"', ''));
        $this->assertTrue($badargs['isError']);
        $this->assertContains('MCP_INVALID_INPUT', $badargs['structuredContent']['issue_codes']);
    }

    /**
     * Mutating skills are refused in phase 1 (no confirm flow yet).
     */
    public function test_mutating_tool_is_refused(): void {
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mcp_teacher();
        $this->setUser($teacher);

        // Even with every skill enabled, the mutating path is not available over MCP yet:
        // without PRO/WB-LLM the governance gate denies first; with it, the facade refuses.
        set_config('aiskillenableall', '1', 'bookingextension_agent');
        $result = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(['activityquery' => 'Announcements', 'visible' => false]),
            ''
        ));
        $this->assertTrue($result['isError']);
        $codes = (array)$result['structuredContent']['issue_codes'];
        $this->assertNotEmpty(array_intersect(['MCP_MUTATIONS_NOT_AVAILABLE', 'MCP_SKILL_DENIED'], $codes));
    }

    /**
     * A teacher cannot escape their course by passing a foreign course's contextid:
     * every gate is evaluated at the passed context, and the teacher holds nothing there.
     */
    public function test_denied_in_foreign_course_context(): void {
        $this->resetAfterTest();
        [$teacher] = $this->create_mcp_teacher();
        $foreign = $this->getDataGenerator()->create_course();
        $foreignctxid = (int)context_course::instance($foreign->id)->id;
        $this->setUser($teacher);

        $result = $this->decode_result(
            mcp_call_tool::execute($foreignctxid, 'course_search_courses', '{}', '')
        );
        $this->assertTrue($result['isError']);
        // The useaiinstructions capability is absent in the foreign context, so readiness fires first.
        $this->assertContains('MCP_NOT_READY', $result['structuredContent']['issue_codes']);
    }

    /**
     * A user failing the readiness gate gets a quiet structured error, and a user
     * without the mcpaccess capability is rejected hard.
     */
    public function test_authorization_gates(): void {
        $this->resetAfterTest();
        [, , $contextid] = $this->create_mcp_teacher();

        // Student: fails check_use_readiness (no useaiinstructions) -> structured error.
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $result = $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', '{}', ''));
        $this->assertTrue($result['isError']);
        $this->assertContains('MCP_NOT_READY', $result['structuredContent']['issue_codes']);

        // Teacher without mcpaccess: readiness passes, capability check throws.
        $gen = $this->getDataGenerator();
        $course2 = $gen->create_course();
        $teacher2 = $gen->create_user();
        $gen->enrol_user($teacher2->id, $course2->id, 'editingteacher');
        $this->setUser($teacher2);
        $this->expectException(\required_capability_exception::class);
        mcp_call_tool::execute((int)context_course::instance($course2->id)->id, 'course_search_courses', '{}', '');
    }

    /**
     * The service-level mcpaccess gate (the path the tool_oauthmcp hook takes, bypassing the REST
     * shim's require_capability) emits an action_denied audit event and returns an empty list.
     */
    public function test_service_access_denial_emits_action_denied(): void {
        $this->resetAfterTest();
        [, $course, $contextid] = $this->create_mcp_teacher();

        // A user enrolled in the course but WITHOUT the mcpaccess capability.
        $gen = $this->getDataGenerator();
        $noaccess = $gen->create_user();
        $gen->enrol_user($noaccess->id, $course->id, 'editingteacher');
        $this->setUser($noaccess);

        $service = new mcp_execution_service(
            skill_registry::make_default(),
            new conversation_store(),
            new authorization_service()
        );

        $sink = $this->redirectEvents();
        $tools = $service->list_tools($contextid, (int)$noaccess->id);
        $sink->close();

        $this->assertSame([], $tools);
        $denials = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof action_denied && ($e->other['gate'] ?? '') === 'mcp_access'
        ));
        $this->assertCount(1, $denials);
        $this->assertSame('MCP_ACCESS_DENIED', $denials[0]->other['reason']);
        $this->assertSame('mcp', $denials[0]->other['channel']);
    }
}
