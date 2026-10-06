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
use context_system;

/**
 * Confirmable soft blocks stage a pending confirmation over MCP, never a terminal error (#2336, MCP-F1).
 *
 * The duplicate-title check is #2239's confirmable channel: the prepared command survives so a
 * human can decide "create anyway". Over MCP this state was flattened to MCP_PREFLIGHT_BLOCKED
 * with no way forward. Hard blocks must keep erroring.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mcp_confirmable_softblock_test extends advanced_testcase {
    public static function setUpBeforeClass(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUpBeforeClass();
    }

    /**
     * Booking activity with one existing option; mutations + skills enabled; admin caller.
     *
     * @return array [booking cm-info-ish record, existing option title]
     */
    private function create_duplicate_fixture(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $booking = $gen->create_module('booking', [
            'course' => $course->id, 'name' => 'MCP Duplikat Aktivitaet', 'eventtype' => 'W', 'bookingmanager' => 'admin',
        ]);
        global $DB;
        $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id,
            'text' => 'Doppelgaenger Option',
            'description' => '',
            'descriptionformat' => 1,
            'maxanswers' => 5,
            'type' => 0,
            'identifier' => 'dupfix1',
            'coursestarttime' => time() + DAYSECS,
            'courseendtime' => time() + DAYSECS + HOURSECS,
            'timemodified' => time(),
        ]);

        set_config('mcpallowmutations', '1', 'bookingextension_agent');
        set_config('aiskillenableall', '1', 'bookingextension_agent');
        $this->setAdminUser();

        return [$booking, 'Doppelgaenger Option'];
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
     * Duplicate title -> pending confirmation carrying the question; confirming creates anyway.
     */
    public function test_duplicate_title_stages_confirmable_pending(): void {
        global $DB;
        $this->resetAfterTest();
        [$booking, $title] = $this->create_duplicate_fixture();
        $contextid = (int)context_system::instance()->id;

        $pending = $this->decode_result(mcp_call_tool::execute(
            $contextid,
            'mod_booking_create_option',
            json_encode([
                'text' => $title,
                'activityquery' => 'MCP Duplikat Aktivitaet',
                'coursestarttime' => date('Y-m-d 18:00', time() + 2 * DAYSECS),
                'courseendtime' => date('Y-m-d 20:00', time() + 2 * DAYSECS),
            ]),
            ''
        ));

        $this->assertFalse(
            (bool)($pending['isError'] ?? true),
            'a confirmable soft block must stage, not error: ' . json_encode($pending)
        );
        $structured = (array)($pending['structuredContent'] ?? []);
        $this->assertTrue((bool)($structured['pending'] ?? false));
        $this->assertNotEmpty(
            $structured['confirm_reasons'] ?? [],
            'the client must learn WHY confirmation is needed'
        );
        $text = (string)($pending['content'][0]['text'] ?? '');
        $this->assertStringContainsString((string)$structured['confirmationcode'], $text);

        $confirmed = $this->decode_result(mcp_confirm_tool::execute(
            $contextid,
            (string)$structured['queueitemid'],
            (string)$structured['confirmationcode']
        ));
        $this->assertFalse(
            (bool)($confirmed['isError'] ?? true),
            'confirming must execute: ' . json_encode($confirmed)
        );

        $this->assertSame(
            2,
            $DB->count_records('booking_options', ['text' => $title]),
            'after the confirm the duplicate is deliberately created'
        );
    }

    /**
     * Hard blocks keep erroring — a schema violation never stages anything.
     */
    public function test_hard_block_still_errors(): void {
        $this->resetAfterTest();
        $this->create_duplicate_fixture();

        $result = $this->decode_result(mcp_call_tool::execute(
            (int)context_system::instance()->id,
            'mod_booking_create_option',
            json_encode(['coursestarttime' => 'kein titel dabei']),
            ''
        ));

        $this->assertTrue((bool)($result['isError'] ?? false), 'hard blocks must stay terminal');
    }
}
