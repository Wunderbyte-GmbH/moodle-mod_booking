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
 * B1 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie B - Regressionstests,
 * "mehrrundig"): progression_test.php's test_k7_permanently_declined_user_is_excluded() proves
 * the K7 lock holds for a single reconcile() call. This is the actual u:rise bug (see the plan's
 * Kontext section: "gleiche Person bekommt nach Ablehnung erneut die Zahlungsaufforderung") - the
 * lock has to survive not just the very next round, but an arbitrary number of LATER, independent
 * reconcile() rounds, with the option's free capacity and candidate pool changing in between each
 * one. A single-round test cannot rule out a lock that is (incorrectly) only honoured "once" or
 * that erodes as other, unrelated activity happens on the same option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * B1: K7 must hold across many later, independent reconcile() rounds, not just the next one.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::get_permanently_declined_userids
 */
final class b1_k7_lock_persists_across_rounds_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Four separate reconcile() rounds, spread out in time, with the free-capacity situation
     * changing between each one (open seat -> filled by someone else -> freed again -> a brand
     * new candidate joins). The permanently declined candidate D must never receive an offer or
     * autobook in ANY of them, while normal processing must keep working for everyone else.
     */
    public function test_k7_lock_survives_several_independent_later_rounds(): void {
        global $DB;

        $clock = $this->mock_clock_with_frozen(1000);

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $this->create_interval_rule(0); // ALWAYS.

        $declineduser = $this->waitlist_user($course, $optionid, 'freecat', 1000);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $declineduser->id,
            'timecreated' => 1000,
        ]);

        // Round 1 (t=2000): one free seat, D is the only candidate - must stay excluded.
        $clock->set_to(2000);
        $this->build_progression()->reconcile($optionid, 'round1');
        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'Round 1: D must still be excluded (this alone is already covered by the single-round test).'
        );

        // Round 2 (t=3000): an unrelated filler takes the only seat via a different path -
        // free capacity now 0, a structural K12 no-op for the whole option.
        $filler = $this->getDataGenerator()->create_user();
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $filler->id,
            'optionid' => $optionid,
            'timemodified' => 2500,
            'timecreated' => 2500,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'status' => 0,
        ]);
        singleton_service::destroy_booking_answers($optionid);
        $clock->set_to(3000);
        $this->build_progression()->reconcile($optionid, 'round2');
        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'Round 2: D must still be excluded, independent of the unrelated capacity change.'
        );

        // Round 3 (t=4000): the filler cancels, freeing the seat again - a second independent
        // opportunity for D to (wrongly) get processed.
        $DB->set_field(
            'booking_answers',
            'waitinglist',
            MOD_BOOKING_STATUSPARAM_DELETED,
            ['optionid' => $optionid, 'userid' => $filler->id]
        );
        singleton_service::destroy_booking_answers($optionid);
        $clock->set_to(4000);
        $this->build_progression()->reconcile($optionid, 'round3');
        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'Round 3: D must still be excluded after the seat freed up again.'
        );

        // Round 4 (t=5000): a brand new candidate E joins and IS eligible - proves the option's
        // reconcile() mechanism is still fully functional, it is specifically and only D who
        // remains locked out, in the fourth independent round since the decline.
        $newcandidate = $this->waitlist_user($course, $optionid, 'freecat', 4500);
        $clock->set_to(5000);
        $this->build_progression()->reconcile($optionid, 'round4');

        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'B1: D must still be excluded in round 4 - four independent rounds since the decline, ' .
            'not just the round immediately after it.'
        );
        $declinedanswer = $DB->get_record(
            'booking_answers',
            ['optionid' => $optionid, 'userid' => $declineduser->id]
        );
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $declinedanswer->waitinglist,
            'B1: D must never actually get booked across any of the four rounds.'
        );
        $this->assertTrue(
            $DB->record_exists('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'B1: the decline row itself must still be there - nothing may have reset it along the way ' .
            '(only expired/K4 locks are ever reset, by the separate waitlist-recycling feature, never ' .
            'declined/K7 locks).'
        );

        $newanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $newcandidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $newanswer->waitinglist,
            'B1: meanwhile, a genuinely new/eligible candidate must still be processed normally - ' .
            'confirms reconcile() itself keeps working, D\'s exclusion is not a side effect of a ' .
            'broken option.'
        );

        unset($clock);
    }
}
