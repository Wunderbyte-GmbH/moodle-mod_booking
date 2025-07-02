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
 * Tests for booking option actions.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;


defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Class handling tests booking option actions
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class booking_action_test extends advanced_testcase {
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
     * Test booking, cancelation, option has started etc.
     *
     * @covers \mod_booking\bo_actions\action_types\userprofilefield
     * @covers \mod_booking\bo_actions\action_types\cancelbooking
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_test_userprofilefield_settings_provider
     */
    public function test_userprofilefield_actions(array $data, array $expected): void {
        global $DB, $CFG;

        $standarddata = self::provide_standard_data();

        $this->setAdminUser();

        // User profile field settings.
        $profilefields = [];
        foreach ($data['profilefields'] as $shortname => $fieldsettings) {
            $this->getDataGenerator()->create_custom_profile_field([
                'shortname' => $shortname, 'name' => $fieldsettings['name'], 'datatype' => $fieldsettings['datatype'],
            ]);
            $profilefields[] = "profile_field_" . $shortname;
        }

        // Coursesettings.
        $courses = [];
        foreach ($data['coursesettings'] as $shortname => $courssettings) {
            $course = $this->getDataGenerator()->create_course($courssettings); // Usually 1 course is sufficient.
            $courses[$shortname] = $course;
        }

        $users = [];
        foreach ($standarddata['users'] as $user) {
            // Standard params of users can be overwritten in testdata.
            $params = isset($data['userssettings'][$user['name']])
                ? $data['userssettings'][$user['name']] : ($standarddata['users']['params'] ?? []);
            $users[$user['name']] = $this->getDataGenerator()->create_user($params);
        }

        // Fetch standarddata for booking.
        $bdata = $standarddata['booking'];
        // Apply the custom settings for the first booking.
        if (isset($data['bookingsettings'])) {
            foreach ($data['bookingsettings'][0] as $key => $value) {
                $bdata[$key] = $value;
            }
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $users["bookingmanager"]->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // For the moment, we enrol all users, this can be adapted if needed.
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $jsoncond = '{
            "boactions": {
                "1": {
                    "id": 1,
                    "action_type": "' . (string) $data['actiontype'] . '",
                    "boactionname": "' . (string) $data['boactionname'] . '"';

        switch ((string) $data['actiontype']) {
            case 'userprofilefield':
                $jsoncond .= ',
                "boactionselectuserprofilefield": "' . (string) $data['boactionselectuserprofilefield'] . '",
                "boactionuserprofileoperator": "' . (string) $data['boactionuserprofileoperator'] . '",
                "boactionuserprofilefieldvalue": "' . (string) $data['boactionuserprofilefieldvalue'] . '"';
                break;
            case 'cancelbooking':
                $jsoncond .= ',
                "boactioncancelbooking": "' . (string) $data['boactioncancelbooking'] . '"';
                break;
        }

        $jsoncond .= '}
            }
        }';

        $option1 = $standarddata['option'];
        if (isset($data['optionsettings'])) {
            foreach ($data['optionsettings'] as $setting) {
                foreach ($setting as $key => $value) {
                    $option1[$key] = $value;
                }
            }
        }

        $option1['bookingid'] = $booking1->id;
        $option1['courseid'] = $course->id;
        $option1['json'] = $jsoncond;
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option((object) $option1);

        // Set pluginsettings.
        if (isset($data['pluginsettings'])) {
            foreach ($data['pluginsettings'] as $pluginsetting) {
                set_config($pluginsetting['key'], $pluginsetting['value'], $pluginsetting['component']);
            }
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // So far for the basic setup.
        // Now proceed to logic of the testcase.

        $student1 = $users['student1'];
        $this->setUser($student1);
        $boinfo = new bo_info($settings);

        switch ((string) $data['actiontype']) {
            case 'userprofilefield':
                // Validate user profile field defaulr values.
                $userobj = singleton_service::get_instance_of_user($student1->id);
                require_once("$CFG->dirroot/user/profile/lib.php");
                profile_load_data($userobj);
                $key = "profile_field_" . (string) $data['boactionselectuserprofilefield'];
                $this->assertEquals($expected['defaultprofilefieldvalue'], $userobj->{$key});

                // User Books Option.
                $result = booking_bookit::bookit('option', $settings->id, $student1->id);
                [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
                $this->assertEquals($expected['bookitresult'], $id);
                // In case booking was possible, book the user.
                $result = booking_bookit::bookit('option', $settings->id, $student1->id);

                // Validate user profile field after booking.
                profile_load_data($userobj);
                $this->assertEquals($expected['resultprofilefieldvalue'], $userobj->{$key});
                break;
            case 'cancelbooking':
                // User Books Option.
                $result = booking_bookit::bookit('option', $settings->id, $student1->id);
                [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
                $this->assertEquals($expected['bookitresult1'], $id);
                // In case booking was possible, book the user.
                $result = booking_bookit::bookit('option', $settings->id, $student1->id);
                [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
                $this->assertEquals($expected['bookitresult2'], $id);
                break;
        }

        self::tearDown();
    }

    /**
     * Data provider for specific test settings
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_test_userprofilefield_settings_provider(): array {

        return [
            'Replace profile field value' => [
                [
                    'profilefields' => [
                        'booking_field' => [
                            'name' => 'Booking Field',
                            'datatype' => 'text',
                        ],
                    ],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'userssettings' => [
                        'student1' => ['profile_field_booking_field' => 'default'],
                    ],
                    'actiontype' => 'userprofilefield',
                    'boactionname' => 'User Profile Field Action - replace value',
                    'boactionselectuserprofilefield' => 'booking_field',
                    'boactionuserprofileoperator' => 'set',
                    'boactionuserprofilefieldvalue' => 'action-football',
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'defaultprofilefieldvalue' => 'default',
                    'resultprofilefieldvalue' => 'action-football',
                ],
            ],
            'Add to profile field value' => [
                [
                    'profilefields' => [
                        'booking_field' => [
                            'name' => 'Booking Field',
                            'datatype' => 'text',
                        ],
                    ],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'userssettings' => [
                        'student1' => ['profile_field_booking_field' => '1000'],
                    ],
                    'actiontype' => 'userprofilefield',
                    'boactionname' => 'User Profile Field Action - add to value',
                    'boactionselectuserprofilefield' => 'booking_field',
                    'boactionuserprofileoperator' => 'subtract',
                    'boactionuserprofilefieldvalue' => '1',
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'defaultprofilefieldvalue' => '1000',
                    'resultprofilefieldvalue' => '999',
                ],
            ],
            'Extract from profile field value' => [
                [
                    'profilefields' => [
                        'booking_field' => [
                            'name' => 'Booking Field',
                            'datatype' => 'text',
                        ],
                    ],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'userssettings' => [
                        'student1' => ['profile_field_booking_field' => '1000'],
                    ],
                    'actiontype' => 'userprofilefield',
                    'boactionname' => 'User Profile Field Action - extract from value',
                    'boactionselectuserprofilefield' => 'booking_field',
                    'boactionuserprofileoperator' => 'add',
                    'boactionuserprofilefieldvalue' => '1',
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'defaultprofilefieldvalue' => '1000',
                    'resultprofilefieldvalue' => '1001',
                ],
            ],
            'Add date to profile field value' => [
                [
                    'profilefields' => [
                        'booking_field' => [
                            'name' => 'Booking Field',
                            'datatype' => 'text',
                        ],
                    ],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'userssettings' => [
                        'student1' => [
                            'profile_field_booking_field' => userdate(strtotime('today + 1 day'))
                            . " - " . userdate(strtotime('today + 2 day')),
                        ],
                    ],
                    'actiontype' => 'userprofilefield',
                    'boactionname' => 'User Profile Field Action - add date to value',
                    'boactionselectuserprofilefield' => 'booking_field',
                    'boactionuserprofileoperator' => 'adddate',
                    'boactionuserprofilefieldvalue' => 'today + 1 week',
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'defaultprofilefieldvalue' => userdate(strtotime('today + 1 day'))
                        . " - " . userdate(strtotime('today + 2 day')),
                    'resultprofilefieldvalue' => userdate(strtotime('today + 1 day'))
                        . " - " . userdate(strtotime('today + 2 day + 1 week')),
                ],
            ],
            'Cancel booking' => [
                [
                    'profilefields' => [],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'userssettings' => [],
                    'actiontype' => 'cancelbooking',
                    'boactionname' => 'Cancel Booking Action',
                    'boactioncancelbooking' => '1',
                ],
                [
                    'bookitresult1' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'bookitresult2' => MOD_BOOKING_BO_COND_BOOKITBUTTON,
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
            'booking' => [
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
                'optiondateid_0' => 0,
                'daystonotify_0' => 0,
                'importing' => 1,
                'useprice' => 0,
                'default' => 50, // Default price.
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

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
