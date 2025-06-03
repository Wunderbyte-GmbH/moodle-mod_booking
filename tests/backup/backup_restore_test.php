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

namespace mod_booking;

use advanced_testcase;
use backup_controller;
use restore_controller;
use backup;
use stdClass;
use context_system;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Test restoring of bookkings with options into another course.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \backup_booking_activity_structure_step
 * @covers \restore_booking_activity_structure_step
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Restore a quiz twice into the same target course, and verify the quiz uses the restored questions both times.
     *
     * @param array $bdata
     * @return void
     *
     * @dataProvider booking_backup_restore_settings_provider
     */
    public function test_backup_restore_bookings_with_options_quiz_into_other_course(array $bdata): void {
        global $DB, $USER;

        $this->setAdminUser();

        singleton_service::destroy_instance();

        // Step 1: Create two courses and a user with editing teacher capabilities.
        $generator = $this->getDataGenerator();
        $course1 = $generator->create_course(['enablecompletion' => 1]);
        $course2 = $generator->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user(); // Booking manager.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $generator->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $generator->enrol_user($teacher->id, $course2->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');

        // Create custom booking field.
        $categorydata = new stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Sport1';
        $fielddata->shortname = 'spt1';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        $bookings = [];
        $options = [];
        // Create 1st booking.
        $bdata['booking']['name'] = 'Test Booking 1';
        $bdata['booking']['course'] = $course1->id;
        $bdata['booking']['bookingmanager'] = $teacher->username;
        $bookings[0] = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        // Create 2nd booking.
        $bdata['booking']['name'] = 'Test Booking 2';
        $bookings[1] = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        // Create options for bookings.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create options for the 1st booking.
        $record = (object)$bdata['options'][0];
        $record->bookingid = $bookings[0]->id;
        $record->text = 'Test Option 11';
        $record->customfield_spt1 = 'chess';
        $options[0] = $plugingenerator->create_option($record);

        $record = (object)$bdata['options'][1];
        $record->bookingid = $bookings[0]->id;
        $record->text = 'Test Option 12';
        $record->customfield_spt1 = 'football';
        $options[1] = $plugingenerator->create_option($record);

        // Create options for the 2nd booking.
        $record = (object)$bdata['options'][0];
        $record->bookingid = $bookings[1]->id;
        $record->text = 'Test Option 21';
        $record->customfield_spt1 = 'tennis';
        $options[2] = $plugingenerator->create_option($record);

        $record = (object)$bdata['options'][1];
        $record->bookingid = $bookings[1]->id;
        $record->text = 'Test Option 22';
        $record->customfield_spt1 = 'tennis';
        $options[3] = $plugingenerator->create_option($record);

        // History item1: book student1 directly into the option.
        $settings = singleton_service::get_instance_of_booking_option_settings($options[0]->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // History item2: Add "dummy" booking_history entry manually.
        $originalhistory = (object)[
            'bookingid' => $bookings[0]->id,
            'optionid' => $options[0]->id,
            'answerid' => null,
            'userid' => $teacher->id,
            'status' => 0,
            'usermodified' => $teacher->id,
            'timecreated' => time(),
            'json' => '{"info":"test"}',
        ];
        $originalhistory->id = $DB->insert_record('booking_history', $originalhistory);

        // Validate booking history.
        $oldhistory = $DB->get_records('booking_history', ['bookingid' => $bookings[0]->id]);
        $this->assertCount(2, $oldhistory);

        // Step 2: Backup the first course.
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course1->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id
        );

        // Include users (and, consequently - booked answers) into the backup.
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('anonymize')->set_value(false);
        $bc->finish_ui();
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Step 3: Import the backup into the second course.
        $rc = new restore_controller(
            $backupid,
            $course2->id,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_CURRENT_ADDING
        );
        // Include users (and, consequently - booked answers) during restore.
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->finish_ui();
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        // Verify bookings and options.
        $bookings2 = get_fast_modinfo($course2->id)->get_instances_of('booking');
        $this->assertCount(2, $bookings2);

        // Validabe 1st booking and its options.
        $booking21 = array_shift($bookings2);
        $this->assertEquals($bookings[0]->name, $booking21->get_name());
        $bookingobj = singleton_service::get_instance_of_booking_by_bookingid((int)$booking21->instance);
        $options2 = $bookingobj->get_all_options();
        $this->assertCount(2, $options2);
        foreach ($options2 as $option2) {
            // In rare cases - order of options could be inverted.
            if ($options[0]->text == $option2->text) {
                $this->assertEquals($options[0]->text, $option2->text);
                $this->assertEquals($options[0]->coursestarttime_0, $option2->coursestarttime);
                $this->assertEquals($options[0]->courseendtime_1, $option2->courseendtime);
                $this->assertEquals($options[0]->customfield_spt1, $option2->spt1);
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($option2->id);
                $sessions = $optionsettings->sessions;
                $this->assertCount(2, $sessions);
                $session1 = array_shift($sessions);
                $this->assertEquals($options[0]->coursestarttime_0, $session1->coursestarttime);
                $this->assertEquals($options[0]->courseendtime_0, $session1->courseendtime);
                $session2 = array_shift($sessions);
                $this->assertEquals($options[0]->coursestarttime_1, $session2->coursestarttime);
                $this->assertEquals($options[0]->courseendtime_1, $session2->courseendtime);
                // Verify answers for the restored option.
                $option20answers = singleton_service::get_instance_of_booking_answers($optionsettings);
                $this->assertCount(1, $option20answers->answers);
                // Verify history items for the restored option.
                $newhistory = $DB->get_records('booking_history', ['bookingid' => $booking21->instance]);
                $this->assertCount(2, $newhistory);
                $historyitem = array_shift($newhistory);
                $this->assertEquals($historyitem->userid, $student1->id);
                $this->assertEquals($historyitem->optionid, $option2->id);
                $this->assertEquals($historyitem->bookingid, $booking21->instance);
                $this->assertEquals($historyitem->status, 0);
                $this->assertEquals($historyitem->json, "[]");
                $historyitem = array_shift($newhistory);
                $this->assertEquals($historyitem->userid, $teacher->id);
                $this->assertEquals($historyitem->optionid, $option2->id);
                $this->assertEquals($historyitem->bookingid, $booking21->instance);
                $this->assertEquals($historyitem->status, 0);
                $this->assertEquals($historyitem->answerid, 0);
                $this->assertEquals($historyitem->json, $originalhistory->json);
            } else {
                $this->assertEquals($options[1]->text, $option2->text);
                $this->assertEquals($options[1]->coursestarttime_0, $option2->coursestarttime);
                $this->assertEquals($options[1]->courseendtime_0, $option2->courseendtime);
                $this->assertEquals($options[1]->customfield_spt1, $option2->spt1);
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($option2->id);
                $this->assertCount(1, $optionsettings->sessions);
            }
        }

        // Validabe 2nd booking and its options.
        $booking22 = array_shift($bookings2);
        $this->assertEquals($bookings[1]->name, $booking22->get_name());
        $bookingobj = singleton_service::get_instance_of_booking_by_bookingid((int)$booking22->instance);
        $options2 = $bookingobj->get_all_options();
        $this->assertCount(2, $options2);
        foreach ($options2 as $option2) {
            // In rare cases - order of options could be inverted.
            if ($options[2]->text == $option2->text) {
                $this->assertEquals($options[2]->coursestarttime_0, $option2->coursestarttime);
                $this->assertEquals($options[2]->courseendtime_1, $option2->courseendtime);
                $this->assertEquals($options[2]->customfield_spt1, $option2->spt1);
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($option2->id);
                $sessions = $optionsettings->sessions;
                $this->assertCount(2, $sessions);
                $session1 = array_shift($sessions);
                $this->assertEquals($options[2]->coursestarttime_0, $session1->coursestarttime);
                $this->assertEquals($options[2]->courseendtime_0, $session1->courseendtime);
                $session2 = array_shift($sessions);
                $this->assertEquals($options[2]->coursestarttime_1, $session2->coursestarttime);
                $this->assertEquals($options[2]->courseendtime_1, $session2->courseendtime);
            } else {
                $this->assertEquals($options[3]->text, $option2->text);
                $this->assertEquals($options[3]->coursestarttime_0, $option2->coursestarttime);
                $this->assertEquals($options[3]->courseendtime_0, $option2->courseendtime);
                $this->assertEquals($options[3]->customfield_spt1, $option2->spt1);
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($option2->id);
                $this->assertCount(1, $optionsettings->sessions);
            }
        }

        singleton_service::destroy_instance();
    }

    /**
     * Data provider for backup_restore_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_backup_restore_settings_provider(): array {
        $bdata = [
            'booking' => [
                'name' => 'Test Booking',
                'eventtype' => 'Test event',
                'enablecompletion' => 1,
                'bookedtext' => ['text' => 'text'],
                'waitingtext' => ['text' => 'text'],
                'notifyemail' => ['text' => 'text'],
                'statuschangetext' => ['text' => 'text'],
                'deletedtext' => ['text' => 'text'],
                'pollurltext' => ['text' => 'text'],
                'pollurlteacherstext' => ['text' => 'text'],
                'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
                'tags' => '',
                'completion' => 2,
                'cancancelbook' => 0,
                'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
            ],
            'options' => [
                // Option 1 with 2 sessions.
                0 => [
                    'text' => 'Test Option 1',
                    'courseid' => 0,
                    'maxanswers' => 2,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('20 May 2050 15:00'),
                    'courseendtime_0' => strtotime('20 June 2050 14:00'),
                    'optiondateid_1' => "0",
                    'daystonotify_1' => "0",
                    'coursestarttime_1' => strtotime('20 June 2050 15:00'),
                    'courseendtime_1' => strtotime('20 July 2050 14:00'),
                ],
                // Option 2 with single session.
                1 => [
                    'text' => 'Test Option 2',
                    'courseid' => 0,
                    'maxanswers' => 4,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('20 July 2050 15:00'),
                    'courseendtime_0' => strtotime('20 August 2050 14:00'),
                ],
            ],
        ];

        return ['bdata' => [$bdata]];
    }
}
