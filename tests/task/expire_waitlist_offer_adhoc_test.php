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

    /**
     * Type 4 fixture: a paid option with one free seat, a send_mail_interval rule and $count paid
     * candidates on the waiting list (joined in this order), with the given waitlistrecycling mode.
     * Reconciles once, so the first candidate holds the only open offer.
     *
     * @param string $optiontext unique per test
     * @param int $waitlistrecycling 0 stop, 1 go through again, 2 open up, 3 remove on offer expiry
     * @param int $count number of candidates on the waiting list
     * @return array [int $optionid, \stdClass[] $candidates, \mod_booking\local\waitlist\waitlist_offer $firstoffer]
     */
    private function create_option_with_offer(string $optiontext, int $waitlistrecycling, int $count = 2): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        if (!$DB->record_exists('user_info_field', ['shortname' => 'pricecat'])) {
            $this->getDataGenerator()->create_custom_profile_field([
                'datatype' => 'text',
                'shortname' => 'pricecat',
                'name' => 'pricecat',
            ]);
        }
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $bdata = [
            'name' => 'Type 4 Test',
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
        if (!$DB->record_exists('booking_pricecategories', ['identifier' => 'type4paidcat'])) {
            $plugingenerator->create_pricecategory((object) [
                'ordernum' => 1,
                'name' => 'type4paidcat',
                'identifier' => 'type4paidcat',
                'defaultvalue' => 80,
                'pricecatsortorder' => 1,
            ]);
        }

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optiontext;
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
        $DB->set_field('booking_options', 'waitlistrecycling', $waitlistrecycling, ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $actstr = json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']);
        $plugingenerator->create_rule([
            'name' => 'type4-rule-' . $optiontext,
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        $candidates = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'type4paidcat']);
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
            $candidates[] = $user;
        }

        $this->setAdminUser();
        progression_factory::get()->reconcile($optionid, 'type4-setup');

        $openoffers = (new db_waitlist_offer_repository())->get_open_offers($optionid);
        $this->assertCount(1, $openoffers, 'Precondition: exactly one open offer for the single free seat.');
        $this->assertEquals((int) $candidates[0]->id, $openoffers[0]->userid, 'Precondition: the first candidate holds it.');

        return [$optionid, $candidates, $openoffers[0]];
    }

    /**
     * Whether the user still has a booking answer with the given status on the option.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $status MOD_BOOKING_STATUSPARAM_*
     * @return bool
     */
    private function has_answer(int $optionid, int $userid, int $status): bool {
        global $DB;
        return $DB->record_exists('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => $status,
        ]);
    }

    /**
     * Type 4 (waitlistrecycling=3): when the offer expires, the person is taken off the waiting
     * list right away - answer soft-deleted, history entry written, K4 lock cleared - and the freed
     * seat goes to the next candidate as usual.
     */
    public function test_type4_removes_the_expired_candidate_and_offers_the_next(): void {
        global $DB;

        [$optionid, [$first, $second], $offer] = $this->create_option_with_offer('type4-remove', 3);

        $this->run_task($offer->id);

        $this->assertFalse(
            $this->has_answer($optionid, (int) $first->id, MOD_BOOKING_STATUSPARAM_WAITINGLIST),
            'Type 4: the candidate whose offer expired must no longer be on the waiting list.'
        );
        $this->assertTrue(
            $this->has_answer($optionid, (int) $first->id, MOD_BOOKING_STATUSPARAM_DELETED),
            'Type 4: the answer is soft-deleted, like every other removal, not wiped.'
        );
        $this->assertTrue(
            $DB->record_exists('booking_history', [
                'optionid' => $optionid,
                'userid' => $first->id,
                'status' => MOD_BOOKING_STATUSPARAM_WAITINGLIST_DELETED,
            ]),
            'Type 4: the removal must show up in the booking history as "deleted from waiting list".'
        );

        $repository = new db_waitlist_offer_repository();
        $this->assertFalse(
            $repository->is_permanently_declined($optionid, (int) $first->id),
            'Type 4: the K4 lock must be cleared once the person has been removed.'
        );
        $openoffers = $repository->get_open_offers($optionid);
        $this->assertCount(1, $openoffers, 'The freed seat must be offered again right away.');
        $this->assertEquals(
            (int) $second->id,
            $openoffers[0]->userid,
            'The next candidate in line must get the offer.'
        );
    }

    /**
     * Type 4 fires its own bookinganswer_removedfromwaitinglist event for booking rules, and
     * deliberately NOT bookinganswer_cancelled - the person never had a seat, so no cancellation
     * rules or mails may run.
     */
    public function test_type4_fires_the_removed_event_and_no_cancellation_event(): void {
        [$optionid, [$first], $offer] = $this->create_option_with_offer('type4-events', 3);

        $sink = $this->redirectEvents();
        $this->run_task($offer->id);
        $events = $sink->get_events();
        $sink->close();

        $removed = array_values(array_filter(
            $events,
            fn($event) => $event instanceof \mod_booking\event\bookinganswer_removedfromwaitinglist
        ));
        $cancelled = array_filter(
            $events,
            fn($event) => $event instanceof \mod_booking\event\bookinganswer_cancelled
        );

        $this->assertCount(1, $removed, 'Type 4 must fire exactly one bookinganswer_removedfromwaitinglist event.');
        $this->assertEquals($optionid, (int) $removed[0]->objectid, 'objectid must be the booking option id.');
        $this->assertEquals((int) $first->id, (int) $removed[0]->relateduserid, 'relateduserid must be the removed person.');
        $this->assertCount(0, $cancelled, 'Type 4 must not fire bookinganswer_cancelled.');
    }

    /**
     * Type 4: re-joining after the removal is a normal fresh start - the person is a candidate
     * again (no leftover lock) and queues behind everyone who joined earlier.
     */
    public function test_type4_rejoin_after_removal_is_a_fresh_start_at_the_end(): void {
        global $DB;

        [$optionid, [$first, , $third], $offer] = $this->create_option_with_offer('type4-rejoin', 3, 3);

        $this->run_task($offer->id);

        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $first->id,
            'optionid' => $optionid,
            'timemodified' => 500,
            'timecreated' => 500,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'status' => 0,
        ]);

        $repository = new db_waitlist_offer_repository();
        $waiting = $repository->get_unbehandelte_waitinglist(
            $optionid,
            $repository->get_permanently_declined_userids($optionid)
        );

        $this->assertSame(
            [(int) $third->id, (int) $first->id],
            array_map(fn($row) => (int) $row->userid, $waiting),
            'Type 4: the re-joined person must be a candidate again, queued behind the third ' .
            'candidate who has been waiting all along (the second one holds the open offer).'
        );
    }

    /**
     * Type 4 on a paid option: a seat the offer holder has already reserved in their shopping
     * cart is removed together with the waiting list entry (hard expiry, K4) - from the booking
     * answers and from the cart itself.
     */
    public function test_type4_removes_a_cart_reservation_too(): void {
        [$optionid, [$first], $offer] = $this->create_option_with_offer('type4-cart', 3);

        $this->setUser($first);
        \local_shopping_cart\shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        $this->setAdminUser();
        $this->assertTrue(
            $this->has_answer($optionid, (int) $first->id, MOD_BOOKING_STATUSPARAM_RESERVED),
            'Precondition: the offer holder has reserved the seat in their cart.'
        );

        $this->run_task($offer->id);

        $this->assertFalse(
            $this->has_answer($optionid, (int) $first->id, MOD_BOOKING_STATUSPARAM_RESERVED),
            'Type 4: the cart reservation must be removed together with the waiting list entry.'
        );
        $this->assertFalse(
            $this->has_answer($optionid, (int) $first->id, MOD_BOOKING_STATUSPARAM_WAITINGLIST),
            'Type 4: nothing may remain on the waiting list either.'
        );
        $this->assertEmpty(
            \local_shopping_cart\local\cartstore::instance((int) $first->id)->get_item('mod_booking', 'option', $optionid),
            'Type 4: the item must be gone from the shopping cart as well.'
        );
    }

    /**
     * Counter-check for the default mode "Stop" (waitlistrecycling=0): the expired candidate stays
     * on the waiting list, locked out (K4) - type 4 must not change this existing behaviour.
     */
    public function test_stop_mode_keeps_the_expired_candidate_locked_on_the_list(): void {
        [$optionid, [$first, $second], $offer] = $this->create_option_with_offer('type0-keep', 0);

        $this->run_task($offer->id);

        $this->assertTrue(
            $this->has_answer($optionid, (int) $first->id, MOD_BOOKING_STATUSPARAM_WAITINGLIST),
            'Stop: the candidate whose offer expired must stay on the waiting list.'
        );
        $repository = new db_waitlist_offer_repository();
        $this->assertTrue(
            $repository->is_permanently_declined($optionid, (int) $first->id),
            'Stop: the K4 lock must stay in place.'
        );
        $openoffers = $repository->get_open_offers($optionid);
        $this->assertCount(1, $openoffers);
        $this->assertEquals((int) $second->id, $openoffers[0]->userid, 'The next candidate still gets the seat.');
    }
}
