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
 * T5 (WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md, §4, T5): a dedicated regression
 * test for latejoiner_waitlist_adapter, added 2026-08-26 once the previously-open "dedicated
 * adapter or heartbeat-only" decision was resolved in favour of the dedicated adapter.
 *
 * Deliberately does NOT use waitlist_progression_fixture_trait::waitlist_user() - that helper puts
 * a candidate on the waiting list via a raw booking_answers insert, bypassing
 * booking_option::after_successful_booking_routine() entirely, so it would never actually exercise
 * the T5 hook. Every candidate here joins through the real flow
 * (booking_bookit::bookit(), twice - once to reach MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
 * once to actually create the answer), exactly as a real user would, with waitforconfirmation=1 so
 * the option unconditionally routes everyone onto the waiting list regardless of actual free
 * capacity (the exact condition that made T5 necessary in the first place - see the adapter's own
 * docblock).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\bo_availability\bo_info;
use mod_booking\booking_bookit;
use mod_booking\local\waitlist\offer_statuses\autobooked;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * T5: latejoiner_waitlist_adapter must reconcile a waitinglist candidate synchronously, the
 * moment they join, whenever real free capacity already exists at that exact moment.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\event\observer\latejoiner_waitlist_adapter::reconcile
 * @covers \mod_booking\booking_option::after_successful_booking_routine
 */
final class t5_latejoiner_waitlist_adapter_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Creates one waitforconfirmation=1 option - waitforconfirmation forces EVERY joiner onto the
     * waiting list unconditionally, independent of actual free capacity, which is exactly the
     * condition T5 exists to catch.
     *
     * @param stdClass $course
     * @param stdClass $teacher
     * @param stdClass $booking
     * @param int $maxanswers
     * @param bool $useprice
     * @return int the new option's id
     */
    private function create_waitforconfirmation_option(
        stdClass $course,
        stdClass $teacher,
        stdClass $booking,
        int $maxanswers,
        bool $useprice
    ): int {
        $this->setAdminUser();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 't5-fixture-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = $maxanswers;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Force everyone onto the waitinglist, T5's whole reason to exist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        if ($useprice) {
            $record->useprice = 1;
        }
        $record->importing = 1;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return (int) $option->id;
    }

    /**
     * Books $user onto $optionid through the real flow (the two-call bookit() confirm pattern),
     * exactly as a real user would - the only way to actually reach
     * booking_option::after_successful_booking_routine() and thus the T5 hook.
     *
     * @param int $optionid
     * @param stdClass $user
     * @return void
     */
    private function book_via_real_flow(int $optionid, stdClass $user): void {
        $this->setUser($user);
        singleton_service::destroy_user($user->id);
        booking_bookit::bookit('option', $optionid, $user->id);
        [$id] = (new bo_info(singleton_service::get_instance_of_booking_option_settings($optionid)))
            ->is_available($optionid, $user->id, false);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
            $id,
            'Precondition: bookit() must first ask for confirmation, same 2-call pattern used everywhere else.'
        );
        booking_bookit::bookit('option', $optionid, $user->id);
    }

    /**
     * Free (price 0) option, one seat, genuinely empty when the candidate joins:
     * waitforconfirmation still forces them onto the waiting list, but latejoiner_waitlist_adapter
     * must reconcile them synchronously inside the very same bookit() call - the candidate must
     * already be ALREADYBOOKED right after joining, with no separate task/heartbeat run needed.
     */
    public function test_latejoiner_with_free_capacity_and_no_price_is_autobooked_immediately(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_interval_rule(0); // ALWAYS - required for K11, reconcile() no-ops without an applicable rule.
        $optionid = $this->create_waitforconfirmation_option($course, $teacher, $booking, 1, false);
        $candidate = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($candidate->id, $course->id, 'student');

        $this->book_via_real_flow($optionid, $candidate);

        $this->setAdminUser();
        [$id] = (new bo_info(singleton_service::get_instance_of_booking_option_settings($optionid)))
            ->is_available($optionid, $candidate->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'T5: with genuinely free capacity, the candidate must already be booked right after ' .
            'joining - no heartbeat/task run happened in this test, so this can only be the ' .
            'synchronous latejoiner_waitlist_adapter reconcile.'
        );

        $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertNotEmpty($offer, 'T5 must have written the K3 audit-trail row immediately.');
        $this->assertEquals(
            (new autobooked())->get_code(),
            (int) $offer->status,
            'No price configured on this option -> K3 autobook, not K4 offer.'
        );
    }

    /**
     * Priced option, one seat, genuinely empty when the candidate joins: same forced-waitinglist
     * situation, but this time the candidate's price resolves > 0, so latejoiner_waitlist_adapter
     * must synchronously create a K4 offer instead of autobooking outright.
     */
    public function test_latejoiner_with_free_capacity_and_price_receives_an_offer_immediately(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $this->create_interval_rule(0); // ALWAYS.
        $optionid = $this->create_waitforconfirmation_option($course, $teacher, $booking, 1, true);
        $candidate = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $this->getDataGenerator()->enrol_user($candidate->id, $course->id, 'student');

        $this->book_via_real_flow($optionid, $candidate);

        $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertNotEmpty(
            $offer,
            'T5 must have written the K4 offer immediately, without waiting for any task/heartbeat run.'
        );
        $this->assertEquals(
            (new offered())->get_code(),
            (int) $offer->status,
            'Price > 0 on this option -> K4 offer, not K3 autobook.'
        );
    }

    /**
     * Regression guard: latejoiner_waitlist_adapter must NOT blindly book/offer every new
     * waitinglist joiner - only ones for whom real free capacity genuinely exists at that exact
     * moment. The first candidate takes the option's only seat (via the same real flow, so their
     * own T5 reconcile is what actually books them); the second candidate joins right after, when
     * capacity is truly exhausted, and must be left untouched on the waiting list.
     */
    public function test_latejoiner_with_no_free_capacity_is_left_on_the_waitinglist(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_interval_rule(0); // ALWAYS.
        $optionid = $this->create_waitforconfirmation_option($course, $teacher, $booking, 1, false);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($first->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($second->id, $course->id, 'student');

        // First candidate: genuinely free capacity, T5 books them - same guarantee as the first
        // test above, used here only to legitimately fill the option's one seat.
        $this->book_via_real_flow($optionid, $first);
        $this->setAdminUser();
        [$firstid] = (new bo_info(singleton_service::get_instance_of_booking_option_settings($optionid)))
            ->is_available($optionid, $first->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $firstid, 'Precondition: the seat must genuinely be taken.');

        // Second candidate: capacity is now truly exhausted - T5 must leave them on the waiting
        // list, not wrongly autobook/offer them.
        $this->book_via_real_flow($optionid, $second);
        $this->setAdminUser();
        [$secondid] = (new bo_info(singleton_service::get_instance_of_booking_option_settings($optionid)))
            ->is_available($optionid, $second->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $secondid,
            'T5: with no genuinely free capacity, the second candidate must stay on the waiting list.'
        );

        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $second->id]),
            'T5 must not have written any offer/autobook row for the second candidate - there was ' .
            'never any real free capacity for them.'
        );
    }
}
