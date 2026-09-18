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
 * C3 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie C - Verschachtelte
 * Mischfälle, P1): price_based_decision_strategy_test.php's P1 test already proves that the SAME
 * strategy instance re-reads a changed price live, called directly, outside any batch. This test
 * goes one level further: the affiliation change happens to the SECOND candidate WHILE the FIRST
 * candidate is still being processed, inside one single, real reconcile() batch - proving
 * progression() never snapshots prices at round start, but genuinely re-checks each candidate's
 * price at their own turn in the loop (exactly as WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md
 * §3.1 requires).
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
require_once(__DIR__ . '/c3_mid_batch_affiliation_change_strategy.php');

/**
 * C3: a live affiliation/price change mid-batch must be picked up for the SAME round, not just
 * the next one.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\price_based_decision_strategy::decide
 */
final class c3_live_price_change_mid_batch_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Candidate 1 (free) joins first and is processed first. Candidate 2 joins second, starting
     * out on a real paid price category (would normally be OFFERed, K4) - but their profile
     * changes to the free category exactly while candidate 1 is being decided, i.e. before
     * candidate 2's own turn in the SAME batch. Candidate 2 must end up AUTOBOOKED (K3),
     * reflecting the live value, not the stale paid value from round start.
     */
    public function test_affiliation_change_mid_batch_is_picked_up_same_round(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $ruleid = $this->create_interval_rule(0); // ALWAYS.

        $candidate1 = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $candidate2 = $this->waitlist_user($course, $optionid, 'paidcat', 200);

        $decorator = new c3_mid_batch_affiliation_change_strategy(
            new price_based_decision_strategy(),
            (int) $candidate1->id,
            (int) $candidate2->id,
            'freecat'
        );

        $progression = new progression(
            new db_waitlist_offer_repository(),
            $decorator,
            new capacity_calculator(new db_waitlist_offer_repository()),
            new rule_condition_checker(),
            new moodle_messaging_gateway()
        );

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $progression->reconcile($optionid, 'test');
        $sink->close();

        // Sanity: candidate 1 unaffected, always free.
        $answer1 = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate1->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer1->waitinglist);

        // The actual C3 assertion: candidate 2 must reflect the LIVE (new, free) price, not the
        // stale paid value read at round start.
        $answer2 = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate2->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $answer2->waitinglist,
            'C3: candidate 2 must be auto-booked, reflecting the LIVE price-category change that ' .
            'happened mid-batch, not the paid value that was true at round start.'
        );
        $offer2 = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate2->id]);
        $this->assertNotEmpty($offer2);
        $this->assertEquals(
            6,
            (int) $offer2->status,
            'C3: candidate 2\'s offer must be "autobooked", not "offered" - a stale-price bug ' .
            'would have created a K4 offer instead, since paidcat was true when the round began.'
        );
        $this->assertEquals($ruleid, (int) $offer2->ruleid);
    }
}
