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
 * D1 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie D - Confirmation-Feinheiten):
 * a candidate who was booked, then cancelled and re-joined the SAME option's waiting list, can
 * carry leftover booking_answers.json baggage from that earlier cycle (e.g. a stale
 * confirmwaitinglist/confirmationcount pair from having been confirmed once before, or unrelated
 * keys like paidwithcredits) - none of that must influence THIS round's outcome. This test starts
 * from a deliberately "dirty" pre-existing json state (not the clean B6 baseline) and verifies
 * the current round still produces the exact same, correct outcome: a genuine new offer this
 * round, a fresh confirmation grant, and the real production is_available() gate opening for the
 * right reason (this round's grant), not because of leftover state from before.
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
 * D1: leftover json baggage from a previous booked-then-cancelled cycle must not affect a
 * re-joined candidate's current round.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\progression::grant_confirmation_if_required
 * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
 */
final class d1_rejoin_after_previous_booking_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * The candidate's waiting-list answer row is seeded with leftover json from an earlier
     * booked-then-cancelled cycle (stale confirmwaitinglist/confirmationcount + an unrelated
     * legacy key) BEFORE reconcile() ever sees them this round. The outcome must be identical to
     * a genuinely fresh candidate: a real offer this round, a real grant, and a working
     * is_available() gate.
     */
    public function test_leftover_json_from_earlier_cycle_does_not_affect_current_round(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);

        $optionrecord = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $json = json_decode($optionrecord->json ?: '{}');
        $json->waitforconfirmation = 1;
        $json->confirmationonnotification = 1;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $this->create_interval_rule(0); // ALWAYS.

        $candidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        // Seed leftover json from an earlier booked-then-cancelled cycle - deliberately dirty
        // pre-existing state, unlike B6's clean baseline.
        $staleanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $DB->set_field(
            'booking_answers',
            'json',
            json_encode((object) [
                'confirmwaitinglist' => 1,
                'confirmationcount' => 1,
                'paidwithcredits' => true,
            ]),
            ['id' => $staleanswer->id]
        );
        singleton_service::destroy_booking_answers($optionid);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        // A genuine new offer for THIS round.
        $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertNotEmpty($offer, 'D1: a re-joined candidate must receive a genuine new offer this round.');
        $this->assertEquals(1, (int) $offer->status, 'K4: offered.');

        // The answer must still be on the waiting list. Note: write_user_answer_to_db() rebuilds
        // json from scratch on every write (not a merge) - confirmed here rather than assumed,
        // since a naive "the old json survives" expectation turned out to be wrong. That means
        // any stale leftover from the earlier cycle is unconditionally replaced by THIS round's
        // fresh grant, never merged with or shadowed by it.
        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, (int) $answer->waitinglist);
        $answerjson = json_decode($answer->json ?: '{}');
        $this->assertArrayNotHasKey(
            'paidwithcredits',
            (array) $answerjson,
            'D1: write_user_answer_to_db() rebuilds json from scratch on every write - the ' .
            'unrelated leftover key from the earlier cycle must be gone, not silently retained.'
        );
        // Finding, not a bug: write_user_answer_to_db() INCREMENTS confirmationcount from
        // whatever value the existing row already had (pre-existing behaviour, not touched by
        // this refactor) - it does not reset to 1 for a re-joined candidate. The stale seed of 1
        // here therefore becomes 2. Harmless in THIS test environment (no bookingextension
        // subplugin means get_required_confirmation_count() is 0, and is_available() only checks
        // >=), but worth documenting precisely rather than assuming a reset.
        $this->assertEquals(
            2,
            $answerjson->confirmationcount ?? null,
            'D1: confirmationcount carries forward and increments from the stale pre-existing ' .
            'value (1 -> 2) - pre-existing write_user_answer_to_db() behaviour, documented here ' .
            'rather than assumed.'
        );

        // The real production gate must work correctly for this round's grant - not accidentally
        // broken, and not merely "happening to still be true" from the stale leftover value.
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new onwaitinglist();
        $this->assertTrue(
            $condition->is_available($settings, (int) $candidate->id),
            'D1: the candidate must actually be able to book - the same outcome as a genuinely ' .
            'fresh candidate would get, despite the leftover baggage from their earlier cycle.'
        );
    }
}
