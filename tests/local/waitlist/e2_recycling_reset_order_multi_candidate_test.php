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
 * E2 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie E - Wartelisten-Recycling):
 * the original requirements clarification (2026-08-21, "Reihenfolge nach dem Reset -> wie
 * zuvor") demands that once recycling resets several K4-locked candidates at once, the very next
 * reconcile() must offer to them in their ORIGINAL join order again - not last-locked-first, not
 * scrambled. db_waitlist_offer_repository_test.php/waitlist_heartbeat_task_test.php only exercise
 * a single recycled candidate; this test drives THREE candidates, one seat at a time (A -> expiry
 * -> locks -> B is auto-offered -> expiry -> locks -> C is auto-offered -> expiry -> locks, all
 * now fully flagged), then lets capacity grow to 3 before the recycling round - so all three get
 * re-offered in the SAME round, letting the full order be asserted at once via O1/O2's sortorder,
 * not just "who gets picked first across several throttled rounds".
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;
use mod_booking\task\expire_waitlist_offer_adhoc;
use mod_booking\task\waitlist_heartbeat_task;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * E2: recycling several candidates at once must re-offer them in their original join order.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\waitlist_heartbeat_task::execute
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::get_unbehandelte_waitinglist
 */
final class e2_recycling_reset_order_multi_candidate_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Expires the currently-open offer for $optionid via the REAL expire_waitlist_offer_adhoc
     * task (mirrors E1's realism - no direct repository transition() shortcut).
     *
     * @param int $optionid
     * @return void
     */
    private function expire_current_offer(int $optionid): void {
        global $DB;
        $taskrow = $DB->get_record(
            'task_adhoc',
            ['classname' => '\mod_booking\task\expire_waitlist_offer_adhoc'],
            '*',
            MUST_EXIST
        );
        $task = new expire_waitlist_offer_adhoc();
        $task->set_custom_data(json_decode($taskrow->customdata));
        $task->execute();
        $DB->delete_records('task_adhoc', ['id' => $taskrow->id]);
    }

    /**
     * A, B, C join in that order with a single seat throughout - each is offered, expires, and
     * locks in turn (auto-advancing to the next via the real expiry task's own internal
     * reconcile()) until all three are K4-locked and the list is fully flagged. Capacity then
     * grows to 3 and the real heartbeat recycles everyone at once - the resulting offers must be
     * sorted A, B, C (sortorder 1, 2, 3), exactly their original join order.
     */
    public function test_recycling_multiple_candidates_restores_original_join_order(): void {
        global $DB;

        $clock = $this->mock_clock_with_frozen(6000000000);

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 1, ['id' => $optionid]);
        singleton_service::destroy_booking_option_singleton($optionid);
        $this->create_interval_rule(0, 'e2subj', 'e2tmpl', 5); // ALWAYS, 5 minutes.

        $usera = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        $userb = $this->waitlist_user($course, $optionid, 'paidcat', 200);
        $userc = $this->waitlist_user($course, $optionid, 'paidcat', 300);

        $this->setAdminUser();
        $repository = new db_waitlist_offer_repository();

        // A offered, expires, locks - the real expiry's own reconcile() then auto-offers B.
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'setup');
        $sink->close();
        $offer = $repository->get_open_offers($optionid)[0];
        $this->assertEquals((int) $usera->id, (int) $offer->userid, 'A must be offered first (O1/O2).');
        $clock->set_to((int) $offer->expiresat + 1);
        $this->expire_current_offer($optionid);

        // B must now be auto-offered.
        $offer = $repository->get_open_offers($optionid)[0] ?? null;
        $this->assertNotEmpty($offer, 'B must have been auto-offered once A locked and freed the seat.');
        $this->assertEquals((int) $userb->id, (int) $offer->userid);
        $clock->set_to((int) $offer->expiresat + 1);
        $this->expire_current_offer($optionid);

        // C must now be auto-offered.
        $offer = $repository->get_open_offers($optionid)[0] ?? null;
        $this->assertNotEmpty($offer, 'C must have been auto-offered once B locked and freed the seat.');
        $this->assertEquals((int) $userc->id, (int) $offer->userid);
        $clock->set_to((int) $offer->expiresat + 1);
        $this->expire_current_offer($optionid);

        // All three now locked, nothing left to offer - genuinely fully flagged.
        foreach ([$usera, $userb, $userc] as $user) {
            $this->assertTrue($repository->is_permanently_declined($optionid, (int) $user->id));
        }
        $this->assertContains($optionid, $repository->find_recyclable_options());

        // Capacity grows to 3 (e.g. the option owner raised maxanswers) - the recycling round
        // must now be able to re-offer all three at once.
        $DB->set_field('booking_options', 'maxanswers', 3, ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $clock->bump(1000);
        (new waitlist_heartbeat_task())->execute();

        $offersafter = $repository->get_open_offers($optionid); // Already sortorder ASC, id ASC.
        $this->assertCount(
            3,
            $offersafter,
            'E2: with capacity for 3, the recycling round must re-offer all three at once.'
        );
        $this->assertEquals(
            [(int) $usera->id, (int) $userb->id, (int) $userc->id],
            array_map(fn ($o) => (int) $o->userid, $offersafter),
            'E2: the recycled offers must be ordered exactly A, B, C - their ORIGINAL join order ' .
            '(O1/O2), not last-locked-first or any other order.'
        );
        $this->assertEquals(
            [1, 2, 3],
            array_map(fn ($o) => (int) $o->sortorder, $offersafter),
            'E2: sortorder must reflect the original join order 1, 2, 3.'
        );

        unset($clock);
    }
}
