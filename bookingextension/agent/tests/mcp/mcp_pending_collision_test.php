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
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;
use context_system;

/**
 * Intra-session pending collision over MCP: one pending confirmation per thread.
 *
 * A second mutating call while a live pending action exists must be refused with
 * MCP_PENDING_ACTION_EXISTS instead of silently overwriting the first (whose confirm
 * would then fail); replace_pending=true opts into an explicit replacement.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service
 */
final class mcp_pending_collision_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Course + teacher with mcpaccess + two target forums; mutations and the update skill enabled.
     *
     * @return array [teacher, course context id, first forum cm, second forum cm]
     */
    private function create_collision_fixture(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'MCP Collision Course']);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $forumone = $gen->create_module('forum', ['course' => $course->id, 'name' => 'Weekly announcements']);
        $forumtwo = $gen->create_module('forum', ['course' => $course->id, 'name' => 'Homework debates']);

        $systemcontext = context_system::instance();
        $roleid = create_role('MCP client', 'mcpclient' . $teacher->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('bookingextension/agent:mcpaccess', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $teacher->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        set_config('mcpallowmutations', '1', 'bookingextension_agent');
        set_config(
            skill_registry::get_skill_toggle_setting_name('course.update_activity'),
            '1',
            'bookingextension_agent'
        );

        return [$teacher, (int)context_course::instance($course->id)->id, $forumone, $forumtwo];
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
     * Request a preview for hiding the given forum and return the decoded MCP result.
     *
     * @param int $contextid
     * @param string $forumname
     * @param array $extraargs
     * @return array
     */
    private function request_preview(int $contextid, string $forumname, array $extraargs = []): array {
        return $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(array_merge(['activityquery' => $forumname, 'visible' => false], $extraargs)),
            ''
        ));
    }

    /**
     * A second mutating call while a pending action exists is refused with a typed
     * error pointing at the pending item, and the first confirm still succeeds.
     */
    public function test_second_call_refused_and_first_confirm_still_succeeds(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, $contextid, $forumone, $forumtwo] = $this->create_collision_fixture();
        $this->setUser($teacher);

        $first = $this->request_preview($contextid, 'Weekly announcements');
        $this->assertFalse($first['isError'], 'Unexpected MCP error: ' . json_encode($first));
        $firstitemid = (string)$first['structuredContent']['queueitemid'];

        $second = $this->request_preview($contextid, 'Homework debates');
        $this->assertTrue($second['isError']);
        $structured = (array)$second['structuredContent'];
        $this->assertContains('MCP_PENDING_ACTION_EXISTS', $structured['issue_codes']);
        $this->assertSame($firstitemid, (string)$structured['queueitemid']);
        $this->assertNotEmpty((string)($structured['title'] ?? ''));
        $this->assertNotEmpty($second['content'][0]['text']);

        // Nothing was executed and the first pending action survived intact.
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $forumone->cmid]));
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $forumtwo->cmid]));

        $confirmed = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            $firstitemid,
            (string)$first['structuredContent']['confirmationcode']
        ));
        $this->assertFalse($confirmed['isError'], 'Unexpected MCP error: ' . json_encode($confirmed));
        $this->assertSame(0, (int)$DB->get_field('course_modules', 'visible', ['id' => $forumone->cmid]));
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $forumtwo->cmid]));
    }

    /**
     * replace_pending=true replaces the pending action, reports the replaced item id,
     * invalidates the old confirm handle and lets the new one execute.
     */
    public function test_replace_pending_replaces_and_reports(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, $contextid, $forumone, $forumtwo] = $this->create_collision_fixture();
        $this->setUser($teacher);

        $first = $this->request_preview($contextid, 'Weekly announcements');
        $this->assertFalse($first['isError'], 'Unexpected MCP error: ' . json_encode($first));
        $firstitemid = (string)$first['structuredContent']['queueitemid'];

        $second = $this->request_preview($contextid, 'Homework debates', ['replace_pending' => true]);
        $this->assertFalse($second['isError'], 'Unexpected MCP error: ' . json_encode($second));
        $structured = (array)$second['structuredContent'];
        $this->assertTrue($structured['pending']);
        $this->assertSame($firstitemid, (string)$structured['replaced_pending']);
        $this->assertNotSame($firstitemid, (string)$structured['queueitemid']);

        // The replaced preview's confirm handle is dead.
        $stale = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            $firstitemid,
            (string)$first['structuredContent']['confirmationcode']
        ));
        $this->assertTrue($stale['isError']);
        $this->assertContains('MCP_CONFIRMATION_MISMATCH', $stale['structuredContent']['issue_codes']);

        // The replacement executes normally.
        $confirmed = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            (string)$structured['queueitemid'],
            (string)$structured['confirmationcode']
        ));
        $this->assertFalse($confirmed['isError'], 'Unexpected MCP error: ' . json_encode($confirmed));
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $forumone->cmid]));
        $this->assertSame(0, (int)$DB->get_field('course_modules', 'visible', ['id' => $forumtwo->cmid]));
    }

    /**
     * An expired pending action does not block a fresh mutating call.
     */
    public function test_expired_pending_does_not_block(): void {
        $this->resetAfterTest();
        [$teacher, $contextid] = $this->create_collision_fixture();
        $this->setUser($teacher);

        $first = $this->request_preview($contextid, 'Weekly announcements');
        $this->assertFalse($first['isError'], 'Unexpected MCP error: ' . json_encode($first));
        $firstitemid = (string)$first['structuredContent']['queueitemid'];

        // Rewrite the stored intent with an expiry in the past (deterministic — no wall-clock
        // sleep): the store treats an expired intent as absent and clears it on read.
        $store = new conversation_store();
        $thread = $store->get_or_create_channel_thread((int)$teacher->id, $contextid, 'mcp');
        $intent = $store->get_pending_intent((int)$thread->id);
        $this->assertIsArray($intent);
        $this->assertContains($firstitemid, array_map('strval', (array)$intent['queue_item_ids']));
        $intent['expiresat'] = time() - 10;
        $store->set_thread_metadata_value((int)$thread->id, 'pending_intent', $intent);

        $second = $this->request_preview($contextid, 'Homework debates');
        $this->assertFalse($second['isError'], 'Unexpected MCP error: ' . json_encode($second));
        $structured = (array)$second['structuredContent'];
        $this->assertTrue($structured['pending']);
        $this->assertArrayNotHasKey('replaced_pending', $structured);
    }
}
