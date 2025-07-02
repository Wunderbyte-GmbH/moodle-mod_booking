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
final class booking_action_bookotheroption_test extends advanced_testcase {
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
     * Test booking option action - book other options.
     *
     * @covers \mod_booking\bo_actions\action_types\bookotheroptions
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_test_bookotheroption_provider
     */
    public function test_bookotheroption_actions(array $data, array $expected): void {
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

        $users = [];
        foreach ($standarddata['users'] as $user) {
            // Standard params of users can be overwritten in testdata.
            $params = isset($data['userssettings'][$user['name']])
                ? $data['userssettings'][$user['name']] : ($standarddata['users']['params'] ?? []);
            $users[$user['name']] = $this->getDataGenerator()->create_user($params);
        }

        // Coursesettings.
        $courses = [];
        foreach ($data['coursesettings'] as $shortname => $courssettings) {
            $course = $this->getDataGenerator()->create_course($courssettings); // Usually 1 course is sufficient.
            $courses[$shortname] = $course;
            // For the moment, we enrol all users, this can be adapted if needed.
            foreach ($users as $user) {
                $this->getDataGenerator()->enrol_user($user->id, $course->id);
            }
        }

        // Bookingsettings.
        $bookings = [];
        foreach ($data['bookingsettings'] as $bookingname => $bookinginstancesettings) {
            // Fetch standarddata for booking.
            $bdata = $standarddata['booking'];
            $bdata['name'] = $bookingname;
            if (isset($bookinginstancesettings['course'])) {
                $bdata['course'] = $courses[$bookinginstancesettings['course']]->id;
                unset($bookinginstancesettings['course']);
            }
            // Apply the custom settings for the each booking.
            foreach ($bookinginstancesettings as $key => $value) {
                $bdata[$key] = $value;
            }
            $bdata['bookingmanager'] = $users["bookingmanager"]->username;
            $bookings[$bookingname] = $this->getDataGenerator()->create_module('booking', $bdata);
        }

        // Optionsettings.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $options = [];
        foreach ($data['optionsettings'] as $optionname => $optionsettings) {
            $option = $standarddata['option'];
            if (isset($optionsettings['booking'])) {
                $option['bookingid'] = $bookings[$optionsettings['booking']]->id;
                unset($optionsettings['booking']);
            }
            if (isset($optionsettings['course'])) {
                $option['courseid'] = $courses[$optionsettings['course']]->id;
                unset($optionsettings['course']);
            }
            foreach ($optionsettings as $key => $value) {
                $option[$key] = $value;
            }
            $options[$optionname] = $plugingenerator->create_option((object) $option);
        }

        // Create option actions.
        if (isset($data['optionactions'])) {
            foreach ($data['optionactions'] as $optionactionname => $optionactionsettings) {
                if (isset($optionactionsettings['option'])) {
                    $optionactionsettings['optionid'] = $options[$optionactionsettings['option']]->id;
                    unset($optionactionsettings['option']);
                }
                $plugingenerator->create_action((object) $optionactionsettings);
            }
        }

        // Set pluginsettings.
        if (isset($data['pluginsettings'])) {
            foreach ($data['pluginsettings'] as $pluginsetting) {
                set_config($pluginsetting['key'], $pluginsetting['value'], $pluginsetting['component']);
            }
        }

        // So far for the basic setup.
        // Now proceed to logic of the testcase.
        foreach ($expected as $expectedresult) {
            // Set user.
            $student = $users[$expectedresult['user']];
            $this->setUser($student);
            // Select option to book.
            $option = $options[$expectedresult['option']];
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
            $boinfo = new bo_info($settings);
            // User Books Option or validate booking.
            $result = booking_bookit::bookit('option', $settings->id, $student->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
            $this->assertEquals($expectedresult['bookitresult1'], $id);
            // In case booking was possible, book the user.
            $result = booking_bookit::bookit('option', $settings->id, $student->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
            $this->assertEquals($expectedresult['bookitresult2'], $id);
        }

        self::tearDown();
    }

    /**
     * Data provider for specific test settings
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_test_bookotheroption_provider(): array {

        return [
            'Book other options - success if forced' => [
                [
                    'profilefields' => [],
                    'userssettings' => [],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                        'secondcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookingsettings' => [
                        'firstbooking' => [
                            'name' => 'firstbooking',
                            'course' => 'firstcourse',
                        ],
                        'secondbooking' => [
                            'name' => 'secondbooking',
                            'course' => 'secondcourse',
                        ],
                    ],
                    'optionsettings' => [
                        'b1option1' => [
                            'text' => 'b1option1',
                            'booking' => 'firstbooking',
                            'course' => 'firstcourse',
                            'maxanswers' => 5,
                        ],
                        'b1option2' => [
                            'text' => 'b1option2',
                            'booking' => 'firstbooking',
                            'course' => 'firstcourse',
                            'maxanswers' => 2,
                            'restrictanswerperiodopening' => 1,
                            'bookingopeningtime' => 'now + 1 day', // Booking is not allowed until tomorrow.
                        ],
                        'b2option1' => [
                            'text' => 'b2option1',
                            'booking' => 'secondbooking',
                            'course' => 'secondcourse',
                            'maxanswers' => 1,
                        ],
                        'b2option2' => [
                            'text' => 'b2option2',
                            'booking' => 'secondbooking',
                            'course' => 'secondcourse',
                            'maxanswers' => 2,
                        ],
                    ],
                    'optionactions' => [
                        'bookotheroptions' => [
                            'option' => 'b1option1',
                            'action_type' => 'bookotheroptions',
                            'boactionname' => 'Book more options',
                            'boactionjson' => json_encode([
                                'otheroptions' => ["b1option2", "b2option1"],
                                'bookotheroptionsforce' => '7',
                            ]),
                        ],
                    ],
                ],
                [
                    'failbookingb1option2' => [
                        'user' => 'student1',
                        'option' => 'b1option2',
                        'bookitresult1' => MOD_BOOKING_BO_COND_BOOKING_TIME, // Cannot book option beause of bookingtime.
                        'bookitresult2' => MOD_BOOKING_BO_COND_BOOKING_TIME, // Cannot book option beause of bookingtime.
                    ],
                    'successbookingnwithothers' => [
                        'user' => 'student2',
                        'option' => 'b1option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT, // Book other option forced.
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                    ],
                    'confirmbookingnwithothers12' => [
                        'user' => 'student2',
                        'option' => 'b1option2',
                        'bookitresult1' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                    ],
                    'confirmbookingwithothers21' => [
                        'user' => 'student2',
                        'option' => 'b2option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                    ],
                    'failbookingb2option1' => [
                        'user' => 'student3',
                        'option' => 'b2option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_FULLYBOOKED, // Cannot book option beause fully booked.
                        'bookitresult2' => MOD_BOOKING_BO_COND_FULLYBOOKED, // Cannot book option beause fully booked.
                    ],
                    'successbookingnwithothers1' => [
                        'user' => 'student3',
                        'option' => 'b1option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT, // Book other option forced.
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                    ],
                    'confirmbookingnwithothers312' => [
                        'user' => 'student3',
                        'option' => 'b1option2',
                        'bookitresult1' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                    ],
                    'confirmbookingwithothers321' => [
                        'user' => 'student3',
                        'option' => 'b2option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Book other option forced.
                    ],
                ],
            ],
            'Book other options - failed beause of blocking' => [
                [
                    'profilefields' => [],
                    'userssettings' => [],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                        'secondcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookingsettings' => [
                        'firstbooking' => [
                            'name' => 'firstbooking',
                            'course' => 'firstcourse',
                        ],
                        'secondbooking' => [
                            'name' => 'secondbooking',
                            'course' => 'secondcourse',
                        ],
                    ],
                    'optionsettings' => [
                        'b1option1' => [
                            'text' => 'b1option1',
                            'booking' => 'firstbooking',
                            'course' => 'firstcourse',
                            'maxanswers' => 5,
                        ],
                        'b1option2' => [
                            'text' => 'b1option2',
                            'booking' => 'firstbooking',
                            'course' => 'firstcourse',
                            'maxanswers' => 3,
                        ],
                        'b2option1' => [
                            'text' => 'b2option1',
                            'booking' => 'secondbooking',
                            'course' => 'secondcourse',
                            'maxanswers' => 2,
                            'restrictanswerperiodopening' => 1,
                            'bookingopeningtime' => 'now + 1 day', // Booking is not allowed until tomorrow.
                        ],
                        'b2option2' => [
                            'text' => 'b2option2',
                            'booking' => 'secondbooking',
                            'course' => 'secondcourse',
                            'maxanswers' => 3,
                        ],
                    ],
                    'optionactions' => [
                        'bookotheroptions' => [
                            'option' => 'b1option1',
                            'action_type' => 'bookotheroptions',
                            'boactionname' => 'Book more options',
                            'boactionjson' => json_encode([
                                'otheroptions' => ["b1option2", "b2option1"],
                                'bookotheroptionsforce' => '5',
                            ]),
                        ],
                    ],
                ],
                [
                    'successbookingb2option2' => [
                        'user' => 'student1',
                        'option' => 'b2option2',
                        'bookitresult1' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    ],
                    'failbookingnwithothers' => [
                        'user' => 'student2',
                        'option' => 'b1option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                        'bookitresult2' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                    ],
                    'successbookingb1option2' => [
                        'user' => 'student2',
                        'option' => 'b1option2',
                        'bookitresult1' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    ],
                    'failbookinngb2option1' => [
                        'user' => 'student2',
                        'option' => 'b2option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_BOOKING_TIME,
                        'bookitresult2' => MOD_BOOKING_BO_COND_BOOKING_TIME,
                    ],
                    'failbookingnwithothers1' => [
                        'user' => 'student3',
                        'option' => 'b1option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                        'bookitresult2' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                    ],
                ],
            ],
            'Book other options - success if no overbooking' => [
                [
                    'profilefields' => [],
                    'userssettings' => [],
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                        'secondcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookingsettings' => [
                        'firstbooking' => [
                            'name' => 'firstbooking',
                            'course' => 'firstcourse',
                        ],
                        'secondbooking' => [
                            'name' => 'secondbooking',
                            'course' => 'secondcourse',
                        ],
                    ],
                    'optionsettings' => [
                        'b1option1' => [
                            'text' => 'b1option1',
                            'booking' => 'firstbooking',
                            'course' => 'firstcourse',
                            'maxanswers' => 5,
                        ],
                        'b1option2' => [
                            'text' => 'b1option2',
                            'booking' => 'firstbooking',
                            'course' => 'firstcourse',
                            'maxanswers' => 2,
                        ],
                        'b2option1' => [
                            'text' => 'b2option1',
                            'booking' => 'secondbooking',
                            'course' => 'secondcourse',
                            'maxanswers' => 1,
                        ],
                        'b2option2' => [
                            'text' => 'b2option2',
                            'booking' => 'secondbooking',
                            'course' => 'secondcourse',
                            'maxanswers' => 2,
                        ],
                    ],
                    'optionactions' => [
                        'bookotheroptions' => [
                            'option' => 'b1option1',
                            'action_type' => 'bookotheroptions',
                            'boactionname' => 'Book more options',
                            'boactionjson' => json_encode([
                                'otheroptions' => ["b1option2", "b2option1"],
                                'bookotheroptionsforce' => '6',
                            ]),
                        ],
                    ],
                ],
                [
                    'successsinglebooking' => [
                        'user' => 'student1',
                        'option' => 'b2option2',
                        'bookitresult1' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    ],
                    'successbookingnwithothers' => [
                        'user' => 'student2',
                        'option' => 'b1option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    ],
                    'confirmbookingnwithothers12' => [
                        'user' => 'student2',
                        'option' => 'b1option2',
                        'bookitresult1' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    ],
                    'confirmbookinngwithothers21' => [
                        'user' => 'student2',
                        'option' => 'b2option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                        'bookitresult2' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    ],
                    'failbookingnwithothers' => [
                        'user' => 'student3',
                        'option' => 'b1option1',
                        'bookitresult1' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                        'bookitresult2' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
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
                'coursestarttime_0' => strtotime('now + 2 day'),
                'courseendtime_0' => strtotime('now + 3 day'),
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
                    'name' => 'student3',
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
