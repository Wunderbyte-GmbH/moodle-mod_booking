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
 * Tests for the "Send message to teacher(s)" modal form.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use advanced_testcase;
use mod_booking\output\booked_users;
use mod_booking\singleton_service;
use mod_booking\teachers_handler;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests for the "Send message to teacher(s)" modal form.
 *
 * @covers \mod_booking\form\modal_send_message_to_teachers
 */
final class modal_send_message_to_teachers_test extends advanced_testcase {
    /**
     * The recipient pool and the preselection are the teachers of the booking option
     * (not the booked users), and the option scope of the bookings tracker provides
     * the matching action button.
     */
    public function test_teachers_are_recipients_and_preselected(): void {
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        foreach ([$teacher1, $teacher2, $student] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => 'admin',
        ]);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Teacher message option',
            'importing' => 1,
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cmid = $settings->cmid;

        // Subscribe two teachers to the option.
        $teacherhandler = new teachers_handler($option->id);
        $teacherhandler->subscribe_teacher_to_booking_option($teacher1->id, $option->id, $cmid);
        $teacherhandler->subscribe_teacher_to_booking_option($teacher2->id, $option->id, $cmid);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'bookingoptionsettings')->purge();

        // Book the student, so we can verify that booked users are NOT in the recipient pool.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $ajaxdata = ['cmid' => $cmid, 'optionid' => $option->id, 'checkedids' => ''];
        $form = new modal_send_message_to_teachers(null, null, 'post', '', [], true, $ajaxdata);

        // 1. The recipient pool contains exactly the teachers, not the booked student.
        $rm = new ReflectionMethod($form, 'get_possible_recipients_for_custom_message');
        $recipients = $rm->invoke($form, $option->id);
        $this->assertEqualsCanonicalizing([$teacher1->id, $teacher2->id], array_keys($recipients));
        $this->assertArrayNotHasKey($student->id, $recipients);

        // 2. All teachers of the option are preselected (instead of the checked users of the table).
        $form->set_data_for_dynamic_submission();
        $rp = new ReflectionProperty($form, '_form');
        $mform = $rp->getValue($form);
        $selected = array_map('intval', (array)$mform->getElement('selecteduserids')->getValue());
        $this->assertEqualsCanonicalizing([(int)$teacher1->id, (int)$teacher2->id], $selected);

        // 3. The option scope of the bookings tracker provides the action button - without
        // requiring any rows to be checked - next to the "Send custom message" button.
        $bookedusers = new booked_users('option', $option->id);
        $table = $bookedusers->return_raw_table('option', $option->id, MOD_BOOKING_STATUSPARAM_BOOKED);
        $teacherbuttons = array_values(array_filter(
            $table->actionbuttons,
            fn($button) => ($button['formname'] ?? '') === 'mod_booking\form\modal_send_message_to_teachers'
        ));
        $this->assertCount(1, $teacherbuttons);
        $this->assertFalse($teacherbuttons[0]['selectionmandatory']);
        $this->assertSame(get_string('sendmessagetoteachers', 'mod_booking'), $teacherbuttons[0]['label']);
        $custommsgbuttons = array_filter(
            $table->actionbuttons,
            fn($button) => ($button['formname'] ?? '') === 'mod_booking\form\modal_send_custom_message'
        );
        $this->assertCount(1, $custommsgbuttons);

        // 4. Messages to teachers never fire the custom_bulk_message_sent event -
        // its "share of booked users" semantics don't apply to the teacher pool.
        $rm = new ReflectionMethod($form, 'should_fire_bulk_event');
        $this->assertFalse($rm->invoke($form));
        $parentform = new modal_send_custom_message(null, null, 'post', '', [], true, $ajaxdata);
        $rm = new ReflectionMethod($parentform, 'should_fire_bulk_event');
        $this->assertTrue($rm->invoke($parentform));

        // 5. Options without teachers don't get the button (the custom message button stays).
        $optionwithoutteachers = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Option without teachers',
            'importing' => 1,
        ]);
        $table = $bookedusers->return_raw_table(
            'option',
            $optionwithoutteachers->id,
            MOD_BOOKING_STATUSPARAM_BOOKED
        );
        $teacherbuttons = array_filter(
            $table->actionbuttons,
            fn($button) => ($button['formname'] ?? '') === 'mod_booking\form\modal_send_message_to_teachers'
        );
        $this->assertCount(0, $teacherbuttons);
        $custommsgbuttons = array_filter(
            $table->actionbuttons,
            fn($button) => ($button['formname'] ?? '') === 'mod_booking\form\modal_send_custom_message'
        );
        $this->assertCount(1, $custommsgbuttons);
    }
}
