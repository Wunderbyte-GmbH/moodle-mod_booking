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
 * C5 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie C - Verschachtelte
 * Mischfälle, K9): rule change/deletion was previously only characterized against the OLD engine
 * (Kategorie A, the pre-refactor characterization suite). The new mechanism has no per-rule state
 * to invalidate at all (unlike the old chain's task_adhoc rows carrying a rulejson snapshot) - a
 * deleted rule simply stops being returned by rule_condition_checker::applicable_rules() on the
 * very next reconcile() call, with no special-casing anywhere. This test proves that structurally:
 * an already-open offer under a since-deleted rule is left untouched (nothing re-processes it),
 * a brand new candidate is correctly NOT processed once the option's only rule is gone (clean
 * no-op, no crash/exception), and reconcile() recovers cleanly once a fresh rule is added - the
 * system is never left "stuck" by the earlier deletion.
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
 * C5: a deleted rule must never crash reconcile(), and never keeps affecting anything once gone.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\rule_condition_checker::applicable_rules
 */
final class c5_rule_deleted_mid_flight_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Candidate 1 gets a genuine offer under rule R. R is then deleted entirely. A brand new
     * candidate 2 joins and reconcile() runs again: no crash, candidate 1's existing offer is
     * completely untouched, candidate 2 is correctly left unprocessed (no rule left to apply). A
     * fresh rule is then added and a third reconcile() proves the option is not permanently stuck.
     */
    public function test_deleted_rule_leaves_existing_offer_untouched_and_new_candidates_unprocessed(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $ruleid = $this->create_interval_rule(0); // ALWAYS.

        $candidate1 = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'round1');
        $sink->close();

        $offer1before = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate1->id]);
        $this->assertNotEmpty($offer1before, 'Candidate 1 must have received a genuine offer under the rule.');
        $this->assertEquals(1, (int) $offer1before->status, 'K4: offered.');
        $this->assertEquals($ruleid, (int) $offer1before->ruleid);

        // K9: the rule is deleted entirely.
        $DB->delete_records('booking_rules', ['id' => $ruleid]);

        $candidate2 = $this->waitlist_user($course, $optionid, 'paidcat', 200);

        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'round2'); // Must not throw.
        $sink->close();

        // Candidate 1's existing offer must be completely untouched by the deletion.
        $offer1after = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate1->id]);
        $this->assertEquals(
            (array) $offer1before,
            (array) $offer1after,
            'C5: an already-open offer under a since-deleted rule must be left completely untouched.'
        );

        // Candidate 2 must NOT have been processed - no rule left to apply, clean no-op.
        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate2->id]),
            'C5: with no active rule left, a new candidate must simply not be processed - no ' .
            'crash, no orphaned offer either.'
        );
        $answer2 = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate2->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, (int) $answer2->waitinglist);

        // A fresh rule is added afterwards - the option must not be permanently stuck.
        $newruleid = $this->create_interval_rule(0, 'newsubj', 'newtmpl', 20);
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'round3');
        $sink->close();

        $offer2 = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate2->id]);
        $this->assertNotEmpty(
            $offer2,
            'C5: once a new rule exists, candidate 2 must be picked up normally - the earlier ' .
            'deletion must not have left the option permanently stuck.'
        );
        $this->assertEquals(1, (int) $offer2->status, 'K4: offered under the new rule.');
        $this->assertEquals($newruleid, (int) $offer2->ruleid);
    }
}
