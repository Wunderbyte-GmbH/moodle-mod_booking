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
 * B2 (K1/K2, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): when M seats become free at once,
 * min(N, M) waiting-list candidates must be treated IMMEDIATELY in a single reconcile() call, in
 * FIFO order, with no overtaking - not one person per interval tick like today. This is the
 * second u:rise-reported scenario (Intervall-Wartezeit trotz mehrerer freier Plätze) and the
 * direct target-behaviour test for WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3's central
 * loop (`if ($free <= 0) { break; } ... $free--;`).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use mod_booking\bo_availability\bo_info;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Target-behaviour test for K1's batch promotion (min(N, M)) and K2's capacity accounting (B2).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.2
 * and §3.3 (still "Entwurf, noch nicht final abgenommen" at the time this test was written) -
 * same caveats as B1/C1-C5: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\capacity_calculator::free_capacity
 * @runInSeparateProcess
 */
final class waitlist_target_b2_batch_promotion_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The two planned Phase 2 classes this test needs. Both not existing yet is the expected
     * state throughout Phase 1 - the test stays skipped until Phase 2 lands them.
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository');
    }

    /**
     * B2 (K1/K2): when several seats free up at once, exactly min(N, M) candidates get treated
     * in ONE reconcile() call, strictly in FIFO order - and a second reconcile() call with no
     * further capacity change must not over-offer, proving free capacity is computed as
     * capacity - booked - OPEN OFFERS (K2), not just capacity - booked.
     */
    public function test_b2_multiple_free_seats_promote_min_of_n_and_m_immediately(): void {
        global $DB;

        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'progression_factory/db_waitlist_offer_repository do not exist yet (Phase 2). ' .
                'This test is fully written against the planned target API - see the class ' .
                'docblock - and will be activated once those classes land.'
            );
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $occupants = [];
        for ($i = 0; $i < 3; $i++) {
            $occupants[] = $this->getDataGenerator()->create_user();
        }
        $wlusers = [];
        for ($i = 0; $i < 5; $i++) {
            $wlusers[] = $this->getDataGenerator()->create_user();
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        foreach (array_merge($occupants, $wlusers) as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // A real (non-zero) price for everyone - the OFFER path, not K3's autobooking path.
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'b2-batch-promotion';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 3;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Fill all three seats.
        foreach ($occupants as $occupant) {
            $this->setUser($occupant);
            singleton_service::destroy_user($occupant->id);
            booking_bookit::bookit('option', $settings->id, $occupant->id);
            [$id] = $boinfo->is_available($settings->id, $occupant->id, false);
            if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
                booking_bookit::bookit('option', $settings->id, $occupant->id);
                [$id] = $boinfo->is_available($settings->id, $occupant->id, true);
            }
            if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
                $this->setAdminUser();
                $optionobj->user_submit_response($occupant, 0, 0, 0, MOD_BOOKING_VERIFIED);
            }
        }
        $this->setAdminUser();

        // Five waiting-list users, in strict join order: wlusers[0] .. wlusers[4].
        foreach ($wlusers as $u) {
            $this->setUser($u);
            singleton_service::destroy_user($u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            [$id] = $boinfo->is_available($settings->id, $u->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                'Precondition: every waiting-list user must actually reach ONWAITINGLIST.'
            );
        }
        $this->setAdminUser();

        // Free all three seats at once, BEFORE calling reconcile() a single time - this is the
        // scenario K1 targets: several seats free up, one reconcile() call must batch-promote.
        foreach ($occupants as $occupant) {
            $this->setUser($occupant);
            $optionobj->user_delete_response($occupant->id);
        }
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $progression = $factoryclass::get();
        $repository = new $repositoryclass();

        // Round 1: 3 free seats, 5 candidates -> min(N, M) = 3 must be offered, in ONE call.
        $progression->reconcile((int) $option->id, 'b2_test_round1');

        $openoffersround1 = $repository->get_open_offers((int) $option->id);
        $this->assertCount(
            3,
            $openoffersround1,
            'B2/K1: exactly min(N, M) = min(5, 3) = 3 candidates must be offered in a single ' .
            'reconcile() call when three seats free up at once - not one at a time.'
        );
        $offereduserids1 = array_map(fn($o) => (int) $o->userid, $openoffersround1);
        sort($offereduserids1);
        $expectedfirstthree = [(int) $wlusers[0]->id, (int) $wlusers[1]->id, (int) $wlusers[2]->id];
        sort($expectedfirstthree);
        $this->assertEquals(
            $expectedfirstthree,
            $offereduserids1,
            'B2/K1: the three offers must go to exactly the three FIFO-first candidates - no ' .
            'overtaking.'
        );
        $this->assertNotContains(
            (int) $wlusers[3]->id,
            $offereduserids1,
            'B2/K1: the fourth candidate must NOT be treated yet - only min(N, M) seats exist.'
        );
        $this->assertNotContains(
            (int) $wlusers[4]->id,
            $offereduserids1,
            'B2/K1: the fifth candidate must NOT be treated yet - only min(N, M) seats exist.'
        );

        // Round 1b (K2): re-reconcile with NO further capacity change - the three OPEN offers
        // just created must themselves count against free capacity, or the remaining two
        // candidates would be wrongly over-offered beyond the option's actual maxanswers.
        $progression->reconcile((int) $option->id, 'b2_test_round1_repeat_no_new_capacity');

        $openoffersafterrepeat = $repository->get_open_offers((int) $option->id);
        $this->assertCount(
            3,
            $openoffersafterrepeat,
            'B2/K2: a second reconcile() call with no new free capacity must not create ' .
            'additional offers - open offers must count against free capacity ' .
            '(capacity - booked - open offers), not just confirmed bookings.'
        );
        $offereduserids1b = array_map(fn($o) => (int) $o->userid, $openoffersafterrepeat);
        $this->assertNotContains(
            (int) $wlusers[3]->id,
            $offereduserids1b,
            'B2/K2: still no offer for the fourth candidate - capacity is genuinely exhausted ' .
            'by the three open offers.'
        );
        $this->assertNotContains(
            (int) $wlusers[4]->id,
            $offereduserids1b,
            'B2/K2: still no offer for the fifth candidate - capacity is genuinely exhausted by ' .
            'the three open offers.'
        );

        // Round 2: a later, generous free-capacity event (far more seats than remaining
        // candidates) - both remaining candidates (wlusers[3], wlusers[4]) must be treated in
        // this one call, and nobody already-offered gets a second, duplicate offer.
        $DB->set_field('booking_options', 'maxanswers', 13, ['id' => $option->id]);
        singleton_service::destroy_booking_option_singleton($option->id);
        $progression->reconcile((int) $option->id, 'b2_test_round2_generous_capacity');

        $openoffersround2 = $repository->get_open_offers((int) $option->id);
        $offereduserids2 = array_map(fn($o) => (int) $o->userid, $openoffersround2);
        $this->assertContains(
            (int) $wlusers[3]->id,
            $offereduserids2,
            'B2/K1: with far more free capacity than remaining candidates, the fourth candidate ' .
            'must be treated in this round.'
        );
        $this->assertContains(
            (int) $wlusers[4]->id,
            $offereduserids2,
            'B2/K1: with far more free capacity than remaining candidates, the fifth (last) ' .
            'candidate must be treated in this round too - min(N, M) never exceeds N.'
        );
        $this->assertCount(
            5,
            $offereduserids2,
            'B2/K1: exactly all five candidates have an open offer now, nobody more than once.'
        );
        $this->assertEquals(
            5,
            count(array_unique($offereduserids2)),
            'B2/K5: no candidate may have accumulated more than one open offer across rounds.'
        );
    }
}
