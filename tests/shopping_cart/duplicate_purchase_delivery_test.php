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
 * Tells a duplicate delivery apart from a legitimate repeated purchase.
 *
 * One call of the cart's checkout creates one identifier, so a user can pay two checkouts of the
 * same cart. The second payment reaches us as a second delivery of an option the user already
 * holds, and nothing is booked for it. The cart asks us about exactly that, so that it can give
 * the money back as credit instead of recording a second sale. A repeated purchase that the option
 * does allow - book again is due, or another slot is free - is a real delivery and must not be
 * mistaken for it.
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
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tells a duplicate delivery apart from a legitimate repeated purchase.
 *
 * @covers \mod_booking\shopping_cart\service_provider
 */
final class duplicate_purchase_delivery_test extends booking_advanced_testcase {
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
     * Creates a course, a booking instance, an enrolled student and one option.
     *
     * @param array $optionoverrides extra option settings, for example multiplebookings
     * @return array [$optionid, $userid]
     */
    private function create_environment(array $optionoverrides = []): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

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
            'maxanswers' => 10,
        ], $optionoverrides));

        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id];
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

        singleton_service::destroy_booking_answers($optionid);
        singleton_service::destroy_instance();

        return [$delivered, $alreadyowned];
    }

    /**
     * The first delivery gives the user their place, so nothing is owned beforehand.
     *
     * @return void
     */
    public function test_a_first_delivery_is_not_reported_as_already_owned(): void {
        [$optionid, $userid] = $this->create_environment();

        [$delivered, $alreadyowned] = $this->deliver($optionid, $userid);

        $this->assertTrue($delivered);
        $this->assertFalse($alreadyowned, 'The user did not hold this option before.');
    }

    /**
     * Paying a second checkout of the same cart delivers nothing and has to say so.
     *
     * @return void
     */
    public function test_a_second_delivery_of_the_same_option_is_reported_as_already_owned(): void {
        global $DB;

        [$optionid, $userid] = $this->create_environment();

        $this->deliver($optionid, $userid);
        [$delivered, $alreadyowned] = $this->deliver($optionid, $userid);

        $this->assertTrue($delivered, 'The component still reports a success, nothing went wrong.');
        $this->assertTrue($alreadyowned, 'The second payment delivered nothing.');

        $this->assertEquals(
            1,
            $DB->count_records('booking_answers', [
                'optionid' => $optionid,
                'userid' => $userid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]),
            'The user must not be booked twice.'
        );
    }

    /**
     * A book again that is due is a real delivery and must not be credited away.
     *
     * @return void
     */
    public function test_a_due_book_again_is_not_reported_as_already_owned(): void {
        global $DB;

        // Mode "after duration" with a wait of zero: the gate is due right after the first booking.
        [$optionid, $userid] = $this->create_environment([
            'multiplebookings' => 1,
            'allowtobookagainafter' => 0,
        ]);

        $this->deliver($optionid, $userid);
        [$delivered, $alreadyowned] = $this->deliver($optionid, $userid);

        $this->assertTrue($delivered);
        $this->assertFalse($alreadyowned, 'A book again that is due delivers a new answer.');

        $this->assertEquals(
            1,
            $DB->count_records('booking_answers', [
                'optionid' => $optionid,
                'userid' => $userid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]),
            'The new answer is the booked one.'
        );
        $this->assertEquals(
            1,
            $DB->count_records('booking_answers', [
                'optionid' => $optionid,
                'userid' => $userid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
            ]),
            'The previous answer is kept as history, so a second answer was really inserted.'
        );
    }

    /**
     * A book again that is not due yet delivers nothing, exactly like a duplicate.
     *
     * @return void
     */
    public function test_a_book_again_that_is_not_due_is_reported_as_already_owned(): void {
        [$optionid, $userid] = $this->create_environment([
            'multiplebookings' => 1,
            'allowtobookagainafter' => 99999,
        ]);

        $this->deliver($optionid, $userid);
        [, $alreadyowned] = $this->deliver($optionid, $userid);

        $this->assertTrue($alreadyowned);
    }

    /**
     * The answer is read once. A second question about the same delivery must not repeat it.
     *
     * @return void
     */
    public function test_the_answer_belongs_to_one_delivery_only(): void {
        [$optionid, $userid] = $this->create_environment();

        service_provider::successful_checkout('option', $optionid, 1, $userid);
        service_provider::successful_checkout('option', $optionid, 1, $userid);

        $this->assertTrue(service_provider::delivery_was_already_owned('option', $optionid, $userid));
        $this->assertFalse(
            service_provider::delivery_was_already_owned('option', $optionid, $userid),
            'Without a delivery of its own, nothing is claimed.'
        );
    }

    /**
     * The event of the cart can be chosen in a rule.
     *
     * @return void
     */
    public function test_the_duplicate_purchase_event_can_be_chosen_in_a_rule(): void {
        if (!class_exists('\local_shopping_cart\event\duplicate_purchase')) {
            $this->markTestSkipped('local_shopping_cart with the duplicate_purchase event is not installed.');
        }

        $mform = new MoodleQuickForm('rule', 'post', '');
        $repeateloptions = [];

        $rule = new \mod_booking\booking_rules\rules\rule_react_on_event();
        $rule->add_rule_to_mform($mform, $repeateloptions);

        $options = $mform->getElement('rule_react_on_event_event')->_options;

        $keys = [];
        foreach ($options as $option) {
            $keys[] = $option['attr']['value'] ?? null;
        }

        $this->assertContains(
            '\local_shopping_cart\event\duplicate_purchase',
            $keys,
            'A rule has to be able to react on a duplicate purchase.'
        );
    }
}
