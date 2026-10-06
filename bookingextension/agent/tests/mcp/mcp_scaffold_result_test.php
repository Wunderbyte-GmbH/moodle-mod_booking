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
use bookingextension_agent\local\wizard\wizard\skills\scaffold_skill;
use context_course;
use context_system;

/**
 * Scaffold results over MCP: the ZIP base64 never reaches the agent, only the preview channel.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\scaffold_skill
 */
final class mcp_scaffold_result_test extends advanced_testcase {
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
     * @return array [teacher record, course context id]
     */
    private function create_mcp_teacher(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'MCP Scaffold Course']);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');

        $systemcontext = context_system::instance();
        $roleid = create_role('MCP client', 'mcpclient' . $teacher->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('bookingextension/agent:mcpaccess', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $teacher->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        return [$teacher, (int)context_course::instance($course->id)->id];
    }

    /**
     * The MCP result of a scaffold run carries the file list and metadata but never
     * the ZIP base64 payload (nor the preview download card that embeds it).
     */
    public function test_mcp_scaffold_result_contains_no_base64(): void {
        $this->resetAfterTest();
        [$teacher, $contextid] = $this->create_mcp_teacher();
        $this->setUser($teacher);

        $wsresult = mcp_call_tool::execute(
            $contextid,
            'wizard_scaffold_skill',
            json_encode([
                'component' => 'mod/scaffolddemo',
                'description' => 'Archive an item when the teacher asks for it.',
            ]),
            ''
        );
        $resultjson = (string)$wsresult['resultjson'];
        $result = json_decode($resultjson, true);
        $this->assertIsArray($result);
        $this->assertFalse($result['isError'], 'Unexpected MCP error: ' . $resultjson);
        $this->assertSame('executed', $result['structuredContent']['status']);

        $structured = (array)$result['structuredContent'];
        $this->assertArrayNotHasKey('scaffold_zip_base64', $structured);
        $this->assertArrayNotHasKey('preview', $structured);
        $this->assertStringNotContainsString('data:application/zip', $resultjson);

        // The agent still gets everything it can talk about: files, filename, observation.
        $this->assertNotEmpty((array)$structured['scaffold_files']);
        $this->assertStringEndsWith('.zip', (string)$structured['scaffold_zip_filename']);
        $this->assertStringContainsString('[SCAFFOLD]', (string)$structured['observation_full']);
        $this->assertNotEmpty($result['content'][0]['text']);
    }

    /**
     * The user-facing preview channel still receives the download card with the ZIP data URI.
     */
    public function test_preview_channel_still_gets_download_card(): void {
        $this->resetAfterTest();
        [$teacher, $contextid] = $this->create_mcp_teacher();
        $this->setUser($teacher);

        $skill = new scaffold_skill();
        $result = $skill->execute([
            'component' => 'mod/scaffolddemo',
            'description' => 'Archive an item when the teacher asks for it.',
        ], $contextid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['scaffold_zip_base64']);

        $preview = $skill->get_result_preview($result, $contextid, (int)$teacher->id);
        $this->assertIsArray($preview);
        $this->assertSame('skill_scaffold', $preview['type']);
        $this->assertStringContainsString('data:application/zip;base64,', (string)$preview['html']);
        $this->assertStringContainsString((string)$result['scaffold_zip_filename'], (string)$preview['html']);
    }
}
