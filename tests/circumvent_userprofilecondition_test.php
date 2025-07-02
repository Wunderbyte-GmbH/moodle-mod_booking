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
 * @author 2025 Magdalena Holčzik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\bo_availability\bo_info;
use mod_booking\local\override_user_field;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class circumvent_userprofilecondition_test extends advanced_testcase {
    /** @var stdClass $user */
    protected $user;

    protected function setUp(): void {
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();

        // Create a test user.
        $this->user = $this->getDataGenerator()->create_user([
            'firstname' => 'Test',
            'lastname' => 'User',
        ]);

        $this->setUser($this->user);

        // Create a custom user profile field.
        $this->create_custom_profile_field('testfield');

        parent::setUp();
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
     * Helper to create a custom user profile field.
     *
     * @param string $shortname
     *
     * @return void
     *
     */
    protected function create_custom_profile_field(string $shortname): void {
        global $DB;

        $field = new stdClass();
        $field->shortname = $shortname;
        $field->name = 'Test Field';
        $field->datatype = 'text';
        $field->categoryid = 1;
        $field->sortorder = 1;
        $field->required = 0;
        $field->locked = 0;

        $DB->insert_record('user_info_field', $field);
    }
    /**
     * Test booking, cancelation, option has started etc.
     *
     * @covers \mod_booking\local\override_user_field::set_userprefs
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_data_provider
     */
    public function test_circumvent_user_profile_condition($data, $expected): void {
        global $DB, $USER;
        $standarddata = self::provide_standard_data();

        // Initially, no user preferences are saved.
        $preference = $DB->get_records('user_preferences', ['userid' => $USER->id]);
        $this->assertEmpty($preference);

        // Now check for a student user if the condition is blocking although the preference is saved.
        // First set up the environment.
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
        ]);
        $users = [];
        foreach ($standarddata['users'] as $user) {
            // Standard params of users can be overwritten in testdata.
            $params = isset($data['usersettings'][$user['name']])
                ? $data['usersettings'][$user['name']] : ($standarddata['users']['params'] ?? []);
            $users[$user['name']] = $this->getDataGenerator()->create_user($params);
        }

        $bdata = $standarddata['booking'];
        if (isset($data['bookingsettings'])) {
            foreach ($data['bookingsettings'] as $key => $value) {
                $bdata[$key] = $value;
            }
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $users["bookingmanager"]->username;

        // Settings for booking instance need to be added.
        $bdata['json'] = $data['json'] ?? '';
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $b = singleton_service::get_instance_of_booking_by_bookingid($booking->id);
        $obj = new override_user_field($b->cmid);
        $result = $obj->set_userprefs($data['param']);

        $this->assertEquals($expected['result'], $result);
        // Only for valid fields we keep the test running.
        if (!$result) {
            return;
        }

        // Preference was saved.
        $preference = $DB->get_records('user_preferences', ['userid' => $USER->id]);
        $this->assertCount(1, $preference);

        $option = $standarddata['option'];
        $option['bookingid'] = $booking->id;
        $option['courseid'] = $course->id;
        if (isset($data['bofields'])) {
            foreach ($data['bofields'] as $bofieldkey => $bofieldvalue) {
                $option[$bofieldkey] = $bofieldvalue;
            }
        }
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($option);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $this->setUser($users['student1']);
        $result = booking_bookit::bookit('option', $settings->id, $users['student1']->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $users['student1']->id, true);

        // Initially the bookingoption is not accessible for the user.
        $this->assertEquals($expected['blockingfield'], $id);

        // Check if password is valid.
        $validpassword = $obj->password_is_valid($data['password']);
        $this->assertEquals($expected['validpassword'], $validpassword);

        // Now we set the userprefs for this user.
        if ($validpassword) {
            $result = $obj->set_userprefs($data['param'], $users['student1']->id);
            $preference = $DB->get_records('user_preferences', ['userid' => $users['student1']->id]);
            $this->assertCount(1, $preference);
        }

        // Now the bookingoption should be available.
        $result = booking_bookit::bookit('option', $settings->id, $users['student1']->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $users['student1']->id, true);
        $this->assertEquals($expected['availableafterprefs'], $id);
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_data_provider(): array {
        return [
            'test_set_userprefs_with_empty_param' => [
                [
                    'param' => '',
                ],
                [
                    'result' => false,
                ],
            ],
            'test_set_userprefs_with_unknown_field' => [
                [
                    'param' => 'unknownfield_value',
                ],
                [
                    'result' => false,
                ],
            ],
            'test_set_userprefs_with_invalid_format' => [
                [
                    'param' => 'badformat',
                ],
                [
                    'result' => false,
                ],
            ],
            'test_set_userprefs_with_valid_custom_field' => [
                [
                    'param' => 'testfield_CustomVal',
                    'json' => '{"circumventcond":{"cvpwd":"pwd1"}}',
                    'bofields' => [
                        'bo_cond_customuserprofilefield_field' => "testfield",
                        'bo_cond_customuserprofilefield_operator' => "=",
                        'bo_cond_customuserprofilefield_value' => "CustomVal",
                        'bo_cond_userprofilefield_2_custom_restrict' => 1,
                    ],
                    'password' => 'pwd1',
                ],
                [
                    'result' => true,
                    'blockingfield' => MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD,
                    'validpassword' => true,
                    'availableafterprefs' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
            ],
            'test_set_userprefs_with_valid_standard_field' => [
                [
                    'param' => 'firstname_Update',
                    'json' => '{"circumventcond":{"cvpwd":"pwd1"}}',
                    'password' => 'pwd1',
                    'bofields' => [
                        'bo_cond_userprofilefield_field' => "firstname",
                        'bo_cond_userprofilefield_operator' => "=",
                        'bo_cond_userprofilefield_value' => "Update",
                    ],
                ],
                [
                    'result' => true,
                    'blockingfield' => MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD,
                    'validpassword' => true,
                    'availableafterprefs' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
            ],
            'test_set_nonvalid_password' => [
                [
                    'param' => 'firstname_Update',
                    'json' => '{"circumventcond":{"cvpwd":"pwd1"}}',
                    'password' => 'pwd2',
                    'bofields' => [
                        'bo_cond_userprofilefield_field' => "firstname",
                        'bo_cond_userprofilefield_operator' => "=",
                        'bo_cond_userprofilefield_value' => "Update",
                    ],
                ],
                [
                    'result' => true,
                    'blockingfield' => MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD,
                    'validpassword' => false,
                    'availableafterprefs' => MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD,
                ],
            ],
            'test_set_userprefs_with_two_custom_fields' => [
                [
                    'param' => 'testfield_CustomVal',
                    'json' => '{"circumventcond":{"cvpwd":"pwd1"}}',
                    'bofields' => [
                        'bo_cond_customuserprofilefield_field' => "testfield",
                        'bo_cond_customuserprofilefield_operator' => "=",
                        'bo_cond_customuserprofilefield_value' => "CustomVal",
                        'bo_cond_customuserprofilefield_field2' => "testfield",
                        'bo_cond_customuserprofilefield_operator2' => "=",
                        'bo_cond_customuserprofilefield_value2' => "CustomVal",
                        'bo_cond_customuserprofilefield_connectsecondfield' => "&&",
                        'bo_cond_userprofilefield_2_custom_restrict' => 1,
                    ],
                    'password' => 'pwd1',
                ],
                [
                    'result' => true,
                    'blockingfield' => MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD,
                    'validpassword' => true,
                    'availableafterprefs' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
            ],
        ];
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_standard_data(): array {
        return [
            'booking' => [ // In this test, we uns only one booking instance.
                'name' => 'Test',
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
                'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            ],
            'option' => [
                'text' => 'Test option1',
                'coursestarttime_0' => strtotime('now + 1 day'),
                'courseendtime_0' => strtotime('now + 2 day'),
                'bo_cond_userprofilefield_1_default_restrict' => 1,
                'bo_cond_allowedtobookininstance_capabilitynotneeded' => 1, // TODO: Check why this is needed here!
                'bo_cond_allowedtobookininstance_restrict' => 1,
            ],
            'users' => [ // Number of entries corresponds to number of users.
                [
                    'name' => 'student1',
                    'params' => [],
                ],
                [
                    'name' => 'student2',
                    'params' => [],
                ],
                [
                    'name' => 'bookingmanager', // Bookingmanager always needs to be set.
                    'params' => [],
                ],
                [
                    'name' => 'teacher',
                    'params' => [],
                ],
            ],
        ];
    }
}
