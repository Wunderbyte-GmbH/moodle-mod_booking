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
use mod_booking\table\manageusers_table;
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
final class condition_otheroptionsavailable_test extends advanced_testcase {
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
    public function test_book_other_options(array $data, array $expected): void {
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

        $this->setAdminUser();

        // For the moment, we enrol all users, this can be adapted if needed.
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $relatedoptions = [];
        foreach ($standarddata['relatedoptions'] as $option) {
            $option['bookingid'] = $booking1->id;
            $option['courseid'] = $course->id;
            /** @var mod_booking_generator $plugingenerator */
            $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
            $relatedoption = $plugingenerator->create_option((object) $option);
            // We can limit the options that should be linked in the description.
            if (isset($data['optionsforjson'])) {
                if (in_array($relatedoption->text, $data['optionsforjson'])) {
                    $relatedoptions[$relatedoption->id] = $relatedoption;
                }
            } else {
                // If the key isn't set, simply set all options for json.
                $relatedoptions[$relatedoption->id] = $relatedoption;
            }
        }

        foreach ($relatedoptions as $id => $option) {
            // Enrol a user (student2) to each option.
            $settings = singleton_service::get_instance_of_booking_option_settings($id);
            $boinfo = new bo_info($settings);
            $result = booking_bookit::bookit('option', $id, $users['student2']->id);
            $result = booking_bookit::bookit('option', $id, $users['student2']->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $users['student2']->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

            $answers = singleton_service::get_instance_of_booking_answers($settings);
            $this->assertCount(1, $answers->users);
        }
        $relatedidstring = implode(',', array_keys($relatedoptions));

        $jsoncond = '{
            "boactions": {
                "1": {
                    "id": 1,
                    "action_type": "bookotheroptions",
                    "boactionname": "Book options",
                    "bookotheroptionsselect": [' . $relatedidstring . '],
                    "bookotheroptionsforce": ' . (string) $data['bookotheroptionsforce'] . '
                }
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
        $this->setUser($users['student1']);
        $boinfo = new bo_info($settings);

        // User Books Option.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($expected['bookitresult'], $id);
        // In case booking was possible, book the user.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Now check if related options have been booked (if conditions didn't block).
        foreach ($relatedoptions as $optionid => $option) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            if ($expected['relatedoptionsbooked']) {
                [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
                $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
            } else {
                $answers = singleton_service::get_instance_of_booking_answers($settings);
                $this->assertCount(1, $answers->users);
            }
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
            'alwaysbook' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'userssettings' => [
                        'student1' => [], // Just a demo how params could be set.
                    ],
                    'optionsettings' => [
                        [
                            'useprice' => 0, // Disable price for this option.
                        ],
                    ],
                    'bookotheroptionsforce' => MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE,
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'relatedoptionsbooked' => true,
                ],
            ],
            'checkwaitinglist' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookotheroptionsforce' => MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_NOOVERBOOKING,
                    'optionsforjson' => [
                        'Available option',
                        'No more seats',
                    ],
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                    'relatedoptionsbooked' => false,
                ],
            ],
            'checkwaitinglistnotfullybooked' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookotheroptionsforce' => MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_NOOVERBOOKING,
                    'optionsforjson' => [
                        'Available option',
                        'Not yet open',
                    ],
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'relatedoptionsbooked' => true,
                ],
            ],
            'checkallconditions' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookotheroptionsforce' => MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_CONDITIONS_BLOCKING,
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                    'relatedoptionsbooked' => false,
                ],
            ],
            'checkallconditionsnotfull' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookotheroptionsforce' => MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_CONDITIONS_BLOCKING,
                    'optionsforjson' => [
                        'Available option',
                        'Not yet open',
                    ],
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_OTHEROPTIONSAVAILABLE,
                    'relatedoptionsbooked' => false,
                ],
            ],
            'checkallconditionsavailable' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookotheroptionsforce' => MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_CONDITIONS_BLOCKING,
                    'optionsforjson' => [
                        'Available option',
                    ],
                ],
                [
                    'bookitresult' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'relatedoptionsbooked' => true,
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
                'useprice' => 0,
                'default' => 50, // Default price.
            ],
            'relatedoptions' => [
                [
                    'text' => 'Not yet open',
                    'bookingopeningtime' => strtotime('now + 2 day'),
                    'bookingclosingtime' => strtotime('now + 5 day'),
                    'restrictanswerperiodopening' => 1,
                    'restrictanswerperiodclosing' => 1,
                    'useprice' => 0,
                    'default' => 50, // Default price.
                ],
                [
                    'text' => 'No more seats',
                    'coursestarttime_0' => strtotime('now + 1 day'),
                    'courseendtime_0' => strtotime('now + 2 day'),
                    'maxanswers' => 1,
                    'importing' => 1,
                    'useprice' => 0,
                    'default' => 50, // Default price.
                ],
                [
                    'text' => 'Available option',
                    'coursestarttime_0' => strtotime('now + 1 day'),
                    'courseendtime_0' => strtotime('now + 2 day'),
                    'importing' => 1,
                    'useprice' => 0,
                    'default' => 50, // Default price.
                ],
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
