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
use mod_booking\local\connectedcourse;
use mod_booking\option\dates_handler;
use local_entities\entitiesrelation_handler;
use context_system;
use context_module;
use core_course_category;
use stdClass;
use tool_mocktesttime\time_mock;


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
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Test update of bookig option and tracking changes.
     *
     * @covers \mod_booking\event\teacher_added
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base::check_for_changes
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_option_changes(array $bdata): void {

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
        unset($bdata['completion']);

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
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');

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
        $record->coursestarttime_0 = strtotime('10 April 2055');
        $record->courseendtime_0 = strtotime('10 May 2055');
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
    }

    /**
     * Test adding of entiy to the bookig option.
     *
     * @covers \mod_booking\booking_option::update
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_option_entities(array $bdata): void {

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
        unset($bdata['completion']);

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');

        // Create entities.
        $entitydata1 = [
            'name' => 'Entity1',
            'shortname' => 'entity1',
            'description' => 'Ent1desc',
        ];
        $entitydata2 = [
            'name' => 'Entity2',
            'shortname' => 'entity2',
            'description' => 'Ent2desc',
        ];

        /** @var local_entities_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid1 = $plugingenerator->create_entities($entitydata1);
        $entityid2 = $plugingenerator->create_entities($entitydata2);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-entity';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Deskr-entity';
        $record->teachersforoption = $user1->username;
        $record->local_entities_entityid_0 = $entityid1; // Option entity.
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 June 2050');
        $record->courseendtime_1 = strtotime('20 July 2050');
        $record->er_saverelationsforoptiondates = 1;
        $record->local_entities_entityarea_1 = "optiondate";
        $record->local_entities_entityid_1 = $entityid2; // Option date entity.

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->assertEquals($entitydata1['name'], $settings->location);

        // Get entity for the 1st (and only) option's date and verify it.
        $handler = new entitiesrelation_handler('mod_booking', $record->local_entities_entityarea_1);
        $session = reset($settings->sessions);
        $entity = $handler->get_instance_data($session->optiondateid);

        $this->assertEquals($entitydata2['name'], $entity->name);
        $this->assertEquals($entitydata2['description'], $entity->description);
        $this->assertEquals($entity->instanceid, $session->optiondateid);
    }

    /**
     * Test delete responses.
     *
     * @covers \mod_booking\booking_option::delete_responses_activitycompletion
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_delete_responses_activitycompletion(array $bdata): void {
        global $DB, $CFG;

        $CFG->enablecompletion = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user3->username;
        $bdata['ratings'] = 3; // Option completed.

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $result = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.module, cm.instance, m.name
                FROM {course_modules} cm LEFT JOIN {modules} m ON m.id = cm.module WHERE cm.course = ?
                AND cm.completion > 0 LIMIT 1',
            [$course->id]
        );

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
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now - 2 day');
        $record->courseendtime_0 = strtotime('now + 1 day');
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 2 day');
        $record->courseendtime_1 = strtotime('now + 3 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        // Required to solve cahce issue.
        singleton_service::destroy_booking_option_singleton($option1->id);

        $bookingobj1 = singleton_service::get_instance_of_booking_by_bookingid($booking1->id);
        $bookingsettings1 = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingobj1->id);
        $bookingoption1 = singleton_service::get_instance_of_booking_option($bookingsettings1->cmid, $option1->id);
        $bookinganswers1 = booking_answers::get_instance_from_optionid($bookingoption1->id);

        $this->setUser($user1);
        $this->assertEquals(false, $bookingoption1->can_rate());
        $this->assertEquals(0, $bookinganswers1->is_activity_completed($user1->id));

        // In this test, we book the user directly (option already started).
        $this->setAdminUser();
        $bookingoption1->user_submit_response($user1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $this->setUser($user1);
        $this->assertEquals(false, $bookingoption1->can_rate());

        // In this test, we set completion to the user directly.
        $this->setAdminUser();
        $sink = $this->redirectEvents();
        booking_activitycompletion([$user1->id], $booking1, $bookingsettings1->cmid, $bookingoption1->id);
        $events = $sink->get_events();

        // Mandatory to get updates on completion.
        $bookinganswers1 = booking_answers::get_instance_from_optionid($bookingoption1->id);

        // Verify completion.
        $this->assertEquals(1, $bookinganswers1->is_activity_completed($user1->id));
        // Verify can_rate.
        $this->setUser($user1);
        $this->assertEquals(true, $bookingoption1->can_rate());

        // Delete responses and verivy absence of completion.
        $this->setAdminUser();
        $res = $bookingoption1->delete_responses_activitycompletion();
        $bookinganswers1 = booking_answers::get_instance_from_optionid($bookingoption1->id);

        // Verify absence completion and inability to rate from user's side.
        $this->setUser($user1);
        $this->assertEquals(0, $bookinganswers1->is_activity_completed($user1->id));
        $this->assertEquals(false, $bookingoption1->can_rate());
    }

    /**
     * Data provider for booking_option_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking 1',
            'eventtype' => 'Test event',
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
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
