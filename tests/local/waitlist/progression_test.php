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
 * Tests for progression (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3), the reconciler
 * facade - the single write path for waitlist progression. Built against REAL collaborators
 * (db_waitlist_offer_repository, price_based_decision_strategy, capacity_calculator,
 * rule_condition_checker, moodle_messaging_gateway), not mocks, matching this whole refactor's
 * established testing style - real DB fixtures, real message_controller sends via
 * redirectMessages(). K3 autobook goes through the real booking_option::user_submit_response(),
 * so these are integration-level tests, not pure unit tests.
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
 * §3.3 tests for progression::reconcile().
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class progression_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * K12: zero free capacity is a complete, unconditional no-op - not even reached far enough
     * to look at rules/candidates.
     */
    public function test_k12_zero_free_capacity_is_a_complete_noop(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $this->create_interval_rule(0); // ALWAYS.

        // Fill the single seat directly, so free capacity is 0.
        $filler = $this->getDataGenerator()->create_user();
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $filler->id,
            'optionid' => $optionid,
            'timemodified' => 1,
            'timecreated' => 1,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'status' => 0,
        ]);

        $waiting = $this->waitlist_user($course, $optionid, 'freecat', 100);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        $this->assertEquals(0, $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid]));
        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $waiting->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, (int) $answer->waitinglist);
    }

    /**
     * K11: free capacity exists, but no applicable rule is configured - must also be a no-op.
     */
    public function test_k11_no_applicable_rule_is_a_noop(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 5, 5);
        // Deliberately no rule created at all.

        $waiting = $this->waitlist_user($course, $optionid, 'freecat', 100);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        $this->assertEquals(0, $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid]));
        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $waiting->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, (int) $answer->waitinglist);
    }

    /**
     * K3: a free-price candidate must be autobooked (real seat flip via
     * booking_option::user_submit_response()) and notified.
     */
    public function test_k3_free_candidate_is_autobooked_and_notified(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $ruleid = $this->create_interval_rule(0); // ALWAYS.

        $candidate = $this->waitlist_user($course, $optionid, 'freecat', 100);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $messages = $sink->get_messages();
        $sink->close();

        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $answer->waitinglist,
            'K3: a free candidate must actually be booked, not just have an offer row created.'
        );

        $offerrow = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertNotEmpty($offerrow);
        $this->assertEquals(6, (int) $offerrow->status, 'autobooked must persist as status code 6.');
        $this->assertEquals($ruleid, (int) $offerrow->ruleid);

        $matching = array_filter($messages, fn($m) => (int) $m->useridto === (int) $candidate->id);
        $this->assertNotEmpty($matching, 'notify_autobooked() must have sent a message to the candidate.');
    }

    /**
     * K4: a paid candidate must receive an offer (not be booked), with a hard-expiry deadline
     * derived from the rule's own interval, and be notified with the rule's own subject/template.
     */
    public function test_k4_paid_candidate_receives_an_offer_not_a_booking(): void {
        global $DB;

        $clock = $this->mock_clock_with_frozen(1000000000);

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $ruleid = $this->create_interval_rule(0, 'k4subj', 'k4tmpl', 45); // ALWAYS, 45 minutes.

        $candidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $messages = $sink->get_messages();
        $sink->close();

        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $answer->waitinglist,
            'K4: a paid candidate must stay on the waiting list, not be auto-booked.'
        );

        $offerrow = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertNotEmpty($offerrow);
        $this->assertEquals(1, (int) $offerrow->status, 'offered must persist as status code 1.');
        $this->assertEquals($ruleid, (int) $offerrow->ruleid);
        $this->assertEquals(
            1000000000 + (45 * MINSECS),
            (int) $offerrow->expiresat,
            'K4: expiresat must be now + the rule\'s own interval.'
        );

        $matching = array_filter(
            $messages,
            fn($m) => (int) $m->useridto === (int) $candidate->id && $m->subject === 'k4subj'
        );
        $this->assertNotEmpty($matching, 'notify_offer() must send the rule-configured subject.');
        unset($clock);
    }

    /**
     * W1-W3 (Phase 3 gap fix): an offer to a candidate on an option that requires waitlist
     * confirmation, with confirmationonnotification enabled, must grant that confirmation - this
     * is what actually lets bo_availability/conditions/onwaitinglist.php allow the booking.
     * Without this, an offered candidate would receive a mail but could never actually book
     * (found and fixed 2026-08-21 while removing the old confirm_bookinganswer_by_rule_adhoc
     * chain, which used to be the only thing that ever set this flag).
     */
    public function test_k4_offer_grants_confirmation_when_required(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);

        // Enable waitlist confirmation + auto-grant-on-notification (mode 1, "for all").
        $optionrecord = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $json = json_decode($optionrecord->json ?: '{}');
        $json->waitforconfirmation = 1;
        $json->confirmationonnotification = 1;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $this->create_interval_rule(0, 'confsubj', 'conftmpl', 45); // ALWAYS.
        $candidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $answerjson = json_decode($answer->json ?: '{}');
        $this->assertNotEmpty(
            $answerjson->confirmwaitinglist ?? null,
            'W1-W3: an offer must grant confirmation when the option requires it and ' .
            'confirmationonnotification allows auto-granting - otherwise the candidate can ' .
            'never actually book despite having an open offer.'
        );
    }

    /**
     * W1: confirmationonnotification=0 ("no auto-open") must NOT grant confirmation - matches
     * the old system's behaviour exactly (nothing was ever queued/granted automatically either).
     */
    public function test_k4_offer_does_not_grant_confirmation_when_notification_mode_is_off(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);

        $optionrecord = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $json = json_decode($optionrecord->json ?: '{}');
        $json->waitforconfirmation = 1;
        $json->confirmationonnotification = 0;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $this->create_interval_rule(0, 'confsubj2', 'conftmpl2', 45); // ALWAYS.
        $candidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $answerjson = json_decode($answer->json ?: '{}');
        $this->assertEmpty(
            $answerjson->confirmwaitinglist ?? null,
            'W1: confirmationonnotification=0 must never auto-grant confirmation.'
        );
    }

    /**
     * K1: the batch size is capped at free capacity (min(N, M)) - with 1 free seat and 2 eligible
     * candidates, only the earlier joiner (O1/O2) is processed; the other is left untouched.
     */
    public function test_k1_batch_limits_to_free_capacity(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $this->create_interval_rule(0); // ALWAYS.

        $earlier = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $later = $this->waitlist_user($course, $optionid, 'freecat', 200);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        $this->assertEquals(
            1,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid]),
            'K1: exactly one candidate must be processed when only one seat is free.'
        );

        $earlieranswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $earlier->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $earlieranswer->waitinglist, 'O1/O2: earlier joiner must win.');

        $lateranswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $later->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $lateranswer->waitinglist,
            'K1: the later joiner must be left completely untouched once capacity is exhausted.'
        );
    }

    /**
     * K7: a permanently declined user must never receive a new offer/autobook, even while
     * genuinely back on the waiting list with free capacity available.
     */
    public function test_k7_permanently_declined_user_is_excluded(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 5, 5);
        $this->create_interval_rule(0); // ALWAYS.

        $declineduser = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $declineduser->id,
            'timecreated' => 1,
        ]);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'K7: a permanently declined user must never get a new offer row.'
        );
        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $declineduser->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, (int) $answer->waitinglist);
    }
}
