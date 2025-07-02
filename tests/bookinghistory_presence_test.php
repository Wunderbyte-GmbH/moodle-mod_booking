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
 * @author 2025 Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Class handling tests for bookinghistory.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class bookinghistory_presence_test extends advanced_testcase {
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
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\fullybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     * @covers \mod_booking\bo_availability\conditions\notifymelist::is_available
     * @covers \mod_booking\bo_availability\conditions\isloggedin::is_available
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_history(array $data, array $expected): void {
        global $DB, $CFG;
        $standarddata = self::provide_standard_data();

        // Coursesettings.
        $courses = [];
        foreach ($data['coursesettings'] as $shortname => $courssettings) {
            $course = $this->getDataGenerator()->create_course($courssettings); // Usually 1 course is sufficient.
            $courses[$shortname] = $course;
        };
        $users = [];
        foreach ($standarddata['users'] as $user) {
            // Standard params of users can be overwritten in testdata.
            $params = isset($data['usersettings'][$user['name']])
                ? $data['usersettings'][$user['name']] : ($standarddata['users']['params'] ?? []);
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

        $this->setAdminUser();

        // For the moment, we enrol all users, this can be adapted if needed.
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $option = $standarddata['option'];
        if (isset($data['optionsettings'])) {
            foreach ($data['optionsettings'] as $setting) {
                foreach ($setting as $key => $value) {
                    $option[$key] = $value;
                }
            }
        }

        $option['bookingid'] = $booking1->id;
        $option['courseid'] = $course->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option((object) $option);

        // Set pluginsettings.
        foreach ($data['pluginsettings'] as $pluginsetting) {
            set_config($pluginsetting['key'], $pluginsetting['value'], $pluginsetting['component']);
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // So far for the basic setup.
        // Now proceed to logic of the testcase.
        // Book the user.
        $student1 = $users['student1'];
        $this->setUser($users['student1']);
        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($expected['bookitresults'][0], $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // User is in Bookingoption next we have to change the presence status.
        for ($i = 0; $i < 9; $i++) {
            $alleselectedusers = [$student1->id];
            $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
            $option->changepresencestatus($alleselectedusers, 1 + $i);
            $answers = $DB->get_records('booking_history');
            $this->assertCount(2 + $i, $answers);
            $json = json_decode(end($answers)->json, true);
            $this->assertEquals($expected['presencechange']['case' . $i], $json);
        }
        self::tearDown();
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        return [
            'presencechanges' => [
                [
                    'pluginsettings' => [
                        [
                            'component' => 'booking',
                            'key' => 'notifymelist',
                            'value' => 1,
                        ],
                    ],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'userssettings' => [
                        'student1' => [], // Just a demo how params could be set.
                    ],
                    'bookingsettings' => [
                        [
                            'cancancelbook' => 1,
                        ],
                    ],
                    'optionsettings' => [
                        [
                            'useprice' => 0, // Disable price for this option.
                        ],
                    ],
                ],
                [
                    'bookitresults' => [
                        MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    ],
                    'presencechange' => [
                        'case0' => [
                            "presence" => [
                                "presenceold" => "0",
                                "presencenew" => "1",
                            ],
                        ],
                        'case1' => [
                            "presence" => [
                                "presenceold" => "1",
                                "presencenew" => "2",
                            ],
                        ],
                        'case2' => [
                            "presence" => [
                                "presenceold" => "2",
                                "presencenew" => "3",
                            ],
                        ],
                        'case3' => [
                            "presence" => [
                                "presenceold" => "3",
                                "presencenew" => "4",
                            ],
                        ],
                        'case4' => [
                            "presence" => [
                                "presenceold" => "4",
                                "presencenew" => "5",
                            ],
                        ],
                        'case5' => [
                            "presence" => [
                                "presenceold" => "5",
                                "presencenew" => "6",
                            ],
                        ],
                        'case6' => [
                            "presence" => [
                                "presenceold" => "6",
                                "presencenew" => "7",
                            ],
                        ],
                        'case7' => [
                            "presence" => [
                                "presenceold" => "7",
                                "presencenew" => "8",
                            ],
                        ],
                        'case8' => [
                            "presence" => [
                                "presenceold" => "8",
                                "presencenew" => "9",
                            ],
                        ],
                    ],
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
            'importing' => 1,
            'useprice' => 1,
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
