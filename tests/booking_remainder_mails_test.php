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
 * Tests for booking remainder mails.
 *
 * @package mod_booking
 * @category test
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2024 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\option\dates_handler;
use mod_booking\task\send_reminder_mails;
use mod_booking\teachers_handler;
use mod_booking\price;
use mod_booking_generator;
use context_course;
use stdClass;
use mod_booking\utils\csv_import;
use mod_booking\importer\bookingoptionsimporter;

/**
 * Class handling tests for booking remainder mails.
 *
 * @package mod_booking
 * @category test
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_remainder_mails_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Tear Down.
     *
     * @return void
     *
     */
    public function tearDown(): void {
    }

    /**
     * Test delete responses.
     *
     * @covers ::delete_responses_activitycompletion
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_send_teacher_remaider() {
        global $DB, $CFG;

        $this->resetAfterTest();
        // It is important to set timezone to have all dates correct!
        $this->setTimezone('Europe/London');

        $CFG->enablecompletion = 1;

        $bdata = ['name' => 'Test Booking 1', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];

        // Spoecific setting to notify teachers.
        $bdata['daystonotifyteachers'] = 1;
        $bdata['notifyemailteachers'] = 'Your booking will start soon:
            {bookingdetails} You have {numberparticipants} booked participants';

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user(s).
        $useremails = ['booking.student@univie.ac.at', 'booking.teacher@univie.ac.at', 'booking.manager@univie.ac.at'];
        $userdata = new stdClass;
        $userdata->email = $useremails[0];
        $userdata->timezone = 'Europe/London';
        $user1 = $this->getDataGenerator()->create_user($userdata);
        $userdata->email = $useremails[1];
        $user2 = $this->getDataGenerator()->create_user($userdata); // Booking teacher.
        $userdata->email = $useremails[2];
        $user3 = $this->getDataGenerator()->create_user($userdata); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user3->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setUser($user3);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id);

        $coursectx = context_course::instance($course->id);

        $time = new \DateTimeImmutable('now', new \DateTimeZone('Europe/London'));
        $twodaysbefore = $time->modify('-2 day');
        $threedaysbefore = $time->modify('-3 day');
        $fourdaysbefore = $time->modify('-4 day');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = $fourdaysbefore->getTimestamp();
        $record->courseendtime_1 = $threedaysbefore->getTimestamp();
        $record->optiondateid_2 = "0";
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = $threedaysbefore->getTimestamp();
        $record->courseendtime_2 = $twodaysbefore->getTimestamp();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $teacherhandler = new teachers_handler($option1->id);
        $teacherdata = new stdClass;
        $teacherdata->teachersforoption = [$user2->id];
        $teacherhandler->save_from_form($teacherdata);

        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);

        $bookingoption1 = singleton_service::get_instance_of_booking_option($cmb1->id, $option1->id);
        $dates = dates_handler::return_array_of_sessions_datestrings($option1->id);

        // Run the send_reminder_mails scheduled task.
        //ob_start();
        $reminder = new send_reminder_mails();
        $reminder->execute();
        //ob_get_clean();
    }
}
