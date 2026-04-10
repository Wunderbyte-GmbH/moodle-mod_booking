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
 * End-to-end tests: booking.update_option via executor (no real LLM).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

require_once __DIR__ . '/abstract_agent_testcase.php';

/**
 * E2E tests for the booking.update_option agent task.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_e2e_update_option_test extends abstract_agent_testcase {

    /**
     * Update maxanswers on an existing option via explicit optionid.
     */
    public function test_update_maxanswers_by_optionid(): void {
        $option = $this->create_option('Update Target', ['maxanswers' => 5]);

        $result = $this->exec_command('booking.update_option', [
            'optionid'   => $option->id,
            'maxanswers' => 20,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertEquals(20, (int)$updated->maxanswers);
    }

    /**
     * Update maxoverbooking (waiting-list size) by optionid.
     */
    public function test_update_maxoverbooking_by_optionid(): void {
        $option = $this->create_option('Waitlist Target', ['maxanswers' => 10, 'maxoverbooking' => 0]);

        $result = $this->exec_command('booking.update_option', [
            'optionid'       => $option->id,
            'maxoverbooking' => 5,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertEquals(5, (int)$updated->maxoverbooking);
    }

    /**
     * Update both maxanswers and maxoverbooking in a single command.
     */
    public function test_update_seats_and_waitinglist_together(): void {
        $option = $this->create_option('Combined Update', ['maxanswers' => 1, 'maxoverbooking' => 0]);

        $result = $this->exec_command('booking.update_option', [
            'optionid'       => $option->id,
            'maxanswers'     => 8,
            'maxoverbooking' => 3,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertEquals(8, (int)$updated->maxanswers);
        $this->assertEquals(3, (int)$updated->maxoverbooking);
    }

    /**
     * Update the option's name (text field).
     */
    public function test_update_option_name(): void {
        $option = $this->create_option('Old Name');

        $result = $this->exec_command('booking.update_option', [
            'optionid' => $option->id,
            'text'     => 'New Name',
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertEquals('New Name', $updated->text);
    }

    /**
     * Update the description field.
     */
    public function test_update_description(): void {
        $option = $this->create_option('Desc Option');

        $result = $this->exec_command('booking.update_option', [
            'optionid'    => $option->id,
            'description' => 'Updated description text.',
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertStringContainsString('Updated description text.', (string)$updated->description);
    }

    /**
     * update_option without optionid or optionquery → validation error (ambiguity).
     */
    public function test_update_without_optionid_returns_error(): void {
        $result = $this->exec_command('booking.update_option', [
            'maxanswers' => 10,
        ]);

        $this->assertEquals('error', $result['status']);
        $this->assertNull($result['resultid']);
    }

    /**
     * update_option with an optionid that does not belong to the booking instance → error.
     */
    public function test_update_with_foreign_optionid_returns_error(): void {
        $result = $this->exec_command('booking.update_option', [
            'optionid'   => 999999,
            'maxanswers' => 10,
        ]);

        $this->assertEquals('error', $result['status']);
    }

    /**
     * Updated option reflects the new maxanswers in wbtable output.
     */
    public function test_updated_option_visible_in_wbtable(): void {
        $option = $this->create_option('WbTable Update Option', ['maxanswers' => 1]);

        $result = $this->exec_command('booking.update_option', [
            'optionid'   => $option->id,
            'maxanswers' => 15,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $rows = $this->gen->create_table_for_one_option((int)$option->id);
        $this->assertNotEmpty($rows);

        $row = reset($rows);
        $this->assertEquals(15, (int)$row->maxanswers);
    }

    /**
     * Updating with optiondates appends by default and keeps existing sessions.
     */
    public function test_update_option_optiondates_append_keeps_existing_sessions(): void {
        global $DB;

        $option = $this->create_option('Date Append Option', [
            'optiondates' => [
                [
                    'coursestarttime' => '2045-05-01T09:00:00',
                    'courseendtime' => '2045-05-01T12:00:00',
                ],
                [
                    'coursestarttime' => '2045-05-02T09:00:00',
                    'courseendtime' => '2045-05-02T12:00:00',
                ],
            ],
        ]);

        $beforecount = $DB->count_records('booking_optiondates', ['optionid' => (int)$option->id]);
        $this->assertEquals(2, $beforecount);

        $result = $this->exec_command('booking.update_option', [
            'optionid' => (int)$option->id,
            'optiondates' => [
                [
                    'coursestarttime' => '2045-05-03T09:00:00',
                    'courseendtime' => '2045-05-03T12:00:00',
                ],
            ],
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $aftercount = $DB->count_records('booking_optiondates', ['optionid' => (int)$option->id]);
        $this->assertEquals(3, $aftercount, 'A new session should be added without deleting existing sessions.');
    }
}
