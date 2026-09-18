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
 * A5 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie A - Kernverhalten in
 * Kombination, K11): two simultaneously-applicable rules with DIFFERENT "Execute when..."
 * conditions (ALWAYS and NOTFULLYBOOKED - both true before any processing this round) must share
 * one free-capacity pool, not get one each. progression::reconcile() keeps a single $free counter
 * and a single $treated map across the whole `foreach ($ruleids as $ruleid) { foreach ($rows...` -
 * this test verifies that end-to-end: with 2 free seats, 2 candidates and 2 applicable rules,
 * exactly 2 offers must be created (not 4), and the second rule's inner loop must contribute
 * nothing because the first rule's pass already exhausted both the capacity and the candidate
 * pool.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\booking_rules\rules\rule_react_on_event;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * A5: two applicable rules with different conditions must share one capacity pool.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\rule_condition_checker::applicable_rules
 */
final class a5_shared_capacity_pool_across_rules_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * 2 free seats, 2 free-price candidates, 2 applicable rules (ALWAYS + NOTFULLYBOOKED, both
     * true before this round) - exactly 2 offers must exist afterwards, both attributed to the
     * SAME (first, lower-id) rule, and free capacity must land exactly at 0.
     */
    public function test_two_applicable_rules_never_double_process_the_same_candidates(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);

        $rulealways = $this->create_interval_rule(rule_react_on_event::ALWAYS, 'alwayssubj', 'alwaystmpl', 30);
        $rulenotfull = $this->create_interval_rule(rule_react_on_event::NOTFULLYBOOKED, 'nfbsubj', 'nfbtmpl', 30);

        // Both rules must genuinely both be applicable before we even reconcile - otherwise this
        // test would not actually exercise the shared-pool scenario it claims to.
        $checker = new rule_condition_checker();
        $applicable = $checker->applicable_rules($optionid);
        sort($applicable);
        $this->assertEquals(
            [min($rulealways, $rulenotfull), max($rulealways, $rulenotfull)],
            $applicable,
            'A5: both rules must be simultaneously applicable for this scenario to be meaningful.'
        );

        $candidate1 = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $candidate2 = $this->waitlist_user($course, $optionid, 'freecat', 200);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $sink->close();

        // Exactly one offer row per candidate - never two (one per rule).
        $this->assertEquals(
            2,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid]),
            'A5: exactly 2 offers total - 2 rules sharing 2 seats must never produce 4.'
        );
        $offer1 = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate1->id]);
        $offer2 = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate2->id]);
        $this->assertNotEmpty($offer1);
        $this->assertNotEmpty($offer2);
        $this->assertEquals(6, (int) $offer1->status, 'K3: candidate 1 must be autobooked.');
        $this->assertEquals(6, (int) $offer2->status, 'K3: candidate 2 must be autobooked.');

        // Both were processed by the SAME (first/lower-id) rule - the second rule's own pass
        // found nothing left to do, exactly because the pool was already shared and exhausted.
        $firstrule = min($rulealways, $rulenotfull);
        $this->assertEquals(
            $firstrule,
            (int) $offer1->ruleid,
            'A5: the first rule (ascending id) must have processed candidate 1.'
        );
        $this->assertEquals(
            $firstrule,
            (int) $offer2->ruleid,
            'A5: the same first rule must also have processed candidate 2 - the second rule\'s ' .
            'own loop must not have contributed anything, proving the capacity pool and the ' .
            'treated-candidates set are shared across rules, not reset per rule.'
        );

        // Both candidates actually booked.
        $answer1 = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate1->id]);
        $answer2 = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate2->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer1->waitinglist);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer2->waitinglist);

        // Capacity lands exactly at 0 - no over-consumption, no leftover.
        $capacity = new capacity_calculator(new db_waitlist_offer_repository());
        $this->assertEquals(
            0,
            $capacity->free_capacity($optionid),
            'A5: the 2 seats must be fully and exactly accounted for, once, not twice.'
        );
    }
}
