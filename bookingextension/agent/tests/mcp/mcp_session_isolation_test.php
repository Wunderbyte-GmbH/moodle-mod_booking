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
 * Per-session MCP threads: distinct sessions get distinct threads and isolated confirmations.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service
 * @covers     \bookingextension_agent\local\wizard\conversation_store
 */
final class mcp_session_isolation_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Course + teacher with mcpaccess + a target forum; mutations and the update skill enabled.
     *
     * @return array [teacher, course, course context id, forum cm]
     */
    private function create_mutation_fixture(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'MCP Session Course']);
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
            skill_registry::get_skill_toggle_setting_name('course.update_activity'),
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
     * Preview the forum-hide mutation for a given session and return its structuredContent.
     *
     * @param int    $contextid
     * @param string $sessionid
     * @return array
     */
    private function preview_hide(int $contextid, string $sessionid): array {
        $result = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(['activityquery' => 'MCP Target Forum', 'visible' => false]),
            '',
            $sessionid
        ));
        $this->assertFalse($result['isError'], 'Unexpected MCP error: ' . json_encode($result));
        return $result['structuredContent'];
    }

    /**
     * Distinct session ids create distinct threads; a reused id maps back to the same one;
     * no session id falls back to the single shared 'mcp' thread.
     */
    public function test_distinct_sessions_get_distinct_threads(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        $read = function (string $sessionid) use ($contextid): void {
            $this->decode_result(mcp_call_tool::execute(
                $contextid,
                'course_search_courses',
                json_encode(['query' => 'MCP']),
                '',
                $sessionid
            ));
        };

        $read('alpha');
        $read('beta');
        $read('alpha'); // Same session reused -> no new thread.
        $read('');      // No session -> shared singleton.

        $statuses = $DB->get_fieldset_select(
            'bx_agent_ai_threads',
            'status',
            'userid = :uid AND ' . $DB->sql_like('status', ':pfx'),
            ['uid' => (int)$teacher->id, 'pfx' => 'mcp%']
        );

        $sessionthreads = array_values(array_filter($statuses, static fn($s) => strpos($s, 'mcp:') === 0));
        $this->assertCount(2, array_unique($sessionthreads), 'Two distinct sessions -> two session threads.');
        $this->assertContains('mcp', $statuses, 'No-session call uses the shared singleton thread.');
        $this->assertCount(3, $statuses, 'alpha reused (not duplicated); beta + alpha + singleton = 3.');
    }

    /**
     * Two concurrent sessions each hold their own pending confirmation: one can confirm even
     * after the other has previewed a mutation on the same token.
     */
    public function test_concurrent_sessions_isolate_pending_confirmation(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, , $contextid, $forum] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        $a = $this->preview_hide($contextid, 'sessionA');
        $this->assertTrue($a['pending']);

        // Session B previews the same mutation on the same token -> must NOT clobber A.
        $b = $this->preview_hide($contextid, 'sessionB');
        $this->assertTrue($b['pending']);

        // Session A confirms with its own handle and session id -> succeeds.
        $confirmed = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            (string)$a['queueitemid'],
            (string)$a['confirmationcode'],
            'sessionA'
        ));
        $this->assertFalse($confirmed['isError'], 'Session A confirm failed: ' . json_encode($confirmed));
        $this->assertSame('executed', $confirmed['structuredContent']['status']);
        $this->assertSame(
            0,
            (int)$DB->get_field('course_modules', 'visible', ['id' => $forum->cmid]),
            'The confirmed mutation must be applied.'
        );
    }

    /**
     * Contrast: without a session id both previews share one thread. The second preview no longer
     * silently overwrites the first's pending intent (the historic clobber): it is refused with
     * MCP_PENDING_ACTION_EXISTS, and the first confirmation still succeeds.
     */
    public function test_shared_thread_second_preview_refused_without_session(): void {
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        $a = $this->preview_hide($contextid, '');

        // Same shared thread -> the collision gate refuses instead of clobbering.
        $second = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'course_update_activity',
            json_encode(['activityquery' => 'MCP Target Forum', 'visible' => false]),
            '',
            ''
        ));
        $this->assertTrue($second['isError']);
        $this->assertContains('MCP_PENDING_ACTION_EXISTS', $second['structuredContent']['issue_codes']);
        $this->assertSame((string)$a['queueitemid'], (string)$second['structuredContent']['queueitemid']);

        $confirmed = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            (string)$a['queueitemid'],
            (string)$a['confirmationcode'],
            ''
        ));
        $this->assertFalse($confirmed['isError'], 'Unexpected MCP error: ' . json_encode($confirmed));
    }

    /**
     * The cleanup helper deletes idle session threads but never the shared singleton.
     */
    public function test_idle_session_threads_are_cleaned_up(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, , $contextid] = $this->create_mutation_fixture();
        $this->setUser($teacher);

        $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', '{}', '', 'gcsession'));
        $this->decode_result(mcp_call_tool::execute($contextid, 'course_search_courses', '{}', '', ''));

        // Age the session thread past the retention window.
        $DB->set_field_select(
            'bx_agent_ai_threads',
            'timemodified',
            time() - (5 * DAYSECS),
            $DB->sql_like('status', ':pfx'),
            ['pfx' => 'mcp:%']
        );

        $deleted = (new conversation_store())->delete_idle_mcp_session_threads(2 * DAYSECS);
        $this->assertSame(1, $deleted);
        $this->assertFalse(
            $DB->record_exists_select('bx_agent_ai_threads', $DB->sql_like('status', ':pfx'), ['pfx' => 'mcp:%']),
            'Idle session threads are gone.'
        );
        $this->assertTrue(
            $DB->record_exists('bx_agent_ai_threads', ['userid' => (int)$teacher->id, 'status' => 'mcp']),
            'The shared singleton thread survives cleanup.'
        );
    }
}
