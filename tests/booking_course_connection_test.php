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
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use mod_booking\local\connectedcourse;
use context_system;
use core_course_category;
use stdClass;
use tool_mocktesttime\time_mock;


/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_course_connection_test extends advanced_testcase {
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
     * Test enrol user and add to group.
     *
     * @covers \mod_booking\booking_option::enrol_user
     * @covers \mod_booking\local\connectedcourse
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_course_connection_enrollemnt(array $bdata): void {
        global $DB, $CFG;

        // Set params requred for enrolment of responsible contact person in the connected course.
        set_config('responsiblecontactenroltocourse', 1, 'booking');

        // Create a tag.
        $tag1 = $this->getDataGenerator()->create_tag(['name' => 'optiontemplate', 'isstandard' => 1]);

        // Create designated course category.
        $category1 = $this->getDataGenerator()->create_category(['name' => 'BookCat1', 'idnumber' => 'BCAT1']);

        // Setup test courses.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'startdate' => strtotime('now + 2 day')]);
        $course3 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course4 = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'tags' => [$tag1->name]]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teachers = [
            [
                'username' => 'teacher1',
                'firstname' => 'Teacher',
                'lastname' => '1',
                'email' => 'teacher1@example.com',
                'timezone' => 'Europe/London',
            ],
            [
                'username' => 'teacher2',
                'firstname' => 'Teacher',
                'lastname' => '2',
                'email' => 'teacher2@example.com',
                'timezone' => 'Europe/London',
            ],
        ];
        $teacher1 = $this->getDataGenerator()->create_user($teachers[0]);
        $teacher2 = $this->getDataGenerator()->create_user($teachers[1]);
        $teacherids = [$teacher1->id, $teacher2->id];
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.
        $rcps = [
            [
                'username' => 'rcp1',
                'firstname' => 'RCP',
                'lastname' => '1',
                'email' => 'rcp1@example.com',
                'timezone' => 'Europe/London',
            ],
            [
                'username' => 'rcp2',
                'firstname' => 'RCP',
                'lastname' => '2',
                'email' => 'rcp2@example.com',
                'timezone' => 'Europe/London',
            ],
            [
                'username' => 'rcp3',
                'firstname' => 'RCP',
                'lastname' => '2',
                'email' => 'rcp3@example.com',
                'timezone' => 'Europe/London',
            ],
        ];
        $rcp1 = $this->getDataGenerator()->create_user($rcps[0]);
        $rcp2 = $this->getDataGenerator()->create_user($rcps[1]);
        $rcp3 = $this->getDataGenerator()->create_user($rcps[2]);
        $rcpids = [$rcp1->id, $rcp2->id, $rcp3->id];

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $bdata['autoenrol'] = 1; // Required to enroll in the connected course.

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        // Create 1st booking option - existing course, enrol at coursestart.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (enroll on start)';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->enrolmentstatus = 0; // Enrol at coursestart.
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 day');
        $record->courseendtime_0 = strtotime('now + 4 day');
        $record->responsiblecontact = implode(',', [$rcp1->username, $rcp2->username]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid);

        // Create 2nd booking option - existing course, enrol immediately.
        $record->text = 'Test option2 (enroll now)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course3->id;
        $record->enrolmentstatus = 2; // Enrol now.
        $record->responsiblecontact = [];
        $option2 = $plugingenerator->create_option($record);

        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings2->cmid);
        // Enrolments of responsible contacts in the connected courses works only via option update.
        $record->id = $option2->id;
        $record->cmid = $settings2->cmid;
        $record->responsiblecontact = [$rcp1->id, $rcp3->id];
        booking_option::update($record);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);

        // Booking options by the 1st student.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option2->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);

        // Now check if the user is enrolled to the course. We should get two courses.
        $courses = enrol_get_users_courses($student1->id);
        $this->assertEquals(2, count($courses));
        $this->assertEquals(true, in_array('Test course 3', array_column($courses, 'fullname')));
        $this->assertEquals(false, in_array('Test course 2', array_column($courses, 'fullname')));

        // Validate rcp1 enrolments.
        $courses = enrol_get_users_courses($rcp1->id);
        $this->assertEquals(1, count($courses));
        $this->assertEquals(true, in_array('Test course 3', array_column($courses, 'fullname')));
        $this->assertEquals(false, in_array('Test course 2', array_column($courses, 'fullname')));
        $courses = enrol_get_users_courses($rcp2->id);
        $this->assertEquals(0, count($courses));
        $courses = enrol_get_users_courses($rcp3->id);
        $this->assertEquals(1, count($courses));

        // Create 3rd booking option - new empty course, enrol at coursestart.
        $record->text = 'Option3-empty_course-enrol_at_start';
        $record->chooseorcreatecourse = 2;
        $record->enrolmentstatus = 0; // Enrol at coursestart.
        $record->responsiblecontact = implode(',', [$rcp1->username, $rcp2->username]);
        $option3 = $plugingenerator->create_option($record);

        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings3->cmid);

        // Create 4th booking option - new empty course, enrol immediately.
        $record->text = 'Option4-empty_course-enrol_now';
        $record->chooseorcreatecourse = 2;
        $record->enrolmentstatus = 2; // Enroll now.
        $record->responsiblecontact = [];
        $option4 = $plugingenerator->create_option($record);

        $settings4 = singleton_service::get_instance_of_booking_option_settings($option4->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings4->cmid);

        // Enrolments of responsible contacts in the connected courses works only via option update.
        $record->id = $option4->id;
        $record->cmid = $settings4->cmid;
        $record->responsiblecontact = [$rcp1->id, $rcp3->id];
        booking_option::update($record);
        $settings4 = singleton_service::get_instance_of_booking_option_settings($option4->id);

        // Booking options by the 1st student.
        $result = $plugingenerator->create_answer(['optionid' => $option3->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option4->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);

        // Now check if the user is enrolled to the course. We should get three courses.
        $courses = enrol_get_users_courses($student1->id);
        $this->assertEquals(3, count($courses));
        $this->assertEquals(true, in_array('Option4-empty_course-enrol_now', array_column($courses, 'fullname')));
        $this->assertEquals(false, in_array('Option3-empty_course-enrol_at_start', array_column($courses, 'fullname')));

        // Validate rcp1 enrolments.
        $courses = enrol_get_users_courses($rcp1->id);
        $this->assertEquals(2, count($courses));
        $this->assertEquals(true, in_array('Option4-empty_course-enrol_now', array_column($courses, 'fullname')));
        $this->assertEquals(false, in_array('Option3-empty_course-enrol_at_start', array_column($courses, 'fullname')));
        $courses = enrol_get_users_courses($rcp2->id);
        $this->assertEquals(0, count($courses));
        $courses = enrol_get_users_courses($rcp3->id);
        $this->assertEquals(2, count($courses));

        // Create custom booking field category and field.
        $categorydata            = new stdClass();
        $categorydata->name      = 'bookcat';
        $categorydata->component = 'mod_booking';
        $categorydata->area      = 'booking';
        $categorydata->itemid    = 0;
        $categorydata->contextid = context_system::instance()->id;
        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array)$categorydata);
        $bookingcat->save();

        $fielddata                = new stdClass();
        $fielddata->categoryid    = $bookingcat->get('id');
        $fielddata->name       = 'CourseCat';
        $fielddata->shortname  = 'coursecat';
        $fielddata->type = 'text';
        $fielddata->configdata    = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array)$fielddata);
        $bookingfield->save();
        $this->assertTrue(\core_customfield\field::record_exists($bookingfield->get('id')));

        // Set params requred for new course category.
        set_config('newcoursecategorycfield', 'coursecat', 'booking');

        // Create 5th booking option - new empty course, enrol at coursestart.
        $record->text = 'Option5-empty_course_existing_cat-enrol';
        $record->chooseorcreatecourse = 2;
        $record->enrolmentstatus = 2; // Enroll now.
        $record->customfield_coursecat = 'BookCat1';
        $record->responsiblecontact = [];
        $option5 = $plugingenerator->create_option($record);

        $settings5 = singleton_service::get_instance_of_booking_option_settings($option5->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings5->cmid);
        // Enrolments of responsible contacts in the connected courses works only via option update.
        $record->id = $option5->id;
        $record->cmid = $settings5->cmid;
        $record->responsiblecontact = [$rcp1->id, $rcp2->id];
        booking_option::update($record);
        $settings5 = singleton_service::get_instance_of_booking_option_settings($option5->id);

        // Create 6th booking option - new empty course, enrol immediately.
        $record->text = 'Option6-empty_course_new_cat-enrol';
        $record->chooseorcreatecourse = 2;
        $record->enrolmentstatus = 2; // Enroll now.
        $record->customfield_coursecat = 'NewBookCat';
        $record->responsiblecontact = [];
        $option6 = $plugingenerator->create_option($record);

        $settings6 = singleton_service::get_instance_of_booking_option_settings($option6->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings6->cmid);
        // Enrolments of responsible contacts in the connected courses works only via option update.
        $record->id = $option6->id;
        $record->cmid = $settings6->cmid;
        $record->responsiblecontact = [$rcp1->id, $rcp3->id];
        booking_option::update($record);
        $settings6 = singleton_service::get_instance_of_booking_option_settings($option6->id);

        // Booking options by the 1st student.
        $result = $plugingenerator->create_answer(['optionid' => $option5->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option6->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);

        // Now check if the user is enrolled to the course. We should get five courses.
        $courses = enrol_get_users_courses($student1->id);
        $this->assertEquals(5, count($courses));
        $this->assertEquals(true, in_array('Option5-empty_course_existing_cat-enrol', array_column($courses, 'fullname')));
        $this->assertEquals(true, in_array('Option6-empty_course_new_cat-enrol', array_column($courses, 'fullname')));
        $key = array_search('Option5-empty_course_existing_cat-enrol', array_column($courses, 'fullname', 'id'));
        $this->assertEquals($category1->id, (int) $courses[$key]->category);
        $key = array_search('Option6-empty_course_new_cat-enrol', array_column($courses, 'fullname', 'id'));
        $coursecat = core_course_category::get((int) $courses[$key]->category);
        $this->assertEquals('NewBookCat', $coursecat->get_formatted_name());

        // Validate rcp1 enrolments.
        $courses = enrol_get_users_courses($rcp1->id);
        $this->assertEquals(4, count($courses));
        $this->assertEquals(true, in_array('Option5-empty_course_existing_cat-enrol', array_column($courses, 'fullname')));
        $this->assertEquals(true, in_array('Option6-empty_course_new_cat-enrol', array_column($courses, 'fullname')));
        $courses = enrol_get_users_courses($rcp2->id);
        $this->assertEquals(1, count($courses));
        $this->assertEquals(true, in_array('Option5-empty_course_existing_cat-enrol', array_column($courses, 'fullname')));
        $courses = enrol_get_users_courses($rcp3->id);
        $this->assertEquals(3, count($courses));
        $this->assertEquals(false, in_array('Option5-empty_course_existing_cat-enrol', array_column($courses, 'fullname')));
        $this->assertEquals(true, in_array('Option6-empty_course_new_cat-enrol', array_column($courses, 'fullname')));

        // Create page activity under a template course.
        $record1 = new stdClass();
        $record1->name = 'TempPage1';
        $record1->choosintroeorcreatecourse = 'PageDesc1';
        $record1->course = $course4->id;
        $record1->idnumber = 'PAGE1';

        /** @var \mod_page_generator $plugingenerator1 */
        $plugingenerator1 = self::getDataGenerator()->get_plugin_generator('mod_page');
        $page1 = $plugingenerator1->create_instance($record1);

        // Set params requred for new course template.
        set_config('templatetags', $tag1->id, 'booking');

        // Get 1st tagged course.
        $taggedcourses = connectedcourse::return_tagged_template_courses();
        $taggedcourse = reset($taggedcourses);

        // Create 7th booking option - course form template into existing category, enrol at coursestart.
        $record->text = 'Option7-course_template_existing_cat-enrol';
        $record->enrolmentstatus = 2; // Enroll now.
        $record->customfield_coursecat = 'BookCat1';
        $record->responsiblecontact = [];
        $option7 = $plugingenerator->create_option($record);
        $settings7 = singleton_service::get_instance_of_booking_option_settings($option7->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings7->cmid);
        // TODO: We can connect course from template only via updationg of option. Does it a bug?
        $record->id = $option7->id;
        $record->cmid = $settings7->cmid;
        $record->chooseorcreatecourse = 3;
        $record->coursetemplateid = $taggedcourse->id;
        $record->responsiblecontact = [$rcp1->id, $rcp2->id];
        booking_option::update($record);

        // Create 8th booking option - course form template into new category, enrol immediately.
        $record->text = 'Option8-course_template_new_cat-enrol';
        $record->enrolmentstatus = 2; // Enroll now.
        $record->customfield_coursecat = 'TemplateBookCat';
        $record->responsiblecontact = [];
        $option8 = $plugingenerator->create_option($record);
        $settings8 = singleton_service::get_instance_of_booking_option_settings($option8->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings8->cmid);
        // TODO: We can connect course from template only via updationg of option. Does it a bug?
        $record->id = $option8->id;
        $record->cmid = $settings8->cmid;
        $record->chooseorcreatecourse = 3;
        $record->coursetemplateid = $taggedcourse->id;
        $record->responsiblecontact = [$rcp1->id, $rcp3->id];
        booking_option::update($record);

        // Booking options by the 1st student.
        $result = $plugingenerator->create_answer(['optionid' => $option7->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option8->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);

        ob_start();
        $this->runAdhocTasks();
        $res = ob_get_clean();

        // Now check if the user is enrolled to the course. We should get seven courses.
        $courses = enrol_get_users_courses($student1->id);
        $this->assertEquals(7, count($courses));
        $this->assertEquals(true, in_array('Option7-course_template_existing_cat-enrol', array_column($courses, 'fullname')));
        $this->assertEquals(true, in_array('Option8-course_template_new_cat-enrol', array_column($courses, 'fullname')));
        // Verify course 7.
        $key = array_search('Option7-course_template_existing_cat-enrol', array_column($courses, 'fullname', 'id'));
        $this->assertEquals($category1->id, (int) $courses[$key]->category);
        // Ensure "page" activity exist in the course 7.
        $modules = get_fast_modinfo($courses[$key]);
        $instances = $modules->get_instances();
        $this->assertEquals(true, array_key_exists('page', $instances));
        // Verify course 8.
        $key = array_search('Option8-course_template_new_cat-enrol', array_column($courses, 'fullname', 'id'));
        $coursecat = core_course_category::get((int) $courses[$key]->category);
        $this->assertEquals('TemplateBookCat', $coursecat->get_formatted_name());
        // Ensure "page" activity exist in the course 8.
        $modules = get_fast_modinfo($courses[$key]);
        $instances = $modules->get_instances();
        $this->assertEquals(true, array_key_exists('page', $instances));

        // Validate rcp1 enrolments.
        $courses = enrol_get_users_courses($rcp1->id);
        $this->assertEquals(6, count($courses));
        $this->assertEquals(true, in_array('Option7-course_template_existing_cat-enrol', array_column($courses, 'fullname')));
        $this->assertEquals(true, in_array('Option8-course_template_new_cat-enrol', array_column($courses, 'fullname')));
        $courses = enrol_get_users_courses($rcp2->id);
        $this->assertEquals(2, count($courses));
        $courses = enrol_get_users_courses($rcp3->id);
        $this->assertEquals(4, count($courses));
        $this->assertEquals(false, in_array('Option7-course_template_existing_cat-enrol', array_column($courses, 'fullname')));
        $this->assertEquals(true, in_array('Option8-course_template_new_cat-enrol', array_column($courses, 'fullname')));
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
