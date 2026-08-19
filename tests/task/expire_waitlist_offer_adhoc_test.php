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
 * Tests for expire_waitlist_offer_adhoc (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §4.1, K4).
 * The task is instantiated and executed directly (set_custom_data() + execute()), not via cron -
 * advanced_testcase::runAdhocTasks() ignores nextruntime entirely (documented finding from this
 * refactor's earlier code-map work), so it cannot exercise the "runs at the scheduled deadline"
 * semantics anyway; the scheduling itself (set_next_run_time()) is exercised implicitly by
 * progression_test.php's K4 test, which already confirms progression::offer() queues this task
 * without error.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\declined;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\local\waitlist\progression_factory;
use mod_booking\singleton_service;
use mod_booking\task\expire_waitlist_offer_adhoc;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * K4/K5 tests for expire_waitlist_offer_adhoc.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\expire_waitlist_offer_adhoc::execute
 */
final class expire_waitlist_offer_adhoc_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Builds and executes the task for the given offer id.
     *
     * @param int $offerid
     * @return void
     */
    private function run_task(int $offerid): void {
        $task = new expire_waitlist_offer_adhoc();
        $task->set_custom_data(['offerid' => $offerid]);
        $task->execute();
    }

    /**
     * K4: an OFFERED offer must be transitioned to expired.
     */
    public function test_execute_expires_an_offered_offer(): void {
        global $DB;

        $repository = new db_waitlist_offer_repository();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $offer = $repository->create_offer(9001, $userid, 1, 1, new offered(), 1000000000, 5);

        $this->run_task($offer->id);

        $raw = $DB->get_record('booking_waitlist_offers', ['id' => $offer->id], '*', MUST_EXIST);
        $this->assertEquals(4, (int) $raw->status, 'expired must persist as status code 4.');
    }

    /**
     * K5: an offer already resolved by something else (e.g. declined) before the task runs must
     * be left untouched - idempotent no-op, not overwritten to expired.
     */
    public function test_execute_is_idempotent_when_offer_already_left_offered_state(): void {
        global $DB;

        $repository = new db_waitlist_offer_repository();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $offer = $repository->create_offer(9002, $userid, 1, 1, new offered(), 1000000000, 5);
        $repository->transition($offer, new declined());

        $this->run_task($offer->id);

        $raw = $DB->get_record('booking_waitlist_offers', ['id' => $offer->id], '*', MUST_EXIST);
        $this->assertEquals(3, (int) $raw->status, 'An already-declined offer must not be overwritten to expired.');
    }

    /**
     * K5: an offerid that no longer resolves to any row must not throw.
     */
    public function test_execute_is_a_noop_when_offer_no_longer_exists(): void {
        $this->run_task(999999);
        $this->addToAssertionCount(1); // Reaching this line without an exception is the assertion.
    }

    /**
     * K1/K4 integration: once the first candidate's offer expires, the freed-up capacity must be
     * offered to the next candidate IMMEDIATELY (execute() re-reconciles), not on some later,
     * unrelated trigger.
     */
    public function test_execute_reconciles_and_offers_the_next_candidate(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $bdata = [
            'name' => 'Expiry Test',
            'eventtype' => 'Test',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ];
        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'paidcat',
            'identifier' => 'paidcat',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'expiry-continuation-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 5;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        $optionid = (int) $option->id;
        singleton_service::destroy_booking_option_singleton($optionid);

        $actstr = json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']);
        $plugingenerator->create_rule([
            'name' => 'expiry-continuation-rule',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        // Two paid candidates, but only one free seat - the second stays unbehandelt (K1) until
        // the first's offer expires.
        $first = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $second = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        foreach ([$first, $second] as $i => $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $DB->insert_record('booking_answers', (object) [
                'bookingid' => 0,
                'userid' => $user->id,
                'optionid' => $optionid,
                'timemodified' => 100 + $i,
                'timecreated' => 100 + $i,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'status' => 0,
            ]);
        }

        $this->setAdminUser();
        progression_factory::get()->reconcile($optionid, 'expiry_continuation_test');

        $offerrepository = new db_waitlist_offer_repository();
        $openoffersbefore = $offerrepository->get_open_offers($optionid);
        $this->assertCount(1, $openoffersbefore, 'Precondition: only the first candidate has an open offer.');
        $this->assertEquals((int) $first->id, $openoffersbefore[0]->userid);

        // The second candidate must still be unbehandelt - K1 batch limit, only 1 seat exists.
        $unbehandeltbefore = $offerrepository->get_unbehandelte_waitinglist($optionid, []);
        $unbehandeltuserids = array_map(fn($u) => (int) $u->userid, $unbehandeltbefore);
        $this->assertContains((int) $second->id, $unbehandeltuserids);

        // Now the first candidate's offer expires - execute() must free the seat AND immediately
        // offer it to the second candidate, without any further external trigger.
        $this->run_task($openoffersbefore[0]->id);

        $expiredrow = $DB->get_record('booking_waitlist_offers', ['id' => $openoffersbefore[0]->id], '*', MUST_EXIST);
        $this->assertEquals(4, (int) $expiredrow->status, 'The first offer must now be expired.');

        $openoffersafter = $offerrepository->get_open_offers($optionid);
        $this->assertCount(1, $openoffersafter, 'K1: exactly the freed single seat must now be offered to someone.');
        $this->assertEquals(
            (int) $second->id,
            $openoffersafter[0]->userid,
            'K4/K1: the second candidate must be offered the freed seat immediately upon expiry, ' .
            'not on some later, unrelated trigger.'
        );
    }

    /**
     * 2026-08-20 Georg decision, direct regression test: if the candidate whose offer just
     * expired is the ONLY person on the waiting list, execute()'s re-reconcile must NOT
     * immediately re-offer the seat back to that same person - the permanent lock (now shared
     * with K7) must prevent an infinite expire-reoffer-expire spam loop. The seat is simply left
     * open until some later, independent trigger (e.g. the heartbeat task) reconsiders it.
     */
    public function test_execute_does_not_reoffer_the_sole_candidate_whose_own_offer_expired(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $bdata = [
            'name' => 'Expiry Solo Test',
            'eventtype' => 'Test',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ];
        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'paidcat',
            'identifier' => 'paidcat',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'expiry-solo-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 5;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        $optionid = (int) $option->id;
        singleton_service::destroy_booking_option_singleton($optionid);

        $actstr = json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']);
        $plugingenerator->create_rule([
            'name' => 'expiry-solo-rule',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        $solo = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $this->getDataGenerator()->enrol_user($solo->id, $course->id, 'student');
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $solo->id,
            'optionid' => $optionid,
            'timemodified' => 100,
            'timecreated' => 100,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'status' => 0,
        ]);

        $this->setAdminUser();
        progression_factory::get()->reconcile($optionid, 'expiry_solo_test');

        $offerrepository = new db_waitlist_offer_repository();
        $openoffersbefore = $offerrepository->get_open_offers($optionid);
        $this->assertCount(1, $openoffersbefore, 'Precondition: the sole candidate has an open offer.');

        // The offer expires - solo is the ONLY person on the waiting list, so there is nobody to
        // hand the seat to.
        $this->run_task($openoffersbefore[0]->id);

        $expiredrow = $DB->get_record('booking_waitlist_offers', ['id' => $openoffersbefore[0]->id], '*', MUST_EXIST);
        $this->assertEquals(4, (int) $expiredrow->status, 'The offer must now be expired.');

        $openoffersafter = $offerrepository->get_open_offers($optionid);
        $this->assertCount(
            0,
            $openoffersafter,
            'The sole candidate must NOT be immediately re-offered the seat they just failed to ' .
            'act on - that would spam them in an endless expire-reoffer loop.'
        );
        $this->assertTrue(
            $offerrepository->is_permanently_declined($optionid, (int) $solo->id),
            'The permanent lock is what actually prevents the loop - solo is excluded from any ' .
            'future reconcile() call for this option, not just this one.'
        );
    }
}
