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
 * End-to-end reproduction of the manual test "Issue 1" (waiting list, employee cancels, student
 * does not pay, next student must be notified).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_rules\rules\rule_react_on_event;
use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\local\waitlist\offer_statuses\offered;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Replays the manual test scenario "Issue 1" 1:1 through the REAL triggers (no direct
 * progression::reconcile() call, unlike waitlist_target_b1..b4):
 *
 * 1. One free seat, waiting list active, three accounts: person 1 (employee), person 2 (student),
 *    person 3 (student).
 * 2. Person 1 books and gets the seat.
 * 3. Person 2 and person 3 join the waiting list.
 * 4. Person 1 cancels -> person 2 moves up and must receive the payment request (offer, message,
 *    confirmation flag).
 * 5. Person 2 does not pay -> once the offer interval has passed, person 3 must be notified and
 *    receive the payment request.
 *
 * Reported actual result: person 3 received the payment request, person 2 never did (missing
 * confirmation and missing notification for person 2). Each assertion message below names the step
 * of the manual test it belongs to, so a failure shows directly where the reported behaviour
 * diverges.
 *
 * Which prices/settings the tester actually used is not known, so the data provider covers the
 * plausible combinations: employee free or paid, no confirmation or waiting-list confirmation with
 * "confirm on notification", and a rule that always applies or only while not fully booked.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\rule_condition_checker::applicable_rules
 * @covers \mod_booking\local\waitlist\moodle_messaging_gateway::notify_offer
 * @covers \mod_booking\task\expire_waitlist_offer_adhoc::execute
 * @runInSeparateProcess
 */
final class waitlist_employee_student_offer_expiry_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /** @var int Offer interval of the rule in minutes. */
    private const INTERVALMINUTES = 60;

    /** @var string Subject of the rule mail, used to recognise offer notifications. */
    private const OFFERSUBJECT = 'offersubj';

    /**
     * Manual test "Issue 1", steps 1-5.
     *
     * @param float $employeeprice price of the "employee" category (students always pay 50)
     * @param int $waitforconfirmation option setting waitforconfirmation
     * @param int $confirmationonnotification option setting confirmationonnotification
     * @param int $condition "Execute when..." condition of the rule (rule_react_on_event::*)
     *
     * @dataProvider scenario_provider
     */
    public function test_issue1_employee_cancels_student_does_not_pay_next_student_is_offered(
        float $employeeprice,
        int $waitforconfirmation,
        int $confirmationonnotification,
        int $condition
    ): void {
        $clock = $this->mock_clock_with_frozen(time());

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();

        // The price category is resolved from this profile field - it has to exist before the users.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $person1 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'employee']);
        $person2 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $person3 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);

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
            'name' => 'employee',
            'identifier' => 'employee',
            'defaultvalue' => $employeeprice,
            'pricecatsortorder' => 1,
        ]);
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 2,
            'name' => 'student',
            'identifier' => 'student',
            'defaultvalue' => 50,
            'pricecatsortorder' => 2,
        ]);

        // Waiting-list progression rule (K11): the only action rule_condition_checker recognises.
        $plugingenerator->create_rule([
            'name' => 'issue1-interval-rule',
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
                'condition' => (string) $condition,
            ]),
        ]);

        // Step 1: one free seat, waiting list active.
        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'issue1-employee-student';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = $waitforconfirmation;
        $record->confirmationonnotification = $confirmationonnotification;
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

        $repository = new db_waitlist_offer_repository();
        $confirmationflagexpected = $waitforconfirmation > 0 && $confirmationonnotification > 0;

        // Step 2: person 1 (employee) books and gets the seat.
        $this->setUser($person1);
        singleton_service::destroy_user($person1->id);
        booking_bookit::bookit('option', $settings->id, $person1->id);
        [$id] = $boinfo->is_available($settings->id, $person1->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $person1->id);
            [$id] = $boinfo->is_available($settings->id, $person1->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            // A paid seat cannot be taken through bookit() alone (price step) - admin override.
            $this->setAdminUser();
            $optionobj->user_submit_response($person1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $this->get_answer((int) $option->id, (int) $person1->id)->waitinglist,
            'Step 2: person 1 (employee) must have got the seat.'
        );

        // Step 3: person 2 and person 3 (students) join the waiting list, in this order.
        foreach ([$person2, $person3] as $user) {
            $this->setUser($user);
            singleton_service::destroy_user($user->id);
            booking_bookit::bookit('option', $settings->id, $user->id);
            booking_bookit::bookit('option', $settings->id, $user->id);
            [$id] = $boinfo->is_available($settings->id, $user->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                'Step 3: both students must end up on the waiting list.'
            );
        }
        $this->setAdminUser();
        $this->assertEmpty(
            $repository->get_open_offers((int) $option->id),
            'Step 3: while the only seat is taken, nobody may have been offered anything.'
        );

        // Step 4: person 1 cancels through the real cancellation path (this is the trigger that
        // must lead to person 2's offer - progression::reconcile() is deliberately not called here).
        $sink = $this->redirectMessages();
        $this->setUser($person1);
        $optionobj->user_delete_response($person1->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        $openoffers = $repository->get_open_offers((int) $option->id);
        $this->assertCount(
            1,
            $openoffers,
            'Step 4: person 1\'s cancellation frees exactly one seat - exactly one open offer expected. ' .
            'Zero offers means the cancellation did not lead to reconcile() at all (e.g. no applicable rule).'
        );
        $offer2 = reset($openoffers);
        $this->assertEquals(
            (int) $person2->id,
            (int) $offer2->userid,
            'Step 4: person 2 (first on the waiting list) must be the one who moves up, not person 3.'
        );
        $this->assertInstanceOf(offered::class, $offer2->status, 'Step 4: the offer must be in state "offered".');
        $this->assertEquals(
            $clock->time() + self::INTERVALMINUTES * MINSECS,
            (int) $offer2->expiresat,
            'Step 4: the offer must expire exactly one rule interval after it was made.'
        );

        $this->assertEquals(
            1,
            $this->count_offer_messages($sink->get_messages(), (int) $person2->id),
            'Step 4: person 2 must receive exactly one payment request (offer message).'
        );
        $this->assertEquals(
            0,
            $this->count_offer_messages($sink->get_messages(), (int) $person3->id),
            'Step 4: person 3 must not be notified yet.'
        );

        $this->assert_still_waiting((int) $option->id, (int) $person2->id, 'Step 4: person 2 must still wait for payment.');
        $this->assert_still_waiting((int) $option->id, (int) $person3->id, 'Step 4: person 3 must still be waiting.');

        // Gate: can person 2 actually act on the offer, and is person 3 still held back?
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        if ($confirmationflagexpected) {
            $this->assertTrue(
                $this->is_confirmed((int) $option->id, (int) $person2->id),
                'Step 4: person 2 must carry the confirmation flag ("Fehlende Bestätigung" in the report).'
            );
            $this->assertFalse(
                $this->is_confirmed((int) $option->id, (int) $person3->id),
                'Step 4: person 3 must not be confirmed while person 2\'s offer is open.'
            );
            [$id2] = $boinfo->is_available($settings->id, $person2->id, true);
            $this->assertNotEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id2,
                'Step 4: person 2 must be released from the waiting-list block so payment is possible.'
            );
            [$id3] = $boinfo->is_available($settings->id, $person3->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id3,
                'Step 4: person 3 must still be blocked by the waiting list.'
            );
        }

        // Step 5: person 2 does not pay. The offer's own deadline passes.
        $clock->set_to((int) $offer2->expiresat + 1);
        ob_start();
        $this->runAdhocTasks('\mod_booking\task\expire_waitlist_offer_adhoc');
        ob_get_clean();
        $this->setAdminUser();

        $expiredoffer = $repository->get_offer_by_id((int) $offer2->id);
        $this->assertInstanceOf(
            expired::class,
            $expiredoffer->status,
            'Step 5: person 2\'s unpaid offer must be expired once its interval is over.'
        );

        $openoffers = $repository->get_open_offers((int) $option->id);
        $this->assertCount(
            1,
            $openoffers,
            'Step 5: after the expiry exactly one new open offer expected, for person 3.'
        );
        $offer3 = reset($openoffers);
        $this->assertEquals(
            (int) $person3->id,
            (int) $offer3->userid,
            'Step 5: person 3 must receive the offer once person 2\'s interval is over.'
        );
        $this->assertInstanceOf(offered::class, $offer3->status);
        $this->assertEquals(
            $clock->time() + self::INTERVALMINUTES * MINSECS,
            (int) $offer3->expiresat,
            'Step 5: person 3\'s own interval starts when their offer is made.'
        );

        $this->assertEquals(
            1,
            $this->count_offer_messages($sink->get_messages(), (int) $person3->id),
            'Step 5: person 3 must receive exactly one payment request.'
        );
        $this->assertEquals(
            1,
            $this->count_offer_messages($sink->get_messages(), (int) $person2->id),
            'Step 5: person 2 must not receive a second request - and, per the report, must have got the first one.'
        );
        if ($confirmationflagexpected) {
            $this->assertTrue(
                $this->is_confirmed((int) $option->id, (int) $person3->id),
                'Step 5: person 3 must carry the confirmation flag once offered.'
            );
        }

        $this->assertTrue(
            $repository->is_permanently_declined((int) $option->id, (int) $person2->id),
            'Step 5: person 2 let the offer expire and must not be offered this seat again.'
        );
        $this->assert_still_waiting((int) $option->id, (int) $person2->id, 'Step 5: person 2 must not be booked without payment.');
        $this->assert_still_waiting((int) $option->id, (int) $person3->id, 'Step 5: person 3 must still wait for payment.');
        $sink->close();
    }

    /**
     * Combinations the tester may have used.
     *
     * @return array
     */
    public static function scenario_provider(): array {
        return [
            'employee free, no confirmation, rule always' => [
                'employeeprice' => 0,
                'waitforconfirmation' => 0,
                'confirmationonnotification' => 0,
                'condition' => rule_react_on_event::ALWAYS,
            ],
            'employee free, waiting-list confirmation on notification, rule always' => [
                'employeeprice' => 0,
                'waitforconfirmation' => 2,
                'confirmationonnotification' => 1,
                'condition' => rule_react_on_event::ALWAYS,
            ],
            'employee paid, waiting-list confirmation on notification, rule always' => [
                'employeeprice' => 80,
                'waitforconfirmation' => 2,
                'confirmationonnotification' => 1,
                'condition' => rule_react_on_event::ALWAYS,
            ],
            'employee free, waiting-list confirmation on notification, rule not fully booked' => [
                'employeeprice' => 0,
                'waitforconfirmation' => 2,
                'confirmationonnotification' => 1,
                'condition' => rule_react_on_event::NOTFULLYBOOKED,
            ],
            'employee paid, waiting-list confirmation on notification, rule not fully booked' => [
                'employeeprice' => 80,
                'waitforconfirmation' => 2,
                'confirmationonnotification' => 1,
                'condition' => rule_react_on_event::NOTFULLYBOOKED,
            ],
        ];
    }

    /**
     * Returns the (most recent) booking answer of a user for an option.
     *
     * @param int $optionid
     * @param int $userid
     * @return \stdClass
     */
    private function get_answer(int $optionid, int $userid): \stdClass {
        global $DB;
        $answers = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid], 'id DESC', '*', 0, 1);
        $this->assertNotEmpty($answers, "User $userid has no booking answer for option $optionid.");
        return reset($answers);
    }

    /**
     * Asserts that the user is still on the waiting list (neither booked nor dropped).
     *
     * @param int $optionid
     * @param int $userid
     * @param string $message
     * @return void
     */
    private function assert_still_waiting(int $optionid, int $userid, string $message): void {
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $this->get_answer($optionid, $userid)->waitinglist,
            $message
        );
    }

    /**
     * Whether the user's waiting-list answer carries the confirmation flag.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    private function is_confirmed(int $optionid, int $userid): bool {
        $json = json_decode($this->get_answer($optionid, $userid)->json ?? '') ?: new \stdClass();
        return !empty($json->confirmwaitinglist);
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
