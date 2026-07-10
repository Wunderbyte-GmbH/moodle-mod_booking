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
 * Diagnostic test for the reported bug: after buying several separate slots (now creating
 * several independent booking_answers rows for the same user+option), the "Cancel purchase"
 * button disappeared entirely.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\bo_availability\conditions\cancelmyself::is_available
 * @covers     \mod_booking\bo_availability\conditions\cancelmyself::get_description
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\local\slotbooking\slot_answer;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/bo_availability/bo_info.php');

/**
 * PHPUnit diagnostic test for cancelmyself with multiple separate slot answers.
 *
 * @covers \mod_booking\bo_availability\conditions\cancelmyself
 */
final class slot_cancel_button_multi_answer_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * With three separate booked slot answers for the same user+option, cancelmyself must still
     * offer the cancel button (is_available() = false, button type CANCEL).
     *
     * @return void
     */
    public function test_cancelmyself_offers_cancel_with_multiple_slot_answers(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'));
        $this->book_slot($optionid, $userid, strtotime('2050-01-07 10:00:00 UTC'));
        $this->book_slot($optionid, $userid, strtotime('2050-01-07 11:00:00 UTC'));
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new cancelmyself();

        $isavailable = $condition->is_available($settings, $userid);
        [$descavailable, $description, $prepage, $buttontype] = $condition->get_description($settings, $userid);

        $this->assertFalse($isavailable, 'cancelmyself::is_available() should be false (cancel offered) when booked.');
        $this->assertFalse($descavailable);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_CANCEL, $buttontype);
    }

    /**
     * Same check with a single slot answer, as a baseline for comparison.
     *
     * @return void
     */
    public function test_cancelmyself_offers_cancel_with_single_slot_answer(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'));
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new cancelmyself();

        $isavailable = $condition->is_available($settings, $userid);
        [, , , $buttontype] = $condition->get_description($settings, $userid);

        $this->assertFalse($isavailable);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_CANCEL, $buttontype);
    }

    /**
     * Full pipeline check: bo_info::get_condition_results() (what the renderer actually consults)
     * must still include cancelmyself with button type CANCEL when the user holds three separate
     * slot answers - isolating whether some OTHER condition's evaluation now interferes once
     * there is more than one answer, even though cancelmyself's own logic is unaffected.
     *
     * @return void
     */
    public function test_condition_results_include_cancel_button_with_multiple_slot_answers(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'));
        $this->book_slot($optionid, $userid, strtotime('2050-01-07 10:00:00 UTC'));
        $this->book_slot($optionid, $userid, strtotime('2050-01-07 11:00:00 UTC'));
        singleton_service::destroy_instance();

        $results = \mod_booking\bo_availability\bo_info::get_condition_results($optionid, $userid);

        $this->assertArrayHasKey(MOD_BOOKING_BO_COND_CANCELMYSELF, $results);
        $cancelresult = $results[MOD_BOOKING_BO_COND_CANCELMYSELF];
        $this->assertFalse($cancelresult['isavailable']);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_CANCEL, $cancelresult['button']);
    }

    /**
     * Create a simple fixed slotbooking option and a student.
     *
     * @param array $overrides option-form field overrides
     * @return array{0:int,1:int} [optionid, userid]
     */
    private function create_slot_option_and_user(array $overrides = []): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id, 'cancancelbook' => 1]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = array_merge([
            'bookingid' => $booking->id,
            'text' => 'Cancel button test option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 30 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 15,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 0,
            'slot_change_deadline_minutes' => '',
        ], $overrides);
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id];
    }

    /**
     * Insert a booked slot answer directly (bypassing the full checkout flow), and purge the
     * option's cached answers so the direct insert is visible to the next read.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @return int booking answer id
     */
    private function book_slot(int $optionid, int $userid, int $start): int {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $end = $start + (30 * MINSECS);

        $answer = (object) [
            'bookingid' => (int)$settings->bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'timebooked' => time(),
            'startdate' => $start,
            'enddate' => $end,
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => [['start' => $start, 'end' => $end]], 'teachers' => []]);

        $baid = (int) $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);

        return $baid;
    }
}
