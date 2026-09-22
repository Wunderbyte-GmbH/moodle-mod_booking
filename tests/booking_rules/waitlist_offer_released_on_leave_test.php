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
 * A waiting-list offer that is declined or whose holder leaves the waiting list must be passed on
 * to the next person immediately, not only when the offer's interval (24h) has run out.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use mod_booking\bo_availability\bo_info;
use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\local\waitlist\offer_statuses\skipped;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Follow-up to waitlist_employee_student_offer_expiry_test: there the offer of person 2 runs out
 * unpaid. Here person 2 actively gets out of the way before the interval is over, in two ways:
 *
 * - declines the offer (manual unconfirm) - the path B1 already covers on repository level;
 * - leaves the waiting list (own cancellation, or removal by an admin) - the offer row of a user
 *   whose answer is gone is not touched anywhere (only unconfirm/booking/expiry transition
 *   offers), while capacity_calculator::free_capacity() counts every open offer against the
 *   seats. If that is what happens, the seat stays blocked for person 3 until the 24h expiry.
 *
 * In both cases the clock is NOT advanced: person 3 must be offered right away.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\capacity_calculator::free_capacity
 * @covers \mod_booking\booking_option::user_delete_response
 * @runInSeparateProcess
 */
final class waitlist_offer_released_on_leave_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /** @var int Offer interval of the rule in minutes (24 hours). */
    private const INTERVALMINUTES = 1440;

    /** @var string Subject of the rule mail, used to recognise offer notifications. */
    private const OFFERSUBJECT = 'offersubj';

    /**
     * Person 2 declines the offer (manual unconfirm) -> person 3 is offered immediately.
     */
    public function test_declined_offer_is_passed_on_to_next_person_immediately(): void {
        $s = $this->build_scenario();

        // Same call the unconfirm button ends in (see rules_waitinglist_notification_test).
        $this->setAdminUser();
        $s->optionobj->user_submit_response(
            $s->person2,
            0,
            0,
            MOD_BOOKING_BO_SUBMIT_STATUS_UN_CONFIRM,
            MOD_BOOKING_VERIFIED
        );

        $this->assert_passed_on_immediately($s, 'declines the offer');
    }

    /**
     * Person 2 leaves the waiting list (own cancellation, or removed by an admin) while holding the
     * offer -> person 3 is offered immediately.
     *
     * @param bool $actorisparticipant true: person 2 cancels themselves, false: an admin removes them
     *
     * @dataProvider leave_provider
     */
    public function test_offer_of_a_user_leaving_the_waitlist_is_passed_on_to_next_person_immediately(
        bool $actorisparticipant
    ): void {
        $s = $this->build_scenario();

        if ($actorisparticipant) {
            $this->setUser($s->person2);
        } else {
            $this->setAdminUser();
        }
        $s->optionobj->user_delete_response($s->person2->id);
        singleton_service::destroy_booking_option_singleton($s->option->id);
        singleton_service::destroy_booking_answers($s->option->id);
        $this->setAdminUser();

        $this->assert_passed_on_immediately($s, 'leaves the waiting list');
    }

    /**
     * Who removes person 2 from the waiting list.
     *
     * @return array
     */
    public static function leave_provider(): array {
        return [
            'participant cancels themselves' => [true],
            'admin removes the participant' => [false],
        ];
    }

    /**
     * Leaving the waiting list is not a refusal: person 2 leaves (their offer is skipped, person 3
     * gets it), joins again later and must be offered again once person 3 does not pay either.
     * Guards the lock side effect - a K7/K4 lock written for someone who left would silently
     * exclude them from every later offer - and the stale expiry task of the skipped offer.
     */
    public function test_user_who_left_the_waitlist_can_rejoin_and_is_offered_again(): void {
        global $DB;

        $s = $this->build_scenario();
        $optionid = (int) $s->option->id;

        // Person 2 cancels themselves -> person 3 holds the offer (see the tests above).
        $this->setUser($s->person2);
        $s->optionobj->user_delete_response($s->person2->id);
        singleton_service::destroy_booking_option_singleton($s->option->id);
        singleton_service::destroy_booking_answers($s->option->id);
        $this->setAdminUser();

        $this->assertInstanceOf(
            skipped::class,
            $s->repository->get_offer_by_id((int) $s->offer2->id)->status,
            'Person 2\'s offer must end as "skipped" (they left), not as declined or expired.'
        );
        $this->assertFalse(
            $s->repository->is_permanently_declined($optionid, (int) $s->person2->id),
            'Leaving the waiting list is not a refusal - no lock may be written for person 2.'
        );
        $openoffers = $s->repository->get_open_offers($optionid);
        $this->assertCount(1, $openoffers, 'Precondition: person 3 holds the one open offer.');
        $offer3 = reset($openoffers);
        $this->assertEquals((int) $s->person3->id, (int) $offer3->userid, 'Precondition: the offer is person 3\'s.');

        // Person 2 joins the waiting list again.
        $this->setUser($s->person2);
        singleton_service::destroy_user($s->person2->id);
        booking_bookit::bookit('option', $optionid, $s->person2->id);
        booking_bookit::bookit('option', $optionid, $s->person2->id);
        $this->setAdminUser();
        $this->assertNotEmpty(
            $DB->get_record('booking_answers', [
                'optionid' => $optionid,
                'userid' => $s->person2->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]),
            'Precondition: person 2 must be on the waiting list again after joining.'
        );
        $this->assertCount(
            1,
            $s->repository->get_open_offers($optionid),
            'Joining again must not create a second offer while person 3 holds the only seat.'
        );

        // Person 3 does not pay either: their interval runs out.
        $s->clock->set_to((int) $offer3->expiresat + 1);
        ob_start();
        $this->runAdhocTasks('\mod_booking\task\expire_waitlist_offer_adhoc');
        ob_get_clean();
        $this->setAdminUser();

        $this->assertInstanceOf(
            expired::class,
            $s->repository->get_offer_by_id((int) $offer3->id)->status,
            'Person 3\'s unpaid offer must be expired.'
        );
        $this->assertInstanceOf(
            skipped::class,
            $s->repository->get_offer_by_id((int) $s->offer2->id)->status,
            'The stale expiry task of person 2\'s old offer must be a no-op and leave it "skipped".'
        );

        $openoffers = $s->repository->get_open_offers($optionid);
        $this->assertCount(1, $openoffers, 'After person 3\'s expiry exactly one new open offer is expected.');
        $newoffer = reset($openoffers);
        $this->assertEquals(
            (int) $s->person2->id,
            (int) $newoffer->userid,
            'Person 2 rejoined without a lock and is the only one waiting - they must be offered again.'
        );
        $this->assertEquals(
            2,
            $this->count_offer_messages($s->sink->get_messages(), (int) $s->person2->id),
            'Person 2 must have received two payment requests: the first offer and the one after rejoining.'
        );
        $this->assertEquals(
            1,
            $this->count_offer_messages($s->sink->get_messages(), (int) $s->person3->id),
            'Person 3 must have received exactly one payment request.'
        );
        $s->sink->close();
    }

    /**
     * Common assertions: clock untouched (still inside person 2's 24h), person 2's offer no longer
     * holds the seat, person 3 has the one open offer and the notification.
     *
     * @param \stdClass $s scenario from build_scenario()
     * @param string $what what person 2 did, for the failure messages
     * @return void
     */
    private function assert_passed_on_immediately(\stdClass $s, string $what): void {
        $this->assertLessThan(
            (int) $s->offer2->expiresat,
            $s->clock->time(),
            'Precondition: the clock must not have reached person 2\'s expiry - this test is about ' .
            'passing the offer on BEFORE the interval is over.'
        );

        $offer2 = $s->repository->get_offer_by_id((int) $s->offer2->id);
        $this->assertNotInstanceOf(
            offered::class,
            $offer2->status,
            "Person 2 $what, so their offer must be closed at once. An offer that stays open keeps " .
            'counting against the free capacity (max - booked - open offers) until the interval is over.'
        );

        $openoffers = $s->repository->get_open_offers((int) $s->option->id);
        $this->assertCount(
            1,
            $openoffers,
            "Person 2 $what: the seat is free again, so exactly one open offer (for person 3) is " .
            'expected right now - not only after the 24h interval.'
        );
        $offer3 = reset($openoffers);
        $this->assertEquals((int) $s->person3->id, (int) $offer3->userid, 'The offer must go to person 3.');
        $this->assertEquals(
            $s->clock->time() + self::INTERVALMINUTES * MINSECS,
            (int) $offer3->expiresat,
            'Person 3 gets their own full interval, starting now.'
        );

        $this->assertEquals(
            1,
            $this->count_offer_messages($s->sink->get_messages(), (int) $s->person3->id),
            'Person 3 must be notified (payment request) right away.'
        );
        $s->sink->close();
    }

    /**
     * Builds: one seat, person 1 booked, persons 2 and 3 waiting (all paying), rule interval 24h.
     * Person 1 cancels through the real path, so person 2 holds the one open offer afterwards.
     *
     * @return \stdClass option, optionobj, person1..3, offer2, repository, clock, sink
     */
    private function build_scenario(): \stdClass {
        $clock = $this->mock_clock_with_frozen(time());

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
            'name' => 'leave-interval-rule',
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
        $record->text = 'offer-released-on-leave';
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

        // Fresh singletons for the action under test (person 2 leaving/declining).
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        return (object) [
            'option' => $option,
            'optionobj' => $optionobj,
            'person1' => $person1,
            'person2' => $person2,
            'person3' => $person3,
            'offer2' => $offer2,
            'repository' => $repository,
            'clock' => $clock,
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
