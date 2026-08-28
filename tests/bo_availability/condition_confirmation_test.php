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
 * Regression test (2026-08-28, behat_errors.md): confirmation::render_page() decides which
 * "Thank you" string to show purely from the highest-id currently-blocking condition
 * (bo_info::get_condition_results()). For slot bookings, slotbooking::is_available() stays
 * permanently blocking by design (see its own docblock: "Keep the prepage condition
 * visible/stable throughout the booking flow"), even once the user has genuinely completed a
 * slot booking - it then wrongly becomes the highest blocking id and masks the real
 * already-booked state, so confirmation.mustache renders the error string ("thankyouerror")
 * instead of the success string ("thankyoubooked"). This broke 6 Behat scenarios across all
 * slotbooking feature files (booking_slotbooking*.feature).
 *
 * Fixed by cross-checking the user's actual booking_answers status directly (same helper
 * bookingoption_description already uses) as a second, authoritative signal alongside the
 * existing MOD_BOOKING_BO_COND_BOOKED_STATES check (which stays in place for its own case,
 * SLOTMOVE/self-rebooking).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\conditions\confirmation;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the confirmation bo_availability condition.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class condition_confirmation_test extends booking_advanced_testcase {
    /**
     * Creates a course + booking + one fixed-calendar slot-booking option.
     *
     * @return array [\stdClass $course, \stdClass $teacher, \stdClass $booking, int $optionid, array $record]
     */
    private function prepare_slot_option(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $bdata = new stdClass();
        $bdata->course = $course->id;
        $bdata->name = 'confirmationfixture';
        $bdata->eventtype = 'Test';
        $bdata->bookingmanager = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', (array)$bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'slotoption';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->description = 'x';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $record->optiontype = 2;
        $record->slot_enabled = 1;
        $record->slot_type = 'fixed';
        $record->slot_booking_view_mode = 'calendar';
        $record->slot_duration_minutes = 20;
        $record->slot_opening_time = '09:00';
        $record->slot_closing_time = '11:00';
        $record->slot_valid_from = 2409195600;
        $record->slot_valid_until = 2409627000;
        $record->slot_day_1 = 1;
        $record->slot_max_participants_per_slot = 2;
        $record->slot_max_slots_per_user = 2;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return [$course, $teacher, $student, (int)$option->id, $record];
    }

    /**
     * Books the given student's first available slot through the real flow
     * (slotbookingstore + booking_bookit::bookit(), same as the JS-driven UI would).
     *
     * @param int $optionid
     * @param stdClass $student
     * @param array $record slot config record from prepare_slot_option()
     * @return void
     */
    private function book_first_slot(int $optionid, stdClass $student, array $record): void {
        $slots = slot_availability::get_slots_for_range(
            $optionid,
            (int)$record['slot_valid_from'],
            (int)$record['slot_valid_until']
        );
        $this->assertNotEmpty($slots, 'Precondition: at least one slot must be generated.');
        [$slotstart, $slotend] = $slots[0];

        $store = new slotbookingstore((int)$student->id, $optionid);
        $store->set_slotbooking_data((object)['slot_selection' => "$slotstart:$slotend"]);

        booking_bookit::bookit('option', $optionid, $student->id);
        booking_bookit::bookit('option', $optionid, $student->id);
        singleton_service::destroy_booking_answers($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
    }

    /**
     * A student who genuinely completed a real slot booking must see the success confirmation.
     *
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     */
    public function test_confirmation_shows_alreadybooked_after_real_slot_booking(): void {
        [, , $student, $optionid, $record] = $this->prepare_slot_option();
        $this->book_first_slot($optionid, $student, (array)$record);

        $condition = new confirmation();
        $returnarray = $condition->render_page($optionid, (int)$student->id);
        $bodata = $returnarray['data'][0]['data'];

        $this->assertTrue(
            $bodata['alreadybooked'] ?? false,
            'After a real slot booking, confirmation::render_page() must set alreadybooked=true, ' .
            'not fall through to the notyetbooked/error branch.'
        );
        $this->assertArrayNotHasKey('notyetbooked', $bodata);
    }

    /**
     * Regression guard: a student who has NOT booked any slot yet must still correctly see the
     * "not yet booked" state, not a false-positive "already booked" success message. The fix must
     * not make every slot-booking prepage visit look like a completed booking.
     *
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     */
    public function test_confirmation_does_not_show_alreadybooked_before_any_slot_is_booked(): void {
        [, , $student, $optionid] = $this->prepare_slot_option();

        $condition = new confirmation();
        $returnarray = $condition->render_page($optionid, (int)$student->id);
        $bodata = $returnarray['data'][0]['data'];

        $this->assertFalse(
            $bodata['alreadybooked'] ?? false,
            'A student who has not booked any slot yet must not see the already-booked success state.'
        );
    }

    /**
     * Regression test for booking_slotbooking_fixed.feature:61 ("cancelling a booked slot frees
     * it up for booking again"): re-booking the same slot after cancelling it must also show the
     * success confirmation, not just a brand-new first booking. This CI failure showed a different
     * symptom (the whole modal element missing, not just the wrong text) - confirmed via direct
     * inspection that it is the same root cause, just a different downstream manifestation.
     *
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     */
    public function test_confirmation_shows_alreadybooked_after_cancel_and_rebooking(): void {
        [, , $student, $optionid, $record] = $this->prepare_slot_option();
        $this->book_first_slot($optionid, $student, (array)$record);

        $option = singleton_service::get_instance_of_booking_option(
            singleton_service::get_instance_of_booking_option_settings($optionid)->cmid,
            $optionid
        );
        $this->setUser($student);
        $option->user_delete_response((int)$student->id);
        singleton_service::destroy_booking_answers($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
        $this->setAdminUser();

        $this->book_first_slot($optionid, $student, (array)$record);

        $condition = new confirmation();
        $returnarray = $condition->render_page($optionid, (int)$student->id);
        $bodata = $returnarray['data'][0]['data'];

        $this->assertTrue(
            $bodata['alreadybooked'] ?? false,
            'After cancelling and re-booking the same slot, confirmation::render_page() must still ' .
            'set alreadybooked=true.'
        );
    }
}
