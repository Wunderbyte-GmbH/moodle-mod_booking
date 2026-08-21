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
 * B6 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie B - Regressionstests): an
 * end-to-end regression test for the confirmation-grant bug found and fixed 2026-08-21 while
 * removing the old confirm_bookinganswer_by_rule_adhoc chain (see
 * progression::grant_confirmation_if_required()). Unlike progression_test.php's
 * test_k4_offer_grants_confirmation_when_required(), which only asserts the raw
 * booking_answers.json flag, this test goes one level further and calls the real production
 * gate - bo_availability\conditions\onwaitinglist::is_available() - the exact code that decides
 * whether an offered candidate can actually book. Without the fix, an offered candidate would
 * receive a notification mail but the booking button would stay permanently blocked, because
 * onwaitinglist::is_available() requires the answer to already carry a non-empty json (the very
 * thing grant_confirmation_if_required() writes).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\bo_availability\conditions\onwaitinglist;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * B6: the real is_available() gate must open for an offered/confirmed candidate and stay closed
 * for an untouched one.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::grant_confirmation_if_required
 * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
 */
final class b6_confirmation_grant_e2e_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * B6: with maxanswers=1 and two paid candidates on the waiting list, K1 caps the batch to the
     * one free seat - only the earlier joiner gets an offer. That candidate's real is_available()
     * gate must then open (grant_confirmation_if_required() ran as part of offer()); the
     * untouched later candidate's gate must stay closed (never received an offer, confirmation
     * was never granted). Asserting both directions in one test confirms the gate is actually
     * discriminating on the confirmation grant, not just always-open or always-closed.
     */
    public function test_offered_candidate_can_actually_book_untouched_candidate_cannot(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);

        // Enable waitlist confirmation + auto-grant-on-notification (mode 1, "für alle").
        $optionrecord = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $json = json_decode($optionrecord->json ?: '{}');
        $json->waitforconfirmation = 1;
        $json->confirmationonnotification = 1;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $this->create_interval_rule(0); // ALWAYS.

        $earlier = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        $later = $this->waitlist_user($course, $optionid, 'paidcat', 200);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $sink->close();

        // Sanity check on the underlying offer state (K1/O1) before checking the actual gate.
        $this->assertNotEmpty(
            $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $earlier->id]),
            'The earlier joiner must have received the one available offer.'
        );
        $this->assertEmpty(
            $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $later->id]),
            'K1: the later joiner must be left completely untouched - no seat was left for them.'
        );

        // The actual production gate, not just the raw JSON flag.
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new onwaitinglist();

        $this->assertTrue(
            $condition->is_available($settings, (int) $earlier->id),
            'B6: the offered candidate must actually be able to book - the confirmation grant ' .
            'must have opened the real production gate, not just set a JSON flag no code reads.'
        );
        $this->assertFalse(
            $condition->is_available($settings, (int) $later->id),
            'B6 (negative control): a candidate who never received an offer must stay blocked - ' .
            'confirms the gate is actually discriminating, not just always-open.'
        );
    }
}
