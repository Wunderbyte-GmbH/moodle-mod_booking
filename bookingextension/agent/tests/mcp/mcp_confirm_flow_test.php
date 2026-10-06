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
use bookingextension_agent\external\mcp_confirm_tool;
use context_course;
use context_system;

/**
 * Contract tests for the MCP two-call confirm flow (mutating skills).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\external\mcp_confirm_tool
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service
 */
final class mcp_confirm_flow_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Course + teacher with mcpaccess + a target forum; mutations enabled.
     *
     * @return array [teacher, course, course context id, forum cm]
     */
    private function create_mutation_fixture(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'MCP Mutation Course']);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $forum = $gen->create_module('forum', ['course' => $course->id, 'name' => 'MCP Target Forum']);

        $systemcontext = context_system::instance();
        $roleid = create_role('MCP client', 'mcpclient' . $teacher->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('bookingextension/agent:mcpaccess', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $teacher->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        set_config('mcpallowmutations', '1', 'bookingextension_agent');
        set_config(
            \bookingextension_agent\local\wizard\skill_registry::get_skill_toggle_setting_name('course.update_activity'),
            '1',
            'bookingextension_agent'
        );

        return [$teacher, $course, (int)context_course::instance($course->id)->id, $forum];
    }

    /**
     * Decode a resultjson payload.
     *
     * @param array $wsresult
     * @return array
     */
    private function decode_result(array $wsresult): array {
        $decoded = json_decode((string)$wsresult['resultjson'], true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Full two-call cycle: preview -> confirm -> mutation verified in the DB.
     */
    public function test_full_confirm_cycle_executes_mutation(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, , $contextid, $forum] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        // Step 1: the call returns a preview + pending handle, nothing is executed.
        $pending = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(['activityquery' => 'MCP Target Forum', 'visible' => false]),
            ''
        ));
        $this->assertFalse($pending['isError'], 'Unexpected MCP error: ' . json_encode($pending));
        $structured = $pending['structuredContent'];
        $this->assertTrue($structured['pending']);
        $this->assertNotEmpty($structured['queueitemid']);
        $this->assertNotEmpty($structured['confirmationcode']);
        $this->assertGreaterThan(0, $structured['expiresin']);
        $this->assertNotEmpty($pending['content'][0]['text']);
        // The text block must be self-contained (#2351): clients on pre-2025-06 protocols never
        // see structuredContent — without the handle in the text the confirm flow dead-ends.
        $this->assertStringContainsString(
            (string)$structured['queueitemid'],
            $pending['content'][0]['text'],
            'queueitemid must travel in the text block'
        );
        $this->assertStringContainsString(
            (string)$structured['confirmationcode'],
            $pending['content'][0]['text'],
            'confirmationcode must travel in the text block'
        );
        $this->assertSame(
            1,
            (int)$DB->get_field('course_modules', 'visible', ['id' => $forum->cmid]),
            'The mutation must not run before confirmation.'
        );

        // Step 2: confirm with the code from the preview response.
        $sink = $this->redirectEvents();
        $confirmed = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            (string)$structured['queueitemid'],
            (string)$structured['confirmationcode']
        ));
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($confirmed['isError'], 'Unexpected MCP error: ' . json_encode($confirmed));
        $this->assertSame('executed', $confirmed['structuredContent']['status']);
        $this->assertSame(
            0,
            (int)$DB->get_field('course_modules', 'visible', ['id' => $forum->cmid]),
            'The confirmed mutation must be applied.'
        );

        $confirmevents = array_values(array_filter(
            $events,
            static fn($e) => $e instanceof \bookingextension_agent\event\action_confirmed
        ));
        $this->assertCount(1, $confirmevents);
        $this->assertSame('course.update_activity', $confirmevents[0]->other['skill']);
        $this->assertSame('mcp', $confirmevents[0]->other['channel']);
    }

    /**
     * A wrong confirmation code is rejected without consuming the pending intent.
     */
    public function test_wrong_confirmation_code_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, , $contextid, $forum] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        $pending = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(['activityquery' => 'MCP Target Forum', 'visible' => false]),
            ''
        ));
        $structured = $pending['structuredContent'];

        $mismatch = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            (string)$structured['queueitemid'],
            'C000000'
        ));
        $this->assertTrue($mismatch['isError']);
        $this->assertContains('MCP_CONFIRMATION_MISMATCH', $mismatch['structuredContent']['issue_codes']);
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $forum->cmid]));

        // The intent survived the failed attempt: the correct code still works.
        $confirmed = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            (string)$structured['queueitemid'],
            (string)$structured['confirmationcode']
        ));
        $this->assertFalse($confirmed['isError'], 'Unexpected MCP error: ' . json_encode($confirmed));
        $this->assertSame(0, (int)$DB->get_field('course_modules', 'visible', ['id' => $forum->cmid]));
    }

    /**
     * Without the mutations opt-in the flow is closed, and a confirm without a
     * pending intent is rejected.
     */
    public function test_mutation_gates(): void {
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        set_config('mcpallowmutations', '0', 'bookingextension_agent');
        $refused = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(['activityquery' => 'MCP Target Forum', 'visible' => false]),
            ''
        ));
        $this->assertTrue($refused['isError']);
        $this->assertContains('MCP_MUTATIONS_NOT_AVAILABLE', $refused['structuredContent']['issue_codes']);

        set_config('mcpallowmutations', '1', 'bookingextension_agent');
        $nopending = $this->decode_result(mcp_confirm_tool::execute($contextid, 'q1_1', 'C123456'));
        $this->assertTrue($nopending['isError']);
        $this->assertContains('MCP_NO_PENDING_CONFIRMATION', $nopending['structuredContent']['issue_codes']);
    }

    /**
     * The synthetic confirm tool is listed and routes through the generic call path,
     * exactly as an MCP transport (bridge) uses it.
     */
    public function test_confirm_via_generic_tool_call(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, , $contextid, $forum] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        $tools = json_decode((string)\bookingextension_agent\external\mcp_list_tools::execute($contextid)['toolsjson'], true);
        $this->assertContains('confirm_pending_action', array_column((array)$tools['tools'], 'name'));

        $pending = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(['activityquery' => 'MCP Target Forum', 'visible' => false]),
            ''
        ));
        $structured = $pending['structuredContent'];

        $confirmed = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'confirm_pending_action',
            json_encode([
                'queueitemid' => (string)$structured['queueitemid'],
                'confirmationcode' => (string)$structured['confirmationcode'],
            ]),
            ''
        ));
        $this->assertFalse($confirmed['isError'], 'Unexpected MCP error: ' . json_encode($confirmed));
        $this->assertSame(0, (int)$DB->get_field('course_modules', 'visible', ['id' => $forum->cmid]));
    }

    /**
     * The per-user rate limit closes the surface after the configured number of calls.
     */
    public function test_rate_limit(): void {
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        set_config('mcpratelimit', '1', 'bookingextension_agent');
        $first = $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', '{}', ''));
        $this->assertNotContains('MCP_RATE_LIMITED', (array)($first['structuredContent']['issue_codes'] ?? []));

        $second = $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', '{}', ''));
        $this->assertTrue($second['isError']);
        $this->assertContains('MCP_RATE_LIMITED', $second['structuredContent']['issue_codes']);
    }
}
