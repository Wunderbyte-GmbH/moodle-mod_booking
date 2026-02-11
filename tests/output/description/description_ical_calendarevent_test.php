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

namespace mod_booking\output\description;

use advanced_testcase;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_bookit;
use mod_booking_generator;
use mod_booking\singleton_service;
use context_system;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for Booking
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class description_ical_calendarevent_test extends advanced_testcase {
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
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Removes cached users in the place holders.
     * @return void
     */
    public static function reset_cache(): void {
        \mod_booking\placeholders\placeholders_info::$placeholders = [];
    }

    /**
     * get_instance
     *
     * @param mixed $class
     * @param mixed $optionid
     * @return description_base
     */
    protected function get_instance($class, $optionid): description_base {
        switch ($class) {
            case 'ical':
                return new description_ical($optionid);
            case 'calendarevent':
                return new description_calendarevent($optionid);
        }

        throw new \InvalidArgumentException('Invalid class name');
    }

    /**
     * This test checks the rendered content as description for an option when the requested
     * context is ical when custom field is empty or has a value.
     * @param string $class
     * @param array $mustcontain
     * @return void
     * @dataProvider data_provider_1
     * @covers \mod_booking\output\description\description_ical
     * @covers \mod_booking\output\description\description_calendarevent
     */
    public function test_render_when_no_custom_field_is_selected(string $class, array $mustcontain): void {
        $this->resetAfterTest();
        self::reset_cache();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $users = [
            ['username' => 'teacher1', 'firstname' => 'Billy', 'lastname' => 'Teachy', 'email' => 'teacher1@example.com'],
            ['username' => 'student1', 'firstname' => 'firstname1', 'lastname' => 'lastname1', 'email' => 'student1@sample.com'],
        ];
        $user1 = $this->getDataGenerator()->create_user($users[0]);
        $student1 = $this->getDataGenerator()->create_user($users[1]);

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;
        unset($bdata['completion']);

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'ABCDEFG';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Custom-Description';
        $record->teachersforoption = $user1->username;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        // Create the description_ical object.
        $descriptionical = $this->get_instance($class, $option->id);

        // Render the description.
        $output = $descriptionical->render();

        // The default template contains the teacher name, so we check that it is contained in the output.
        foreach ($mustcontain as $must) {
            $this->assertStringContainsString($must, $output);
        }
    }

    /**
     * data provider for test_render_when_no_custom_field_is_selected
     * @return array
     */
    public static function data_provider_1(): array {
        return [
            'ical' => [
                'class' => 'ical',
                'mustcontain' => ['Billy Teachy', 'ABCDEFG'], // Default template contains teacher name.
            ],
            'calendarevent' => [
                'class' => 'calendarevent',
                'mustcontain' => ['Billy Teachy', 'ABCDEFG'],
                // Default template contains teacher name & some class names which are not present in ical mustahce file.
            ],
        ];
    }

    /**
     * This test checks the rendered content as description for an option when the requested
     * context is ical when custom field is empty or has a value.
     * @param string $class
     * @param string $usertemplate
     * @param array $mustcontain
     * @param array $mustnotcontain
     * @return void
     * @dataProvider data_provider_2
     * @covers \mod_booking\output\description\description_ical
     * @covers \mod_booking\output\description\description_calendarevent
     */
    public function test_render_with_user_defined_template(
        string $class,
        string $usertemplate,
        array $mustcontain,
        array $mustnotcontain
    ): void {
        $this->resetAfterTest();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $users = [
            ['username' => 'teacher1', 'firstname' => 'Billy', 'lastname' => 'Teachy', 'email' => 'teacher1@example.com'],
            ['username' => 'student1', 'firstname' => 'firstname1', 'lastname' => 'lastname1', 'email' => 'student1@sample.com'],
            ['username' => 'student2', 'firstname' => 'firstname2', 'lastname' => 'lastname2', 'email' => 'student2@sample.com'],
        ];
        $user1 = $this->getDataGenerator()->create_user($users[0]);
        $student1 = $this->getDataGenerator()->create_user($users[1]);

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;
        unset($bdata['completion']);

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');

        // Create custom booking field category.
        $categorydata = new stdClass();
        $categorydata->name = 'CustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();
        // Create custom booking field.
        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Custom filed 1';
        $fielddata->shortname = 'customfield1';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        // Set a custom field for iCal description.
        $cfname = 'customfield1';
        set_config('icaldescriptionfield', $cfname, 'booking');
        set_config('calendareventdescriptionfield', $cfname, 'booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'ABCDEFG';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Custom-Description';
        $record->teachersforoption = $user1->username;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');
        $record->customfield_customfield1 = $usertemplate;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        // Destroy the singleton to force a fresh load of settings with custom fields.
        singleton_service::destroy_instance();

        $this->setAdminUser();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->setUser($student1);
        // Book the first user without any problem.
        $boinfo = new bo_info($settings);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Create the description_ical object.
        $descriptionclass = $this->get_instance($class, $option->id);

        // Render the description.
        $output = $descriptionclass->render();

        // Check that the output contains the custom field data.
        foreach ($mustcontain as $must) {
            $this->assertStringContainsString($must, $output);
        }
        foreach ($mustnotcontain as $mustnot) {
            $this->assertStringNotContainsString($mustnot, $output);
        }
    }

    /**
     * data provider for test_render_with_user_defined_template function.
     * @return array
     */
    public static function data_provider_2(): array {
        return [
            'ical: Empty custom field value' => [
                'class' => 'ical',
                'usertemplate' => '', // No value provided so default template is used.
                'mustcontain' => ['Billy Teachy', 'ABCDEFG'], // Default template contains teacher name.
                'mustnotcontain' => [],
            ],
            'ical: Custom field with placeholder: title' => [
                'class' => 'ical',
                'usertemplate' => 'Event for {title}.',
                'mustcontain' => ['Event for ABCDEFG.'],
                'mustnotcontain' => ['Billy Teachy'], // User defined tempate doesn't contain teacher name.
            ],
            'ical: Custom field with multiple placeholders: firstname, lastname' => [
                'class' => 'ical',
                'usertemplate' => 'Dear {firstname} {lastname}',
                'mustcontain' => ['Dear firstname1 lastname1'],
                'mustnotcontain' => ['ABCDEFG', 'Billy Teachy'],
                // User defined tempate doesn't contain teacher name and title.
            ],
            'calendarevent: Empty custom field value' => [
                'class' => 'calendarevent',
                'usertemplate' => '', // No value provided so default template is used.
                'mustcontain' => ['Billy Teachy', 'ABCDEFG'], // Default template contains teacher name.
                'mustnotcontain' => [],
            ],
            'calendarevent: Custom field with placeholder: title' => [
                'class' => 'calendarevent',
                'usertemplate' => 'Event for {title}.',
                'mustcontain' => ['Event for ABCDEFG.'],
                'mustnotcontain' => ['Billy Teachy'], // User defined tempate doesn't contain teacher name.
            ],
            'calendarevent: Custom field with multiple placeholders: firstname, lastname' => [
                'class' => 'calendarevent',
                'usertemplate' => 'Dear {firstname} {lastname}',
                'mustcontain' => ['Dear firstname1 lastname1'],
                'mustnotcontain' => ['ABCDEFG', 'Billy Teachy'],
                // User defined tempate doesn't contain teacher name and title.
            ],
        ];
    }
}
