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
 * B1 (K7/T4, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): THE u:rise bugfix. After a
 * waiting-list offer is declined (manual unconfirm), the NEXT person in the queue must get the
 * offer immediately - not the person who just declined, ever again for this option, even across
 * a later, completely independent free-capacity event. This is the target-behaviour replacement
 * for the Ist-Zustand documented in A1
 * (rules_waitinglist_notification_test.php::test_manual_unconfirm_triggers_immediate_next_task_but_keeps_interval_for_following_task),
 * see WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.3.
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
 * Target-behaviour test for K7's permanent-decline lockout (B1).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2,
 * §2.3, §3.2 and §3.3 (still "Entwurf, noch nicht final abgenommen" at the time this test was
 * written) - same caveats as the Kategorie-C tests: the target classes do not exist yet, this
 * test is guarded with class_exists()/markTestSkipped() and will need minor signature
 * reconciliation once Phase 2 lands them. In particular:
 * - The formal repository interface (§3.2) names the query `is_permanently_declined(int
 *   $optionid, int $userid): bool`, while the reconcile() pseudocode in §3.3 sketches a
 *   batch-style `get_permanently_declined_userids(int $optionid)` - this test is written against
 *   the formal interface name.
 * - `offer_status::declined()` is a best-effort guess at the state pattern's factory method name
 *   (§2.2 only specifies the interface `can_transition_to()`/`is_terminal()`, not concrete state
 *   construction) - exactly the kind of detail Phase 2 is expected to pin down.
 * - Phase 3's `unconfirm_waitlist_adapter` (WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md:
 *   "Setzt Offer->declined, dann sofortiges reconcile()") is what will eventually perform the
 *   transition+reconcile sequence below automatically; this Phase-1 test calls both steps
 *   directly to simulate it, same pattern already used in C3/C5.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::is_permanently_declined
 * @runInSeparateProcess
 */
final class waitlist_target_b1_immediate_next_offer_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The three planned Phase 2 classes this test needs. All three not existing yet is the
     * expected state throughout Phase 1 - the test stays skipped until Phase 2 lands them.
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository')
            && interface_exists('\mod_booking\local\waitlist\offer_status');
    }

    /**
     * B1 (K7/T4): a declined offer must never be re-offered to the same person - the NEXT
     * unbehandelt person gets it instead, immediately, and the lockout survives even a later,
     * unrelated free-capacity event (K7 is permanent, not round-scoped - the actual policy
     * decision behind this test, see WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.3).
     */
    public function test_b1_declined_user_is_never_reoffered_next_person_gets_it_immediately(): void {
        global $DB;

        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'progression_factory/db_waitlist_offer_repository/offer_status do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

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

        // A real (non-zero) price for everyone - K7 is about the OFFER path (price > 0), not
        // K3's autobooking path (price = 0), so the decision strategy must land on OFFER.
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'b1-immediate-next-offer';
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

        // K11: progression::reconcile() only acts when an active send_mail_interval rule applies
        // - this test predates rule_condition_checker, so a plain ALWAYS rule is added here.
        $plugingenerator->create_rule([
            'name' => 'b1-interval-rule',
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

        // Free the seat - a genuine T1 (cancellation) trigger event.
        $this->setUser($occupant);
        $optionobj->user_delete_response($occupant->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $progression = $factoryclass::get();
        $repository = new $repositoryclass();

        // Round 1: exactly one free seat -> exactly one offer, to the FIFO-first candidate.
        $progression->reconcile((int) $option->id, 'b1_test_initial');

        $openoffersround1 = $repository->get_open_offers((int) $option->id);
        $this->assertCount(
            1,
            $openoffersround1,
            'Precondition: freeing the single seat must produce exactly one open offer (K1).'
        );
        $offertowluser1 = reset($openoffersround1);
        $this->assertEquals(
            (int) $wluser1->id,
            (int) $offertowluser1->userid,
            'Precondition: the FIFO-first waiting-list user must be the one offered the seat.'
        );

        // T4: wluser1 manually declines (unconfirm). unconfirm_waitlist_adapter (Phase 3) will
        // perform this transition+reconcile sequence automatically - simulated directly here.
        $repository->transition($offertowluser1, new \mod_booking\local\waitlist\offer_statuses\declined());
        $progression->reconcile((int) $option->id, 'b1_test_after_decline');

        $this->assertTrue(
            $repository->is_permanently_declined((int) $option->id, (int) $wluser1->id),
            'B1/K7: a declined offer must register a permanent lockout for this option, not just ' .
            'flip the offer row to a terminal state.'
        );

        $openoffersafterdecline = $repository->get_open_offers((int) $option->id);
        $this->assertCount(
            1,
            $openoffersafterdecline,
            'B1/K1: exactly one seat is free again after the decline - exactly one new offer.'
        );
        $offertowluser2 = reset($openoffersafterdecline);
        $this->assertEquals(
            (int) $wluser2->id,
            (int) $offertowluser2->userid,
            'B1/K7: THE u:rise bugfix - the NEXT person in the queue (wluser2) must get the ' .
            'offer immediately, not the person who just declined.'
        );
        $openofferuserids = array_map(fn($o) => (int) $o->userid, $openoffersafterdecline);
        $this->assertNotContains(
            (int) $wluser1->id,
            $openofferuserids,
            'B1/K7: the declined user (wluser1) must not appear in any open offer after the ' .
            'immediate re-reconcile - declined->offered must be structurally unreachable.'
        );

        // Round 2, a LATER and otherwise unrelated free-capacity event (increasing maxanswers,
        // not a further decline) - proves K7 is a PERMANENT lockout, not merely "not reoffered
        // within the same reconcile() call" or "not reoffered this round".
        $DB->set_field('booking_options', 'maxanswers', 2, ['id' => $option->id]);
        // The raw DB write bypasses the mod_booking/bookingoptionsettings MUC cache -
        // destroy_booking_option_singleton() only clears the in-process singleton, not this.
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($option->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        $progression->reconcile((int) $option->id, 'b1_test_later_unrelated_round');

        $openoffersround2 = $repository->get_open_offers((int) $option->id);
        $round2userids = array_map(fn($o) => (int) $o->userid, $openoffersround2);
        $this->assertContains(
            (int) $wluser2->id,
            $round2userids,
            'Precondition: wluser2\'s still-open offer from round 1 must not have been touched.'
        );
        $this->assertContains(
            (int) $wluser3->id,
            $round2userids,
            'B1/K1: the newly freed second seat must go to wluser3, the only remaining ' .
            'unbehandelt candidate.'
        );
        $this->assertNotContains(
            (int) $wluser1->id,
            $round2userids,
            'B1/K7: wluser1 must still be excluded even in a completely separate, later ' .
            'free-capacity round - the lockout is permanent, not round-scoped.'
        );
        $this->assertTrue(
            $repository->is_permanently_declined((int) $option->id, (int) $wluser1->id),
            'B1/K7: the permanent lockout must still hold after a later, unrelated round.'
        );
    }
}
