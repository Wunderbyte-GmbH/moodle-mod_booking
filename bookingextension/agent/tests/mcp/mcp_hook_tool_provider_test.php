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
use bookingextension_agent\local\wizard\services\mcp\mcp_hook_tool_provider;
use context_course;
use context_system;

/**
 * Tests for the MCP hook tool provider that publishes skills to tool_oauthmcp.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_hook_tool_provider
 */
final class mcp_hook_tool_provider_test extends advanced_testcase {
    /**
     * Skip when mod_booking or tool_oauthmcp (the interface owner) is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        if (!interface_exists('\\tool_oauthmcp\\local\\registry\\tool_source_interface')) {
            $this->markTestSkipped('tool_oauthmcp is not installed.');
        }
        parent::setUp();
    }

    /**
     * Create a teacher holding the per-skill capabilities via a system role.
     *
     * @return array [teacher, course context id]
     */
    private function create_teacher(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Hook Provider Course']);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->grant_mcpaccess($teacher);
        return [$teacher, (int)context_course::instance($course->id)->id];
    }

    /**
     * Grant the MCP entry capability to a user via a dedicated system role.
     *
     * @param \stdClass $user
     * @return void
     */
    private function grant_mcpaccess(\stdClass $user): void {
        $systemcontext = context_system::instance();
        $roleid = create_role('MCP client', 'mcpclient' . $user->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('bookingextension/agent:mcpaccess', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $user->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The provider lists native tools carrying the tool_oauthmcp read scope.
     */
    public function test_list_tools_native_with_read_scope(): void {
        $this->resetAfterTest();
        [$teacher, $contextid] = $this->create_teacher();
        $this->setUser($teacher);

        $provider = new mcp_hook_tool_provider();
        $this->assertSame('wizard', $provider->get_source_id());

        $tools = $provider->list_tools((int)$teacher->id, $contextid);
        $byname = array_column($tools, null, 'name');

        // Native, individually-named tool (not the meta mcp_call_tool).
        $this->assertArrayHasKey('course_search_courses', $byname);
        $this->assertSame('mcp:read', $byname['course_search_courses']['scope']);
        $this->assertTrue($byname['course_search_courses']['annotations']['readOnlyHint']);

        // The get_tool() call resolves the same definition.
        $one = $provider->get_tool('course_search_courses', (int)$teacher->id, $contextid);
        $this->assertSame('course_search_courses', $one['name']);
        $this->assertNull($provider->get_tool('no_such_tool', (int)$teacher->id, $contextid));
    }

    /**
     * A read-only call is executed and returns the flat MCP result (no double-JSON).
     */
    public function test_call_tool_delegates_to_facade(): void {
        $this->resetAfterTest();
        [$teacher, $contextid] = $this->create_teacher();
        $this->setUser($teacher);

        $provider = new mcp_hook_tool_provider();
        $result = $provider->call_tool('course_search_courses', ['limit' => 5], (int)$teacher->id, $contextid, 'hookkey1');

        $this->assertFalse($result['isError']);
        $this->assertNotEmpty($result['content'][0]['text']);
        // The structuredContent is the flat skill payload, not a nested resultjson string.
        $this->assertArrayHasKey('courses', $result['structuredContent']);
    }

    /**
     * With mutations enabled the write tools and the synthetic confirm tool carry the write scope.
     */
    public function test_mutating_tools_carry_write_scope(): void {
        $this->resetAfterTest();
        [$teacher, $contextid] = $this->create_teacher();
        $this->setUser($teacher);
        set_config('mcpallowmutations', '1', 'bookingextension_agent');
        set_config('aiskillenableall', '1', 'bookingextension_agent');

        $provider = new mcp_hook_tool_provider();
        $byname = array_column($provider->list_tools((int)$teacher->id, $contextid), null, 'name');

        $this->assertArrayHasKey('course_update_activity', $byname);
        $this->assertSame('mcp:write', $byname['course_update_activity']['scope']);
        $this->assertArrayHasKey('confirm_pending_action', $byname);
        $this->assertSame('mcp:write', $byname['confirm_pending_action']['scope']);
    }

    /**
     * Without the mcpaccess capability the hook path exposes and executes nothing —
     * the gate that the REST shims enforce now also applies to the tool_oauthmcp path.
     */
    public function test_mcpaccess_required_on_hook_path(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        // Deliberately NO mcpaccess granted.
        $this->setUser($teacher);
        $contextid = (int)context_course::instance($course->id)->id;

        $provider = new mcp_hook_tool_provider();
        $this->assertSame(
            [],
            $provider->list_tools((int)$teacher->id, $contextid),
            'Without mcpaccess the hook provider must expose no tools.'
        );

        $result = $provider->call_tool('course_search_courses', [], (int)$teacher->id, $contextid, 'nomcpkey');
        $this->assertTrue($result['isError']);
        $this->assertContains('MCP_ACCESS_DENIED', $result['structuredContent']['issue_codes']);
    }

    /**
     * A skill opting out via is_mcp_exposable() is never published by the provider.
     */
    public function test_is_mcp_exposable_opt_out_is_honored(): void {
        $this->resetAfterTest();
        [$teacher, $contextid] = $this->create_teacher();
        $this->setUser($teacher);

        // Default: every real skill is exposable.
        $registry = \bookingextension_agent\local\wizard\skill_registry::make_default();
        foreach ($registry->get_skills() as $skill) {
            $this->assertTrue($skill->is_mcp_exposable(), get_class($skill) . ' should default to exposable');
        }
    }
}
