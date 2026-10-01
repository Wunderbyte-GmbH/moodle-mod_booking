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
 * Tests for the booked slots shown in the showdates column of booking option tables.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_shopping_cart\shopping_cart;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the booked slots shown in bookingoptions_wbtable::col_showdates.
 *
 * At the cashier the booking option list is rendered for the selected buyer, so the booked slots
 * must be the ones of that buyer and never the ones of the cashier who is logged in.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\table\bookingoptions_wbtable::col_showdates
 */
final class bookingoptions_wbtable_bookedslots_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        unset($_GET['_buyforuser_']);
    }

    /**
     * Tear down.
     */
    public function tearDown(): void {
        unset($_GET['_buyforuser_']);
        parent::tearDown();
    }

    /**
     * Without a selected buyer, the logged in user sees their own booked slots.
     *
     * @return void
     */
    public function test_shows_own_booked_slots_without_buyer(): void {
        [$optionid, $student, $ownslot, $studentslot] = $this->seed();

        $html = $this->render_showdates($optionid);

        $this->assertStringContainsString($this->format_slot($ownslot), $html);
        $this->assertStringNotContainsString($this->format_slot($studentslot), $html);
    }

    /**
     * At the cashier (a buyer is selected), the booked slots of the buyer are shown, not the ones of
     * the cashier.
     *
     * @return void
     */
    public function test_shows_booked_slots_of_selected_buyer_at_cashier(): void {
        [$optionid, $student, $ownslot, $studentslot] = $this->seed();

        shopping_cart::buy_for_user((int) $student->id);
        $html = $this->render_showdates($optionid);

        $this->assertStringContainsString($this->format_slot($studentslot), $html);
        $this->assertStringNotContainsString($this->format_slot($ownslot), $html);
    }

    /**
     * A selected buyer without slots gets an empty column, even if the cashier has booked slots.
     *
     * @return void
     */
    public function test_shows_nothing_for_selected_buyer_without_slots(): void {
        [$optionid] = $this->seed();
        $buyer = $this->getDataGenerator()->create_user();

        shopping_cart::buy_for_user((int) $buyer->id);

        $this->assertSame('', $this->render_showdates($optionid));
    }

    /**
     * Create a slot option where the admin (cashier) and a student each hold one booked slot.
     *
     * @return array{0:int,1:\stdClass,2:array,3:array} [optionid, student, adminslot, studentslot]
     */
    private function seed(): array {
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Cashier slot option',
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 60,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 5,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 0,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }
        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        $adminslot = [strtotime('2050-01-07 09:00:00 UTC'), strtotime('2050-01-07 10:00:00 UTC')];
        $studentslot = [strtotime('2050-01-08 14:00:00 UTC'), strtotime('2050-01-08 15:00:00 UTC')];
        $this->insert_slot_answer((int) $option->id, (int) get_admin()->id, $adminslot);
        $this->insert_slot_answer((int) $option->id, (int) $student->id, $studentslot);

        return [(int) $option->id, $student, $adminslot, $studentslot];
    }

    /**
     * Insert a booked slot answer directly (bypassing checkout), like the other slotbooking tests do.
     *
     * @param int $optionid
     * @param int $userid
     * @param array $range [start, end]
     * @return void
     */
    private function insert_slot_answer(int $optionid, int $userid, array $range): void {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answer = (object) [
            'bookingid' => (int) $settings->bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $range[0],
            'enddate' => $range[1],
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => [['start' => $range[0], 'end' => $range[1]]], 'teachers' => []]);
        $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Render the showdates column for the option.
     *
     * @param int $optionid
     * @return string
     */
    private function render_showdates(int $optionid): string {
        singleton_service::destroy_instance();
        $table = new bookingoptions_wbtable('bookedslots_' . $optionid);
        return (string) $table->col_showdates((object) ['id' => $optionid]);
    }

    /**
     * Expected display of a slot (same format as col_showdates).
     *
     * @param array $range [start, end]
     * @return string
     */
    private function format_slot(array $range): string {
        return s(userdate($range[0], get_string('strftimedatetime', 'langconfig'))
            . ' - ' . userdate($range[1], get_string('strftimetime', 'langconfig')));
    }
}
