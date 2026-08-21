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
 * A4 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie A - Kernverhalten in
 * Kombination, K8): a candidate who leaves the waiting list mid-round (e.g. cancels themselves,
 * or is removed by a manager) between the round's initial snapshot
 * (get_unbehandelte_waitinglist()) and their own turn in the batch loop must be skipped WITHOUT
 * consuming a $free slot - otherwise a genuinely eligible later candidate would lose their seat
 * to someone who is no longer even waiting.
 *
 * There is no way to make a real user leave the list from a separate thread mid-call in a single
 * PHPUnit process, so this test uses the repository interface exactly as intended
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.2: "makes it mockable for unit tests") - see
 * a4_leaves_mid_round_repository.php, a thin decorator around the real
 * db_waitlist_offer_repository that answers is_still_on_waitinglist() as "no" for one specific
 * user, simulating that their status changed after the round's snapshot was taken but before
 * their turn came up.
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
require_once(__DIR__ . '/a4_leaves_mid_round_repository.php');

/**
 * A4/K8: a mid-round departure must be skipped without consuming a free-capacity slot.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class a4_k8_skip_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * One free seat, two candidates: the earlier joiner leaves mid-round (K8), the later joiner
     * must still get the seat within the SAME round - the skip must not burn the free slot nor
     * require a second reconcile() call.
     */
    public function test_mid_round_departure_is_skipped_without_consuming_capacity(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $ruleid = $this->create_interval_rule(0); // ALWAYS.

        $leaving = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $next = $this->waitlist_user($course, $optionid, 'freecat', 200);

        $progression = new progression(
            new a4_leaves_mid_round_repository(new db_waitlist_offer_repository(), (int) $leaving->id),
            new price_based_decision_strategy(),
            new capacity_calculator(new db_waitlist_offer_repository()),
            new rule_condition_checker(),
            new moodle_messaging_gateway()
        );

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $progression->reconcile($optionid, 'test');
        $sink->close();

        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $leaving->id]),
            'K8: the user who left mid-round must never receive an offer/autobook.'
        );
        $leavinganswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $leaving->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $leavinganswer->waitinglist,
            'K8: the skip must not have touched the leaving user\'s own answer row.'
        );

        $nextanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $next->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $nextanswer->waitinglist,
            'A4: the free-price candidate one place further back must still get the seat, ' .
            'in the SAME round the K8 skip happened in - the skip must not consume the slot.'
        );
        $nextoffer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $next->id]);
        $this->assertNotEmpty($nextoffer);
        $this->assertEquals($ruleid, (int) $nextoffer->ruleid);
    }
}
