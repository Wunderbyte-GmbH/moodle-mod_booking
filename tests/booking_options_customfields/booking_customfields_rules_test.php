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
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2026 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2026 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_customfields_rules_test extends advanced_testcase {
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
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Test rule on option being booked for user wiht customfields.
     *
     * @covers \mod_booking\event\bookingoption_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\match_userprofilefield::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_option_booked_with_customfields_and_profilefields(array $bdata): void {
        global $DB;

        singleton_service::destroy_instance();
        $this->setAdminUser();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Add a user profile field of text type.
        $fieldid1 = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'company', 'name' => 'Company', 'datatype' => 'text',
        ])->id;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user(['profile_field_company' => 'Demo Inc']);
        $user3 = $this->getDataGenerator()->create_user(['profile_field_company' => 'Acme Inc']);
        $user4 = $this->getDataGenerator()->create_user(['profile_field_company' => 'TNMU Inc']);
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user4->id, $course->id, 'editingteacher');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_booked".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"bookedsubj","templateformat":"1",';
        $actstr .= '"template":"Dear {firstname} {lastname} of {company}, a new user has been booked:';
        $actstr .= '{firstnamerelated} {lastnamerelated}, {emailrelated} of {company-related}."}';
        $ruledata1 = [
            'name' => 'notifyuser',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["' . $user4->id . '"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked","aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Create a booking option answer - book user3.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user3->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Run adhock tasks to get actual messages and clean-up task list.
        unset($messages);
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        // Validate messages.
        $this->assertCount(2, $messages);
        // Load user profile data to validate profile field values.
        profile_load_data($user2);
        profile_load_data($user3);
        profile_load_data($user4);
        foreach ($messages as $key => $message) {
            $this->assertStringContainsString($user4->firstname, $message->fullmessage);
            $this->assertStringContainsString($user4->lastname, $message->fullmessage);
            $this->assertSame(1, preg_match('(' . $user2->firstname . '|' . $user3->firstname . ')', $message->fullmessage));
            $this->assertSame(1, preg_match('(' . $user2->lastname . '|' . $user3->lastname . ')', $message->fullmessage));
            $this->assertSame(1, preg_match('(' . $user2->email . '|' . $user3->email . ')', $message->fullmessage));
            // Validate user profile field values in message.
            $this->assertStringContainsString($user4->profile_field_company, $message->fullmessage);
            $this->assertSame(1, preg_match(
                '(' . $user2->profile_field_company . '|' . $user3->profile_field_company . ')',
                $message->fullmessage
            ));
        }
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
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
