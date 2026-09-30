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
 * A paid delivery for a user who is on the waiting list.
 *
 * The duplicate purchase check compares the ids of the answers a user holds before and after the
 * delivery, and counts booked and waiting list answers as held. A waiting list answer is updated
 * in place when the delivery books the user, so its id stays the same. These tests pin what is
 * reported to the cart in that case: a delivery that moved the user from the waiting list to a
 * place did deliver something and must not be credited away.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\shopping_cart\service_provider::delivery_was_already_owned
 */

namespace mod_booking;

use mod_booking\shopping_cart\service_provider;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A paid delivery for a user who is on the waiting list.
 *
 * @covers \mod_booking\shopping_cart\service_provider
 */
final class duplicate_purchase_waitinglist_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        singleton_service::destroy_instance();
    }

    /**
     * Creates a course, a booking instance, two enrolled students and one option.
     *
     * @param array $optionoverrides extra option settings
     * @return array [$optionid, $student, $otherstudent]
     */
    private function create_environment(array $optionoverrides = []): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($otherstudent->id, $course->id, 'student');

        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var \mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object) array_merge([
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Option test',
            'chooseorcreatecourse' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 5,
        ], $optionoverrides));

        singleton_service::destroy_instance();

        return [(int) $option->id, $student, $otherstudent];
    }

    /**
     * Forgets everything this request cached about the option, as a new request would.
     *
     * @param int $optionid
     * @return void
     */
    private function forget(int $optionid): void {
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_booking_answers($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Runs one delivery of the option and returns what we report about it afterwards.
     *
     * @param int $optionid
     * @param int $userid
     * @return array [$delivered, $alreadyowned]
     */
    private function deliver(int $optionid, int $userid): array {
        $delivered = service_provider::successful_checkout('option', $optionid, 1, $userid);
        $alreadyowned = service_provider::delivery_was_already_owned('option', $optionid, $userid);

        $this->forget($optionid);

        return [$delivered, $alreadyowned];
    }

    /**
     * The answers of a user for an option, as id => waitinglist status.
     *
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    private function answers(int $optionid, int $userid): array {
        global $DB;

        return array_map(
            'intval',
            $DB->get_records_menu('booking_answers', ['optionid' => $optionid, 'userid' => $userid], 'id', 'id, waitinglist')
        );
    }

    /**
     * Books the other student into the only place and puts the student on the waiting list.
     *
     * @param int $optionid
     * @param stdClass $student
     * @param stdClass $otherstudent
     * @return int the id of the student's waiting list answer
     */
    private function put_student_on_waitinglist(int $optionid, stdClass $student, stdClass $otherstudent): int {
        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($otherstudent, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);

        $answers = $this->answers($optionid, (int) $student->id);
        $this->assertCount(1, $answers, 'Precondition: the student has exactly one answer.');
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            reset($answers),
            'Precondition: the option is full, so the student is on the waiting list.'
        );

        return (int) array_key_first($answers);
    }

    /**
     * A place became free, the student is still on the waiting list and has no reservation, and
     * their payment arrives. The delivery books them, so it must not be reported as already owned.
     *
     * @return void
     */
    public function test_a_delivery_that_books_a_waitlisted_user_is_not_reported_as_already_owned(): void {
        global $DB;

        [$optionid, $student, $otherstudent] = $this->create_environment();
        $waitinglistanswerid = $this->put_student_on_waitinglist($optionid, $student, $otherstudent);

        // The place becomes free, without anybody moving up from the waiting list yet.
        $DB->delete_records('booking_answers', ['optionid' => $optionid, 'userid' => $otherstudent->id]);
        $this->forget($optionid);

        [$delivered, $alreadyowned] = $this->deliver($optionid, (int) $student->id);

        $this->assertTrue($delivered);
        $this->assertSame(
            [$waitinglistanswerid => MOD_BOOKING_STATUSPARAM_BOOKED],
            $this->answers($optionid, (int) $student->id),
            'The delivery booked the student, by updating their waiting list answer in place.'
        );
        $this->assertFalse(
            $alreadyowned,
            'The payment gave the student a place they did not have, so it is a sale and not a duplicate.'
        );
    }

    /**
     * The option asks for confirmation, so the student waits on the waiting list although places
     * are free. Their payment arrives without a reservation and books them.
     *
     * @return void
     */
    public function test_a_delivery_that_books_a_user_waiting_for_confirmation_is_not_reported_as_already_owned(): void {
        [$optionid, $student] = $this->create_environment([
            'maxanswers' => 10,
            'waitforconfirmation' => 1,
        ]);

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);

        $answers = $this->answers($optionid, (int) $student->id);
        $this->assertSame(
            [MOD_BOOKING_STATUSPARAM_WAITINGLIST],
            array_values($answers),
            'Precondition: the student waits for confirmation on the waiting list.'
        );
        $waitinglistanswerid = (int) array_key_first($answers);

        [$delivered, $alreadyowned] = $this->deliver($optionid, (int) $student->id);

        $this->assertTrue($delivered);
        $this->assertSame(
            [$waitinglistanswerid => MOD_BOOKING_STATUSPARAM_BOOKED],
            $this->answers($optionid, (int) $student->id),
            'The delivery booked the student, by updating their waiting list answer in place.'
        );
        $this->assertFalse(
            $alreadyowned,
            'The payment gave the student a place they did not have, so it is a sale and not a duplicate.'
        );
    }

    /**
     * The usual way: the waitlisted student puts the option into the cart, which turns their
     * answer into a reservation, and then pays. This is a plain sale.
     *
     * @return void
     */
    public function test_a_waitlisted_user_who_reserves_and_pays_is_not_reported_as_already_owned(): void {
        global $DB;

        [$optionid, $student, $otherstudent] = $this->create_environment();
        $this->put_student_on_waitinglist($optionid, $student, $otherstudent);

        $DB->delete_records('booking_answers', ['optionid' => $optionid, 'userid' => $otherstudent->id]);
        $this->forget($optionid);

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($student, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_ADDED_TO_CART, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);

        $this->assertSame(
            [MOD_BOOKING_STATUSPARAM_RESERVED],
            array_values($this->answers($optionid, (int) $student->id)),
            'Precondition: putting the option into the cart reserved it.'
        );

        [$delivered, $alreadyowned] = $this->deliver($optionid, (int) $student->id);

        $this->assertTrue($delivered);
        $this->assertSame(
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            array_values($this->answers($optionid, (int) $student->id))
        );
        $this->assertFalse($alreadyowned);
    }

    /**
     * The option is still full when the payment of a waitlisted student arrives. They stay on the
     * waiting list, so the payment delivered nothing and is reported as such.
     *
     * @return void
     */
    public function test_a_delivery_that_leaves_the_user_on_the_waitinglist_is_reported_as_already_owned(): void {
        [$optionid, $student, $otherstudent] = $this->create_environment();
        $waitinglistanswerid = $this->put_student_on_waitinglist($optionid, $student, $otherstudent);

        [, $alreadyowned] = $this->deliver($optionid, (int) $student->id);

        $this->assertSame(
            [$waitinglistanswerid => MOD_BOOKING_STATUSPARAM_WAITINGLIST],
            $this->answers($optionid, (int) $student->id),
            'The option is full, so the student stays on the waiting list.'
        );
        $this->assertTrue($alreadyowned, 'The payment did not give the student a place.');
    }

    /**
     * The cart was emptied while the user was paying, so the reservation is gone when the payment
     * arrives. The option still has a free place: the delivery books the user and it is a sale.
     *
     * @return void
     */
    public function test_a_late_payment_after_the_cart_was_emptied_books_the_user_and_is_a_sale(): void {
        [$optionid, $student] = $this->create_environment(['maxanswers' => 10]);

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($student, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_ADDED_TO_CART, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);
        $this->assertSame(
            [MOD_BOOKING_STATUSPARAM_RESERVED],
            array_values($this->answers($optionid, (int) $student->id)),
            'Precondition: the option is reserved in the cart.'
        );

        service_provider::unload_cartitem('option', $optionid, (int) $student->id);
        $this->forget($optionid);
        $this->assertNotContains(
            MOD_BOOKING_STATUSPARAM_RESERVED,
            $this->answers($optionid, (int) $student->id),
            'Precondition: emptying the cart removed the reservation.'
        );

        [$delivered, $alreadyowned] = $this->deliver($optionid, (int) $student->id);

        $this->assertTrue($delivered);
        $this->assertContains(MOD_BOOKING_STATUSPARAM_BOOKED, $this->answers($optionid, (int) $student->id));
        $this->assertFalse($alreadyowned);
    }

    /**
     * The same late payment, but the last place was taken by somebody else in the meantime.
     * This pins what happens today: the answers of the user and what is reported to the cart.
     *
     * @return void
     */
    public function test_a_late_payment_after_the_cart_was_emptied_on_a_full_option(): void {
        [$optionid, $student, $otherstudent] = $this->create_environment();

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($student, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_ADDED_TO_CART, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);
        service_provider::unload_cartitem('option', $optionid, (int) $student->id);
        $this->forget($optionid);

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($otherstudent, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);

        [$delivered, $alreadyowned] = $this->deliver($optionid, (int) $student->id);

        $this->assertSame(
            ['delivered' => true, 'alreadyowned' => false, 'answers' => [MOD_BOOKING_STATUSPARAM_WAITINGLIST]],
            [
                'delivered' => $delivered,
                'alreadyowned' => $alreadyowned,
                'answers' => array_values(array_diff(
                    $this->answers($optionid, (int) $student->id),
                    [MOD_BOOKING_STATUSPARAM_DELETED, MOD_BOOKING_STATUSPARAM_NOTBOOKED]
                )),
            ]
        );
    }

    /**
     * The same late payment on an option without waiting list places, after the last place was
     * taken by somebody else: nothing can be delivered, so the cart is told that the delivery
     * failed and gives the money back.
     *
     * @return void
     */
    public function test_a_late_payment_on_a_full_option_without_waitinglist_is_not_delivered(): void {
        [$optionid, $student, $otherstudent] = $this->create_environment(['maxoverbooking' => 0]);

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($student, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_ADDED_TO_CART, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);
        service_provider::unload_cartitem('option', $optionid, (int) $student->id);
        $this->forget($optionid);

        $option = booking_option::create_option_from_optionid($optionid);
        $option->user_submit_response($otherstudent, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $this->forget($optionid);

        $sink = $this->redirectEvents();
        [$delivered, $alreadyowned] = $this->deliver($optionid, (int) $student->id);
        $failed = array_filter($sink->get_events(), fn($event) => $event instanceof \mod_booking\event\booking_failed);
        $sink->close();

        $this->assertFalse($delivered, 'There is no place and no waiting list, so nothing is delivered.');
        $this->assertFalse($alreadyowned, 'A failed delivery is not a duplicate.');
        $this->assertCount(1, $failed, 'The failed booking is logged.');
        $this->assertEmpty(
            array_intersect(
                $this->answers($optionid, (int) $student->id),
                [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_WAITINGLIST]
            ),
            'The student holds neither a place nor a waiting list position.'
        );
    }
}
