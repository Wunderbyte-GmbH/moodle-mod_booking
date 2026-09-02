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
 * A1 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie A - Kernverhalten in
 * Kombination): Georg's own original motivating example (2026-08-21 chat) - a mixed-price K1
 * batch. Person 1 is first on the waiting list and owes a price; person 2 is second and owes
 * nothing. Two seats then become free in the same event. Person 1 must still go through the
 * normal offer/confirmation process (K4); person 2 must be booked automatically, per definition,
 * without waiting for anyone (K3) - and both decisions must happen within the SAME reconcile()
 * batch/round, not two separate passes.
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
 * A1: a mixed-price batch must resolve each candidate independently within one round.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class a1_mixed_price_batch_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Two seats free at once, one priced candidate ahead of one free candidate: the priced one
     * gets an offer (K4) and stays on the waiting list, the free one is autobooked (K3) - in the
     * very same batch/round, not a delayed follow-up pass.
     */
    public function test_priced_candidate_offered_free_candidate_autobooked_same_round(): void {
        global $DB;

        $clock = $this->mock_clock_with_frozen(1000000000);

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $ruleid = $this->create_interval_rule(0, 'a1subj', 'a1tmpl', 30); // ALWAYS, 30 minutes.

        // Person 1: first on the list, owes a price.
        $paidcandidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        // Person 2: second on the list, owes nothing.
        $freecandidate = $this->waitlist_user($course, $optionid, 'freecat', 200);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $sink->close();

        // Person 1 (priced): must still be going through the offer/confirmation process.
        $paidanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $paidcandidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $paidanswer->waitinglist,
            'A1: the priced candidate must stay on the waiting list, not be auto-booked.'
        );
        $paidoffer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $paidcandidate->id]);
        $this->assertNotEmpty($paidoffer);
        $this->assertEquals(1, (int) $paidoffer->status, 'K4: the priced candidate must be in status "offered".');
        $this->assertEquals(1, (int) $paidoffer->sortorder, 'O1/O2: the priced candidate joined first.');
        $this->assertEquals(
            1000000000 + (30 * MINSECS),
            (int) $paidoffer->expiresat,
            'K4: hard-expiry deadline must be set from the rule\'s own interval.'
        );

        // Person 2 (free): must be booked automatically, per definition, without waiting.
        $freeanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $freecandidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $freeanswer->waitinglist,
            'A1: the free candidate must be auto-booked, per definition - no confirmation needed.'
        );
        $freeoffer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $freecandidate->id]);
        $this->assertNotEmpty($freeoffer);
        $this->assertEquals(6, (int) $freeoffer->status, 'K3: the free candidate must be in status "autobooked".');
        $this->assertEquals(2, (int) $freeoffer->sortorder, 'O1/O2: the free candidate joined second.');

        // Both decisions belong to the very same reconcile() round, not two separate passes.
        $this->assertEquals(
            (int) $paidoffer->roundid,
            (int) $freeoffer->roundid,
            'A1: both candidates must be resolved within the same batch/round.'
        );
        $this->assertEquals($ruleid, (int) $paidoffer->ruleid);
        $this->assertEquals($ruleid, (int) $freeoffer->ruleid);

        // Both seats are now accounted for (1 offer + 1 booking) - nothing left over, nothing
        // over-consumed (K1/K2).
        $capacity = new capacity_calculator(new db_waitlist_offer_repository());
        $this->assertEquals(
            0,
            $capacity->free_capacity($optionid),
            'K1/K2: exactly the 2 available seats must be fully accounted for by this one batch.'
        );

        unset($clock);
    }
}
