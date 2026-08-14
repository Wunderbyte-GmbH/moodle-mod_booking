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
 * B4 (K4, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): an open offer whose deadline passes
 * must be HARD-expired - actively transitioned to a terminal `expired` state (no grace, no
 * silent survival) - and the next unbehandelt candidate must get an immediate new offer. Also
 * covers the K5 idempotency guard explicitly called out in
 * WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §4.1's `expire_waitlist_offer_adhoc` pseudocode:
 * an offer that was already resolved (e.g. accepted) before its expiry task runs must NOT be
 * force-expired. This is the first test in Kategorie B to exercise `\core\clock` DI
 * (§5.1) via `$this->mock_clock_with_frozen()`, the structural fix for the Schritt-2 finding
 * that `tool_mocktesttime` and `\core\task\manager`'s own clock are unsynchronised.
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
 * Target-behaviour test for K4's hard offer expiry, including the K5 idempotency guard (B4).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2,
 * §4.1 and §5.1 (still "Entwurf, noch nicht final abgenommen" at the time this test was written)
 * - same caveats as B1-B3/C1-C5: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them. In particular, the exact source of an offer's expiry interval (rule config vs.
 * option setting vs. a global default) is not pinned down by the architecture doc - this test
 * deliberately does not assume a concrete interval value, it reads `expiresat` back off the
 * created offer and jumps the frozen clock straight to it, so it stays correct regardless of
 * where Phase 2 ends up sourcing the interval from.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\expire_waitlist_offer_adhoc::execute
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @runInSeparateProcess
 */
final class waitlist_target_b4_hard_expiry_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The four planned Phase 2 classes this test needs. None existing yet is the expected state
     * throughout Phase 1 - the test stays skipped until Phase 2 lands them.
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository')
            && class_exists('\mod_booking\local\waitlist\offer_status')
            && class_exists('\mod_booking\task\expire_waitlist_offer_adhoc');
    }

    /**
     * B4 (K4/K5): an offer whose deadline passes is hard-expired and the next candidate is
     * offered immediately; an offer already resolved before its expiry task runs is left alone.
     */
    public function test_b4_offer_deadline_hard_expires_and_promotes_next_candidate(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'progression_factory/db_waitlist_offer_repository/offer_status/' .
                'expire_waitlist_offer_adhoc do not exist yet (Phase 2). This test is fully ' .
                'written against the planned target API - see the class docblock - and will be ' .
                'activated once those classes land.'
            );
        }

        // Freeze the clock BEFORE anything runs - the structural §5.1 fix means the offer's
        // expiresat must be computed off this injected clock, not a bare time() call.
        $clock = $this->mock_clock_with_frozen(time());

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $occupant = $this->getDataGenerator()->create_user();
        $wluser1 = $this->getDataGenerator()->create_user();
        $wluser2 = $this->getDataGenerator()->create_user();
        $wluser3 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($occupant->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($wluser1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($wluser2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($wluser3->id, $course->id, 'student');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // A real (non-zero) price for everyone - K4 is about the OFFER path's deadline.
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'b4-hard-expiry';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
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

        // Fill the single seat with the occupant.
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
        $this->setAdminUser();

        // Three waiting-list users, in strict join order: wluser1, wluser2, wluser3.
        foreach ([$wluser1, $wluser2, $wluser3] as $u) {
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

        // Free the seat.
        $this->setUser($occupant);
        $optionobj->user_delete_response($occupant->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $offerstatusclass = '\mod_booking\local\waitlist\offer_status';
        $expiretaskclass = '\mod_booking\task\expire_waitlist_offer_adhoc';
        $progression = $factoryclass::get();
        $repository = new $repositoryclass();

        // Round 1: wluser1 gets the offer.
        $progression->reconcile((int) $option->id, 'b4_test_initial');

        $openoffersround1 = $repository->get_open_offers((int) $option->id);
        $this->assertCount(1, $openoffersround1, 'Precondition: exactly one open offer after round 1.');
        $offer1 = reset($openoffersround1);
        $this->assertEquals((int) $wluser1->id, (int) $offer1->userid);
        $this->assertGreaterThan(
            $clock->time(),
            (int) $offer1->expiresat,
            'B4/K4/§5.1: the offer\'s expiry must be in the future relative to the INJECTED ' .
            'clock at creation time - proof that expiresat is computed off \core\clock, not a ' .
            'bare, untestable time() call.'
        );
        $this->assertLessThanOrEqual(
            $clock->time() + (86400 * 30),
            (int) $offer1->expiresat,
            'B4/K4: sanity bound - an offer must not be effectively "never expiring".'
        );

        // Jump the frozen clock straight to (just past) the offer's actual expiresat - correct
        // regardless of which concrete interval configuration Phase 2 ends up using.
        $clock->set_to((int) $offer1->expiresat + 1);

        // Fire whatever expire_waitlist_offer_adhoc task was queued for offer1.
        ob_start();
        $this->runAdhocTasks($expiretaskclass);
        ob_get_clean();
        $this->setAdminUser();

        $openoffersafterexpiry = $repository->get_open_offers((int) $option->id);
        $offereduserids = array_map(fn($o) => (int) $o->userid, $openoffersafterexpiry);
        $this->assertNotContains(
            (int) $wluser1->id,
            $offereduserids,
            'B4/K4: a hard-expired offer must no longer be open - wluser1\'s expired offer must ' .
            'not still count as an active offer.'
        );
        $this->assertContains(
            (int) $wluser2->id,
            $offereduserids,
            'B4/K4: the next candidate (wluser2) must get an immediate new offer once the ' .
            'previous one hard-expires - the seat must not sit idle waiting for a later trigger.'
        );
        $this->assertNotContains(
            (int) $wluser3->id,
            $offereduserids,
            'B4/K1: only one seat exists - wluser3 must not be treated yet.'
        );
        $offer2 = null;
        foreach ($openoffersafterexpiry as $o) {
            if ((int) $o->userid === (int) $wluser2->id) {
                $offer2 = $o;
                break;
            }
        }
        $this->assertNotNull($offer2, 'Precondition: wluser2\'s new offer must be findable.');

        // K5 idempotency guard: resolve offer2 (accepted) BEFORE its own expiry task would run -
        // the (already-queued) expire task for offer2 must be a no-op, must NOT force it back to
        // expired, and must NOT trigger a bogus reconcile() that hands the seat to wluser3.
        $repository->transition($offer2, $offerstatusclass::accepted());
        $clock->set_to((int) $offer2->expiresat + 1);

        ob_start();
        $this->runAdhocTasks($expiretaskclass);
        ob_get_clean();
        $this->setAdminUser();

        $openoffersfinal = $repository->get_open_offers((int) $option->id);
        $finaluserids = array_map(fn($o) => (int) $o->userid, $openoffersfinal);
        $this->assertNotContains(
            (int) $wluser3->id,
            $finaluserids,
            'B4/K5: the expire task for an already-accepted offer must be idempotent/no-op - it ' .
            'must not wrongly re-open the seat and offer it to wluser3.'
        );
    }
}
