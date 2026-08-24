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
 * A2 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie A - Kernverhalten in
 * Kombination, P2): price_based_decision_strategy_test.php's
 * test_p2_missing_price_key_treated_as_free_no_warning() already proves P2 in isolation (one
 * candidate, decide() called directly). This test goes one level further and puts a P2 candidate
 * (profile value matches no configured price category, pricecategoryfallback=2 so
 * price::get_price() returns no 'price' key at all, not even 0) into the SAME reconcile() batch
 * as an ordinary, cleanly-priced neighbour - verifying that the P2 edge case does not leak PHP
 * warnings into the whole batch, does not block or otherwise affect the neighbour's own decision,
 * and both are resolved correctly in the same round.
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
 * A2: a P2 candidate in the same batch as a normally-priced neighbour must not leak warnings or
 * affect the neighbour's decision.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\price_based_decision_strategy::decide
 */
final class a2_p2_missing_price_category_batch_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Two seats free: one candidate whose profile matches no price category at all (P2, must be
     * treated as free/AUTOBOOK), one candidate with a normal, resolvable price (must be OFFERed
     * as usual, K4). Both processed in the same reconcile() round, zero PHP warnings/notices
     * across the whole call.
     */
    public function test_p2_candidate_and_priced_neighbour_resolved_correctly_same_round(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        set_config('pricecategoryfallback', 2, 'booking'); // No default - a non-matching profile
        // value resolves to NO 'price' key at all, not even 0 (the actual P2 edge case).
        $this->create_pricecategory('somecat', 50);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $ruleid = $this->create_interval_rule(0); // ALWAYS.

        // P2 candidate: profile value matches nothing configured.
        $p2candidate = $this->waitlist_user($course, $optionid, 'nomatch', 100);
        // Ordinary neighbour: profile value resolves cleanly to a real price.
        $pricedneighbour = $this->waitlist_user($course, $optionid, 'somecat', 200);

        $this->setAdminUser();
        $sink = $this->redirectMessages();

        $warningtriggered = false;
        set_error_handler(function () use (&$warningtriggered) {
            $warningtriggered = true;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        $this->build_progression()->reconcile($optionid, 'test');

        restore_error_handler();
        $sink->close();

        $this->assertFalse(
            $warningtriggered,
            'A2: the P2 candidate must not leak any PHP warning/notice into the whole batch.'
        );

        // P2 candidate: treated exactly like price 0 - autobooked.
        $p2answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $p2candidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $p2answer->waitinglist,
            'A2: the P2 candidate (no resolvable price key at all) must be auto-booked, ' .
            'exactly like a genuine price-0 candidate.'
        );
        $p2offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $p2candidate->id]);
        $this->assertNotEmpty($p2offer);
        $this->assertEquals(6, (int) $p2offer->status, 'K3: the P2 candidate must be in status "autobooked".');

        // The ordinary priced neighbour must be completely unaffected - still offered as usual.
        $neighbouranswer = $DB->get_record(
            'booking_answers',
            ['optionid' => $optionid, 'userid' => $pricedneighbour->id]
        );
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $neighbouranswer->waitinglist,
            'A2: the normally-priced neighbour must stay on the waiting list, not be affected ' .
            'by the P2 candidate sharing the same batch.'
        );
        $neighbouroffer = $DB->get_record(
            'booking_waitlist_offers',
            ['optionid' => $optionid, 'userid' => $pricedneighbour->id]
        );
        $this->assertNotEmpty($neighbouroffer);
        $this->assertEquals(1, (int) $neighbouroffer->status, 'K4: the priced neighbour must be in status "offered".');

        // Both resolved within the very same round.
        $this->assertEquals(
            (int) $p2offer->roundid,
            (int) $neighbouroffer->roundid,
            'A2: both candidates must be resolved within the same batch/round.'
        );
        $this->assertEquals($ruleid, (int) $p2offer->ruleid);
        $this->assertEquals($ruleid, (int) $neighbouroffer->ruleid);
    }
}
