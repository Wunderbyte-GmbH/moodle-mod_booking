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
 * End-to-end tests: booking.create_option via executor (no real LLM).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * E2E tests for the booking.create_option agent task.
 *
 * The executor is called directly with a fully-formed command array — no LLM
 * is involved.  After execution the option is verified in the DB and via the
 * bookingoptions_wbtable helper.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class agent_e2e_create_option_test extends abstract_agent_testcase {
    /**
     * Create a basic booking option and verify it exists in the DB.
     */
    public function test_create_option_creates_db_record(): void {
        $result = $this->exec_command('booking.create_option', [
            'text' => 'E2E Option Alpha',
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
        $this->assertGreaterThan(0, $result['resultid']);

        $option = $this->get_option_from_db((int)$result['resultid']);
        $this->assertEquals('E2E Option Alpha', $option->text);
        $this->assertEquals($this->booking->id, (int)$option->bookingid);
    }

    /**
     * maxanswers set in command is persisted.
     */
    public function test_create_option_with_maxanswers(): void {
        $result = $this->exec_command('booking.create_option', [
            'text'        => 'Option With Seats',
            'maxanswers'  => 12,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $option = $this->get_option_from_db((int)$result['resultid']);
        $this->assertEquals(12, (int)$option->maxanswers);
    }

    /**
     * maxanswers and maxoverbooking are both persisted.
     */
    public function test_create_option_with_seats_and_waitinglist(): void {
        $result = $this->exec_command('booking.create_option', [
            'text'           => 'Option With Waitlist',
            'maxanswers'     => 8,
            'maxoverbooking' => 3,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $option = $this->get_option_from_db((int)$result['resultid']);
        $this->assertEquals(8, (int)$option->maxanswers);
        $this->assertEquals(3, (int)$option->maxoverbooking);
    }

    /**
     * Created option has the correct bookingid (foreign key).
     */
    public function test_create_option_has_correct_bookingid(): void {
        $result = $this->exec_command('booking.create_option', ['text' => 'FK Option']);
        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $option = $this->get_option_from_db((int)$result['resultid']);
        $this->assertEquals((int)$this->booking->id, (int)$option->bookingid);
    }

    /**
     * description field is persisted.
     */
    public function test_create_option_with_description(): void {
        $result = $this->exec_command('booking.create_option', [
            'text'        => 'Option With Desc',
            'description' => 'Full description text.',
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $option = $this->get_option_from_db((int)$result['resultid']);
        $this->assertStringContainsString('Full description text.', $option->description);
    }

    /**
     * teacherquery "current" resolves to the executor user.
     */
    public function test_create_option_with_teacherquery_current(): void {
        $this->setUser($this->teacher->id);

        $result = $this->exec_command('booking.create_option', [
            'text' => 'Option With Current Teacher',
            'teacherquery' => 'current',
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
        $this->assertGreaterThan(0, (int)$result['resultid']);
    }

    /**
     * coursestarttime / courseendtime are stored as unix timestamps.
     */
    public function test_create_option_with_dates(): void {
        $result = $this->exec_command('booking.create_option', [
            'text'            => 'Option With Dates',
            'coursestarttime' => '2045-03-15T09:00:00',
            'courseendtime'   => '2045-03-15T17:00:00',
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $option = $this->get_option_from_db((int)$result['resultid']);
        // The stored value should be a non-zero unix timestamp.
        $this->assertGreaterThan(0, (int)$option->coursestarttime);
        $this->assertGreaterThan((int)$option->coursestarttime, (int)$option->courseendtime);
    }

    /**
     * Creating two options with different names results in two distinct DB records.
     */
    public function test_create_two_options_independently(): void {
        $r1 = $this->exec_command('booking.create_option', ['text' => 'Alpha']);
        $r2 = $this->exec_command('booking.create_option', ['text' => 'Beta']);

        $this->assertEquals('executed', $r1['status']);
        $this->assertEquals('executed', $r2['status']);
        $this->assertNotEquals($r1['resultid'], $r2['resultid']);

        $opt1 = $this->get_option_from_db((int)$r1['resultid']);
        $opt2 = $this->get_option_from_db((int)$r2['resultid']);
        $this->assertEquals('Alpha', $opt1->text);
        $this->assertEquals('Beta', $opt2->text);
    }

    /**
     * Missing 'text' field → validation error, no option created.
     */
    public function test_create_option_without_text_returns_error(): void {
        $result = $this->exec_command('booking.create_option', []);

        $this->assertEquals('error', $result['status']);
        $this->assertNull($result['resultid']);
    }

    /**
     * Option created by the executor appears in create_table_for_one_option output.
     */
    public function test_created_option_visible_in_wbtable(): void {
        $result = $this->exec_command('booking.create_option', [
            'text'       => 'WbTable Visible Option',
            'maxanswers' => 5,
        ]);

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');

        $optionid = (int)$result['resultid'];
        $rows = $this->gen->create_table_for_one_option($optionid);

        $this->assertNotEmpty($rows, 'bookingoptions_wbtable returned no rows for the newly created option');

        $row = reset($rows);
        $this->assertEquals('WbTable Visible Option', $row->text);
        $this->assertEquals(5, (int)$row->maxanswers);
    }
}
