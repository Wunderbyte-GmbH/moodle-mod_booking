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
 * End-to-end reproduction of the manual test "Issue 2" (waiting list, unconfirm/trash on the
 * manage-users table).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use mod_booking\bo_availability\bo_info;
use mod_booking\event\bookingoptionwaitinglist_booked;
use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\offered;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Manual test "Issue 2", steps 5 and 6, replayed through the exact calls the manage-users table's
 * "unconfirm" and "trash" buttons end in
 * (\mod_booking\table\manageusers_table::action_unconfirmbooking()/action_deletebooking()).
 *
 * Step 5 ("Person 2 wird von der Warteliste abgemeldet / unconfirmed"): the "unconfirm" button
 * calls user_submit_response(..., MOD_BOOKING_BO_SUBMIT_STATUS_UN_CONFIRM, ...), which REWRITES
 * person 2's existing waiting-list answer - it is not a new join. Before Variante A, that rewrite
 * still ran the full after_successful_booking_routine() (bookingoptionwaitinglist_booked event and
 * its rules, calendar entry, "book" after-actions, legacy status mail) exactly as if person 2 had
 * newly landed on the waiting list - the reported "Person 2 erhält gleichzeitig die
 * Benachrichtigung, dass sie auf der Warteliste ist". This test locks in Variante A (Georg,
 * 2026-09-22): unconfirm keeps declining the offer and reconciling immediately - person 3 must
 * still be offered right away - but must no longer re-fire the landing event/rules for person 2.
 *
 * Step 6 ("Person 3 wird von der Warteliste gelöscht"): the "trash" button calls
 * user_delete_response($userid, false, false, false) - same removal path already covered by
 * waitlist_offer_released_on_leave_test, just with the exact argument list the button uses
 * ($syncwaitinglist=false). Re-verified here end-to-end with a NEW joiner taking the freed seat,
 * to lock in the actual manual-test step: a new candidate must be offered immediately, not after
 * the stale 24h interval.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::user_submit_response
 * @covers \mod_booking\booking_option::user_delete_response
 * @covers \mod_booking\event\observer\unconfirm_waitlist_adapter::decline
 * @covers \mod_booking\event\observer\answer_removed_waitlist_adapter::skip
 * @runInSeparateProcess
 */
final class waitlist_unconfirm_and_trash_reoffer_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /** @var int Offer interval of the rule in minutes (24 hours, as in the manual test). */
    private const INTERVALMINUTES = 1440;

    /** @var string Subject of the rule mail, used to recognise offer notifications. */
    private const OFFERSUBJECT = 'offersubj';

    /**
     * Step 5: unconfirming person 2 (the "unconfirm" button) must decline their offer and hand it
     * to person 3 immediately, WITHOUT re-firing the waiting-list-landing event/rules for person 2
     * - Variante A: person 2 stays on the waiting list (locked, K7), silently, not notified again.
     */
    public function test_unconfirm_does_not_refire_the_waitinglist_landing_event_for_the_unconfirmed_user(): void {
        $s = $this->build_scenario();

        // A rule reacting to the landing event, same shape as
        // waitingforconfirmation_waitinglist_events_test::create_mail_rule() - if the routine
        // re-runs for person 2, this rule fires a second time and queues a second mail for them.
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_rule([
            'name' => 'landing-rule',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",'
                . '"subject":"landingsubj {title}","template":"x","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoptionwaitinglist_booked",'
                . '"condition":"0","aftercompletion":1,"cancelrules":[]}',
        ]);

        // Same call the "unconfirm" button ends in (manageusers_table::action_unconfirmbooking()).
        $eventsink = $this->redirectEvents();
        $this->setAdminUser();
        $s->optionobj->user_submit_response(
            $s->person2,
            0,
            0,
            MOD_BOOKING_BO_SUBMIT_STATUS_UN_CONFIRM,
            MOD_BOOKING_VERIFIED
        );
        $events = $eventsink->get_events();
        $eventsink->close();

        foreach ($events as $event) {
            if (
                $event instanceof bookingoptionwaitinglist_booked
                && (int) $event->relateduserid === (int) $s->person2->id
            ) {
                $this->fail(
                    'Step 5/Variante A: unconfirming person 2 rewrites their EXISTING waiting-list ' .
                    'answer - it must not re-fire bookingoptionwaitinglist_booked for them, exactly ' .
                    'like confirming does not (booking_option.php\'s own ' .
                );
            }
        }

        $queuedforperson2 = false;
        foreach (\core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc') as $task) {
            $customdata = json_encode($task->get_custom_data());
            if (str_contains($customdata, 'landingsubj') && str_contains($customdata, (string) $s->person2->id)) {
                $queuedforperson2 = true;
            }
        }
        $this->assertFalse(
            $queuedforperson2,
            'Step 5/Variante A: no landing-rule mail may be queued for person 2 out of an unconfirm ' .
            '- that is exactly the spurious "you are on the waiting list" notification reported.'
        );

        // The actual point of unconfirm must still work: person 2's offer declined (permanently
        // locked, K7) and person 3 offered immediately - Variante A only removes the re-fired
        // landing event, not the decline/reconcile behaviour B1 already covers.
        $offer2 = $s->repository->get_offer_by_id((int) $s->offer2->id);
        $this->assertNotInstanceOf(offered::class, $offer2->status, 'Precondition: person 2\'s offer must be closed.');
        $this->assertTrue(
            $s->repository->is_permanently_declined((int) $s->option->id, (int) $s->person2->id),
            'Precondition: unconfirm must still be a K7 permanent decline (unlike leaving the list).'
        );
        $openoffers = $s->repository->get_open_offers((int) $s->option->id);
        $this->assertCount(1, $openoffers, 'Precondition: exactly one open offer, for person 3, right now.');
        $offer3 = reset($openoffers);
        $this->assertEquals((int) $s->person3->id, (int) $offer3->userid);
        $this->assertEquals(
            1,
            $this->count_offer_messages($s->sink->get_messages(), (int) $s->person3->id),
            'Precondition: person 3 must have been notified right away.'
        );
        $s->sink->close();
    }

    /**
     * Step 6: removing the offer holder via the "trash" button (manageusers_table::
     * action_deletebooking(), user_delete_response($id, false, false, false)) must free the offer
     * immediately - a newly joining candidate must be offered right away, not after the stale 24h
     * interval of the removed person's offer.
     */
    public function test_offer_holder_removed_via_trash_button_frees_the_seat_for_a_new_joiner(): void {
        $s = $this->build_scenario();

        // Same call the "trash" button ends in for a plain (non-reserved) waiting-list answer.
        $this->setAdminUser();
        $s->optionobj->user_delete_response($s->person2->id, false, false, false);
        singleton_service::destroy_booking_option_singleton($s->option->id);
        singleton_service::destroy_booking_answers($s->option->id);
        $this->setAdminUser();

        $offer2 = $s->repository->get_offer_by_id((int) $s->offer2->id);
        $this->assertNotInstanceOf(
            offered::class,
            $offer2->status,
            'Step 6: the trash button must close the removed person\'s offer at once, not leave it ' .
            'open until its 24h interval runs out.'
        );
        $this->assertFalse(
            $s->repository->is_permanently_declined((int) $s->option->id, (int) $s->person2->id),
            'Step 6: being removed by an admin is not a refusal - no K7 lock for person 2.'
        );

        // A brand-new person joins the now-free seat.
        $settings = singleton_service::get_instance_of_booking_option_settings($s->option->id);
        $boinfo = new bo_info($settings);
        $person4 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($person4->id, $s->course->id, 'student');
        $this->setUser($person4);
        singleton_service::destroy_user($person4->id);
        booking_bookit::bookit('option', $settings->id, $person4->id);
        booking_bookit::bookit('option', $settings->id, $person4->id);
        [$id] = $boinfo->is_available($settings->id, $person4->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $id,
            'Precondition: person 4 must land on the waiting list (person 3 still holds the seat).'
        );
        $this->setAdminUser();

        $openoffers = $s->repository->get_open_offers((int) $s->option->id);
        $this->assertCount(
            1,
            $openoffers,
            'Step 6: exactly one open offer expected right after person 2\'s removal - person 3 - ' .
            'person 4\'s join must not have created a second one while the seat is still taken.'
        );
        $offer3 = reset($openoffers);
        $this->assertEquals((int) $s->person3->id, (int) $offer3->userid);
        $this->assertEquals(
            1,
            $this->count_offer_messages($s->sink->get_messages(), (int) $s->person3->id),
            'Step 6: person 3 must have been offered the freed seat right away.'
        );
        $s->sink->close();
    }

    /**
     * Builds: one seat, person 1 booked, persons 2 and 3 waiting (all paying), rule interval 24h.
     * Person 1 cancels through the real path, so person 2 holds the one open offer afterwards.
     *
     * @return \stdClass course, option, optionobj, person1..3, offer2, repository, sink
     */
    private function build_scenario(): \stdClass {
        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $person1 = $this->getDataGenerator()->create_user();
        $person2 = $this->getDataGenerator()->create_user();
        $person3 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        foreach ([$person1, $person2, $person3] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        $plugingenerator->create_rule([
            'name' => 'issue2-interval-rule',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => json_encode([
                'sendical' => 0,
                'sendicalcreateorcancel' => '',
                'interval' => self::INTERVALMINUTES,
                'subject' => self::OFFERSUBJECT,
                'template' => 'offermsg',
                'templateformat' => '1',
            ]),
            'rulename' => 'rule_react_on_event',
            'ruledata' => json_encode([
                'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
                'aftercompletion' => 0,
                'cancelrules' => [],
                'condition' => '0',
            ]),
        ]);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'issue2-unconfirm-trash';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
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

        // Person 1 takes the seat (admin override: a paid seat cannot be taken through bookit() alone).
        $this->setUser($person1);
        singleton_service::destroy_user($person1->id);
        booking_bookit::bookit('option', $settings->id, $person1->id);
        [$id] = $boinfo->is_available($settings->id, $person1->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $person1->id);
            [$id] = $boinfo->is_available($settings->id, $person1->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $optionobj->user_submit_response($person1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Persons 2 and 3 join the waiting list, in this order.
        foreach ([$person2, $person3] as $user) {
            $this->setUser($user);
            singleton_service::destroy_user($user->id);
            booking_bookit::bookit('option', $settings->id, $user->id);
            booking_bookit::bookit('option', $settings->id, $user->id);
            [$id] = $boinfo->is_available($settings->id, $user->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                'Precondition: both waiting persons must actually be on the waiting list.'
            );
        }
        $this->setAdminUser();

        // Person 1 cancels -> person 2 holds the offer.
        $sink = $this->redirectMessages();
        $this->setUser($person1);
        $optionobj->user_delete_response($person1->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        $repository = new db_waitlist_offer_repository();
        $openoffers = $repository->get_open_offers((int) $option->id);
        $this->assertCount(1, $openoffers, 'Precondition: person 1\'s cancellation must produce one open offer.');
        $offer2 = reset($openoffers);
        $this->assertEquals((int) $person2->id, (int) $offer2->userid, 'Precondition: the offer must be person 2\'s.');
        $this->assertEquals(
            0,
            $this->count_offer_messages($sink->get_messages(), (int) $person3->id),
            'Precondition: person 3 has not been notified yet.'
        );

        // Fresh singletons for the action under test.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        return (object) [
            'course' => $course,
            'option' => $option,
            'optionobj' => $optionobj,
            'person1' => $person1,
            'person2' => $person2,
            'person3' => $person3,
            'offer2' => $offer2,
            'repository' => $repository,
            'sink' => $sink,
        ];
    }

    /**
     * Counts the captured messages that are offer notifications for the given user.
     *
     * @param array $messages messages captured by the message sink
     * @param int $userid recipient
     * @return int
     */
    private function count_offer_messages(array $messages, int $userid): int {
        $count = 0;
        foreach ($messages as $message) {
            if ((int) $message->useridto === $userid && str_contains($message->subject, self::OFFERSUBJECT)) {
                $count++;
            }
        }
        return $count;
    }
}
