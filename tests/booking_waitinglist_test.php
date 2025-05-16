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
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class booking_waitinglist_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
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
     * Test booking, cancelation, option has started etc.
     *
     * @covers \classes\booking_option::enrol_user
     *
     * @param array $data
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_group_enrolment(array $data, array $expected): void {
        global $DB, $CFG;

        $standarddata = self::provide_standard_data();

        $this->setAdminUser();

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
        if (isset($data['bookingsettings'])) {
            foreach ($data['bookingsettings'] as $key => $value) {
                $bdata[$key] = $value;
            }
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $users["bookingmanager"]->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        // For the moment, we enrol all users in course, this can be adapted if needed.
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option = $standarddata['option'];
        if (isset($data['optionsettings'])) {
            foreach ($data['optionsettings'] as $setting) {
                foreach ($setting as $key => $value) {
                    $option[$key] = $value;
                }
            }
        }

        $option['bookingid'] = $booking->id;
        $option['courseid'] = $course->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option((object) $option);

        // Set pluginsettings.
        if (isset($data['pluginsettings'])) {
            foreach ($data['pluginsettings'] as $pluginsetting) {
                set_config($pluginsetting['key'], $pluginsetting['value'], $pluginsetting['component']);
            }
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // So far for the basic setup.
        // Now proceed to specific logic of the testcase.
        $i = $data['userstobook'];
        $c = 1;
        foreach ($users as $user) {
            $this->setUser($user);
            // Book the first user without any problem.
            $boinfo = new bo_info($settings);

            $result = booking_bookit::bookit('option', $settings->id, $user->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, true);
            $this->assertEquals($expected['userresults'][$c][1], $id);
            $result = booking_bookit::bookit('option', $settings->id, $user->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, true);
            $this->assertEquals($expected['userresults'][$c][2], $id);

            $c++;
            // TODO: Create time difference between the bookings.
            // time_mock::set_mock_time(strtotime('+ 1 days'));
            // time_mock::init();
            // $time = time_mock::get_mock_time();
            if ($c > $i) {
                $lastuser = $user;
                break;
            }
        }
        $answers = $DB->get_records('booking_answers', null, 'timemodified ASC');
        $this->assertCount($data['userstobook'], $answers);
        $booked = [];
        $waiting = [];
        foreach ($answers as $answer) {
            if ($answer->waitinglist === "1") {
                $waiting[] = $answer;
            } else if (
                $answer->waitinglist === "0"
            ) {
                $booked[] = $answer;
            }
        }
        $this->assertCount(2, $booked);
        $this->assertCount($data['userstobook'] - 2, $waiting);
        // Check how many are on waitinglist.
        // Reo
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        return [
            'bookonwaitinglist' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    // 'optionsettings' => [
                    //     'maxanswers' => 2,
                    //     'maxoverbooking' => 5,
                    // ],
                    'userstobook' => 6,
                ],
                [
                    'userresults' => [
                        1 => [
                            1 => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                            2 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                            3 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                        ],
                        2 => [
                            1 => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                            2 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                            3 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                        ],
                        3 => [ // Waitinglist starting here.
                            1 => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                            2 => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                        ],
                        4 => [
                            1 => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                            2 => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                        ],
                        5 => [
                            1 => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                            2 => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                        ],
                        6 => [
                            1 => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                            2 => MOD_BOOKING_BO_COND_ONWAITINGLIST,
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
                'coursestarttime' => strtotime('now + 1 day'),
                'courseendtime' => strtotime('now + 2 day'),
                'importing' => 1,
                'useprice' => 0,
                'default' => 50, // Default price.
                'maxanswers' => 2, // Default price.
                'maxoverbooking' => 5, // Default price.
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
                    'name' => 'student3',
                    'params' => [],
                ],
                [
                    'name' => 'student4',
                    'params' => [],
                ],
                                [
                    'name' => 'student5',
                    'params' => [],
                ],
                                [
                    'name' => 'student6',
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
