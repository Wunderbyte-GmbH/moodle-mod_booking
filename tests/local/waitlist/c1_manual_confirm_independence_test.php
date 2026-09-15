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
 * C1 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie C - Verschachtelte
 * Mischfälle): Georg's own confirmation-independence requirement (2026-08-21 chat) - "Für jeden
 * Nutzer muss die Confirmation separat gesetzt werden können. Wenn drei Nutzer ein Angebot
 * bekommen, soll es möglich sein, der zweiten Person das Angebot freizugeben und bei der ersten
 * noch zu warten." Mode 0 (confirmationonnotification=0, "no auto-grant") means nobody is
 * auto-granted on notification at all - every one of the three offered candidates needs a
 * MANUAL confirmation. This test manually confirms person 2 only (via the same low-level
 * booking_option::write_user_answer_to_db() call any manual-confirm UI path and
 * progression::grant_confirmation_if_required() both ultimately use) and verifies that only
 * person 2's real production is_available() gate opens - persons 1 and 3 are completely
 * unaffected, and neither's underlying offer row is touched (the manual confirmation lives
 * entirely on the booking_answers/UI layer, not the offer/decision engine).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\bo_availability\conditions\onwaitinglist;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * C1: manually confirming one offered candidate must not affect any of the others.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\booking_option::write_user_answer_to_db
 * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
 */
final class c1_manual_confirm_independence_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Three paid candidates all receive an offer in the same round (mode 0: none auto-granted).
     * Manually confirming candidate 2 only must open exactly candidate 2's real booking gate,
     * leaving candidates 1 and 3 - and their offer rows - completely untouched.
     */
    public function test_manually_confirming_one_candidate_does_not_affect_the_others(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        // 3 free seats so all 3 candidates get an offer in the same round - K1 batching itself
        // is already covered elsewhere, this test is purely about confirmation independence.
        $optionid = $this->create_priced_option($course, $teacher, $booking, 3, 5);

        // Mode 0: waitlist confirmation required, but never auto-granted on notification.
        $optionrecord = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $json = json_decode($optionrecord->json ?: '{}');
        $json->waitforconfirmation = 1;
        $json->confirmationonnotification = 0;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $this->create_interval_rule(0); // ALWAYS.

        $person1 = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        $person2 = $this->waitlist_user($course, $optionid, 'paidcat', 200);
        $person3 = $this->waitlist_user($course, $optionid, 'paidcat', 300);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $sink->close();

        // Sanity: all three got an offer, mode 0 means none was auto-granted.
        foreach ([$person1, $person2, $person3] as $person) {
            $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $person->id]);
            $this->assertNotEmpty($offer, "Person {$person->id} must have received an offer.");
            $this->assertEquals(1, (int) $offer->status, "Person {$person->id}'s offer must be 'offered'.");
            $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $person->id]);
            $answerjson = json_decode($answer->json ?: '{}');
            $this->assertEmpty(
                $answerjson->confirmwaitinglist ?? null,
                "Mode 0: person {$person->id} must NOT have been auto-granted."
            );
        }

        // Manually confirm person 2 only - the same low-level call any manual-confirm UI path
        // and the auto-grant mechanism both ultimately use.
        $answer2 = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $person2->id]);
        booking_option::write_user_answer_to_db(
            $answer2->bookingid,
            $answer2->frombookingid,
            $answer2->userid,
            $answer2->optionid,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            $answer2->id,
            null,
            MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION,
            "",
            MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED
        );

        // The real production gate: only person 2 can now actually book.
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new onwaitinglist();

        $this->assertFalse(
            $condition->is_available($settings, (int) $person1->id),
            'C1: person 1 must stay blocked - only person 2 was manually confirmed.'
        );
        $this->assertTrue(
            $condition->is_available($settings, (int) $person2->id),
            'C1: person 2 must now actually be able to book - manually confirmed independently.'
        );
        $this->assertFalse(
            $condition->is_available($settings, (int) $person3->id),
            'C1: person 3 must stay blocked too - confirming person 2 must not leak to anyone else.'
        );

        // The manual confirmation lives entirely on the booking_answers/UI layer - none of the
        // underlying offer/decision rows were touched by it.
        foreach ([$person1, $person2, $person3] as $person) {
            $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $person->id]);
            $this->assertEquals(
                1,
                (int) $offer->status,
                "Person {$person->id}'s offer row must still be 'offered' - a manual UI confirmation " .
                "must never touch the offer/decision engine."
            );
        }
    }
}
