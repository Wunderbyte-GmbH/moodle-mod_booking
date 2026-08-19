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
 * B3 (T8, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): after a K3 autobooking, the reconciler
 * must NOT wait for the next interval tick before continuing - it must keep processing the
 * remaining candidates immediately, within the SAME reconcile() call, even when the decision
 * type changes mid-batch (autobook someone, then offer to someone else). This is the third
 * u:rise-reported scenario (unnötige Wartezeit trotz freier Kapazität) and the direct
 * target-behaviour test for WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3's central loop
 * treating AUTOBOOK and OFFER decisions uniformly - there is no "wait" branch in the new
 * architecture at all, unlike today's send_mail_interval repeat-task chain.
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
 * Target-behaviour test for T8's immediate continuation after autobooking (B3).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2,
 * §3.1 and §3.3 (still "Entwurf, noch nicht final abgenommen" at the time this test was written)
 * - same caveats as B1/B2/C1-C5: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\price_based_decision_strategy::decide
 * @runInSeparateProcess
 */
final class waitlist_target_b3_immediate_continuation_test extends booking_advanced_testcase {
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
     * B3 (T8): a batch of free seats with a MIX of free (autobook) and paid (offer) candidates
     * must be fully processed in ONE reconcile() call - autobooking one candidate must not stop
     * or delay processing of the next, regardless of that next candidate's decision type.
     */
    public function test_b3_autobooking_does_not_block_immediate_processing_of_the_rest(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

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
        for ($i = 0; $i < 4; $i++) {
            $occupants[] = $this->getDataGenerator()->create_user();
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'freecat',
            'identifier' => 'freecat',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 2,
            'name' => 'paidcat',
            'identifier' => 'paidcat',
            'defaultvalue' => 80,
            'pricecatsortorder' => 2,
        ]);

        // FIFO join order: candidatea, candidateb (free -> AUTOBOOK), candidatec, candidated
        // (paid -> OFFER) - deliberately alternating decision types across the batch.
        $candidatea = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'freecat']);
        $candidateb = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'freecat']);
        $candidatec = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $candidated = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $candidates = [$candidatea, $candidateb, $candidatec, $candidated];

        foreach (array_merge($occupants, $candidates) as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
        }
        $this->setAdminUser();

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'b3-immediate-continuation';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 4;
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

        // K11: progression::reconcile() only acts when an active send_mail_interval rule applies
        // - this test predates rule_condition_checker, so a plain ALWAYS rule is added here.
        $plugingenerator->create_rule([
            'name' => 'b3-interval-rule',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']),
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Fill all four seats.
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

        // All four candidates join the waiting list, in order.
        foreach ($candidates as $u) {
            $this->setUser($u);
            singleton_service::destroy_user($u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            [$id] = $boinfo->is_available($settings->id, $u->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                'Precondition: every candidate must actually reach ONWAITINGLIST.'
            );
        }
        $this->setAdminUser();

        // Free all four seats at once, then a SINGLE reconcile() call - this is what T8 targets:
        // one pass must fully drain the queue regardless of how the decision type flips
        // candidate-to-candidate.
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

        $progression->reconcile((int) $option->id, 'b3_test_mixed_batch');

        // Nobody may be left "unbehandelt" after this single call - T8's core claim is that
        // autobooking never stops or defers processing of the rest of the batch.
        $unbehandelt = $repository->get_unbehandelte_waitinglist((int) $option->id, []);
        $this->assertCount(
            0,
            $unbehandelt,
            'B3/T8: with four free seats and four candidates, nobody may remain unbehandelt ' .
            'after a single reconcile() call - autobooking must not block immediate continuation.'
        );

        // Note: candidatea/candidateb have free price -> AUTOBOOK, a real, terminal booking,
        // not an open offer.
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $boinfo = new bo_info(singleton_service::get_instance_of_booking_option_settings($option->id));
        foreach ([$candidatea, $candidateb] as $freeuser) {
            [$id] = $boinfo->is_available($settings->id, $freeuser->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ALREADYBOOKED,
                $id,
                'B3/K3: a free-price candidate must end up genuinely, immediately booked ' .
                '(autobooked), not merely offered.'
            );
        }

        // Note: candidatec/candidated have paid price -> OFFER, an open (non-terminal) offer.
        $openoffers = $repository->get_open_offers((int) $option->id);
        $openofferuserids = array_map(fn($o) => (int) $o->userid, $openoffers);
        $this->assertContains(
            (int) $candidatec->id,
            $openofferuserids,
            'B3/K4: the third (paid) candidate must have an open offer after the same single call.'
        );
        $this->assertContains(
            (int) $candidated->id,
            $openofferuserids,
            'B3/T8: the fourth candidate - reached only AFTER an autobook and an offer already ' .
            'happened earlier in the SAME call - must still be treated immediately, not deferred ' .
            'to a later trigger/interval tick.'
        );
        $this->assertCount(
            2,
            $openoffers,
            'B3: exactly the two paid candidates have an open offer - the two autobooked ' .
            'candidates must not additionally appear as open offers (autobooked is terminal).'
        );
    }
}
