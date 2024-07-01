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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use context_module;
use stdClass;


/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_option_test extends advanced_testcase {

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
     * Test update of bookig option and tracking changes.
     *
     * @covers \mod_booking\event\teacher_added
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base->check_for_changes
     * @throws \coding_exception
     */
    public function test_option_changes(): void {

        $bdata = ['name' => 'Test Booking', 'eventtype' => 'Test event',
                    'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
                    'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
                    'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
                    'pollurlteacherstext' => ['text' => 'text'],
                    'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
                    'bookingpolicy' => 'bookingpolicy', 'tags' => '',
                    'showviews' => ['showall,showactive,mybooking,myoptions,myinstitution'],
        ];

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $users = [
            ['username' => 'teacher1', 'firstname' => 'Teacher', 'lastname' => '1', 'email' => 'teacher1@example.com'],
            ['username' => 'teacher2', 'firstname' => 'Teacher', 'lastname' => '2', 'email' => 'teacher2@sample.com'],
            ['username' => 'student1', 'firstname' => 'Student', 'lastname' => '1', 'email' => 'student1@sample.com'],
        ];
        $user1 = $this->getDataGenerator()->create_user($users[0]);
        $user2 = $this->getDataGenerator()->create_user($users[1]);
        $user3 = $this->getDataGenerator()->create_user($users[2]);

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user2->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-created';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Deskr-created';
        $record->teachersforoption = $user1->username;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 June 2050');
        $record->courseendtime_1 = strtotime('20 July 2050');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $this->setAdminUser();

        // Trigger and capture events.
        unset_config('noemailever');
        ob_start();
        $sink = $this->redirectEvents();

        // Required to solve cahce issue.
        singleton_service::destroy_user($user1->id);
        singleton_service::destroy_user($user2->id);

        // Update booking option.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->text = 'Option-updated';
        $record->description = 'Deskr-updated';
        $record->limitanswers = 1;
        $record->maxanswers = 5;
        $record->coursestarttime_1 = strtotime('10 April 2055');
        $record->courseendtime_1 = strtotime('10 May 2055');
        $record->teachersforoption = [$user2->id];
        booking_option::update($record);

        // Required to solve cahce issue.
        singleton_service::destroy_booking_option_singleton($option->id);

        $events = $sink->get_events();

        $res = ob_get_clean();
        $sink->close();

        // Last event must be on the option update.
        foreach ($events as $key => $event) {
            if ($event instanceof bookingoption_updated) {
                // Checking that the event contains the expected values.
                $this->assertInstanceOf('mod_booking\event\bookingoption_updated', $event);
                $modulecontext = context_module::instance($settings->cmid);
                $this->assertEquals($modulecontext, $event->get_context());
                $this->assertEventContextNotUsed($event);
                $data = $event->get_data();
                $this->assertIsArray($data);
                $this->assertIsArray($data['other']['changes']);
                $changes = $data['other']['changes'];
                foreach ($changes as $change) {
                    switch ($change['fieldname']) {
                        case 'text':
                            $this->assertEquals('Option-updated', $change['newvalue']);
                            $this->assertEquals('Option-created', $change['oldvalue']);
                            break;
                        case 'description':
                            $this->assertEquals('Deskr-updated', $change['newvalue']);
                            $this->assertEquals('Deskr-created', $change['oldvalue']);
                            break;
                        case 'maxanswers':
                            $this->assertEquals(5, $change['newvalue']);
                            $this->assertEmpty($change['oldvalue']);
                            break;
                        case 'teachers':
                            $this->assertStringContainsString('Teacher 2', $change['newvalue']);
                            $this->assertStringContainsString('Teacher 1', $change['oldvalue']);
                            break;
                        case 'dates':
                            $this->assertEquals(strtotime('10 April 2055'), $change['newvalue'][0]['coursestarttime']);
                            $this->assertEquals(strtotime('10 May 2055'), $change['newvalue'][0]['courseendtime']);
                            $this->assertEquals(strtotime('20 June 2050'), $change['oldvalue'][0]['coursestarttime']);
                            $this->assertEquals(strtotime('20 July 2050'), $change['oldvalue'][0]['courseendtime']);
                            break;
                    }
                }
            }
        }

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option->id);
    }

    /**
     * Test delete responses.
     *
     * @covers ::delete_responses_activitycompletion
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_delete_responses_activitycompletion(): void {
        global $DB, $CFG;

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
        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user3->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $result = $DB->get_record_sql(
                'SELECT cm.id, cm.course, cm.module, cm.instance, m.name
                FROM {course_modules} cm LEFT JOIN {modules} m ON m.id = cm.module WHERE cm.course = ?
                AND cm.completion > 0 LIMIT 1', [$course->id]);

        $bdata['name'] = 'Test Booking 2';
        unset($bdata['completion']);
        unset($bdata['enablecompletion']);
        $bdata['completionmodule'] = $result->id;
        $booking2 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now - 2 day');
        $record->courseendtime_1 = strtotime('now + 1 day');
        $record->optiondateid_2 = "0";
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = strtotime('now + 2 day');
        $record->courseendtime_2 = strtotime('now + 3 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);
        $record->bookingid = $booking2->id;

        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);

        $bookingoption1 = singleton_service::get_instance_of_booking_option($cmb1->id, $option1->id);

        $this->setUser($user1);
        $this->assertEquals(false, $bookingoption1->can_rate());

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option1->id);
    }
}
