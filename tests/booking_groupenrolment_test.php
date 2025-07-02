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
final class booking_groupenrolment_test extends advanced_testcase {
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
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Test booking, cancelation, option has started etc.
     *
     * @covers \mod_booking\booking_option::enrol_user
     *
     * @param array $data
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_group_enrolment(array $data): void {
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

        // Create the groups as defined and apply settings as defined.
        $groupstoenrolto = $data['bookingsettings']['addtogroupofcurrentcourse'] ?? [];
        if (isset($data['additionalsettings']['existingcoursegroups'])) {
            foreach ($data['additionalsettings']['existingcoursegroups'] as $groupname) {
                $group = $this->getDataGenerator()->create_group([
                    'courseid' => $course->id,
                    'name' => $groupname,
                ]);
                if (in_array($groupname, $data['additionalsettings']['groupstobookinto'])) {
                    $groupstoenrolto[] = $group->id;
                }
            }
        }
        $data['bookingsettings']['addtogroupofcurrentcourse'] = $groupstoenrolto;

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

        // Check if groups were created correctly.
        $groupsincoursecreated = groups_get_all_groups($booking->course);
        $this->assertEquals(count($data['additionalsettings']['existingcoursegroups']), count($groupsincoursecreated));

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

        // Try to book again with user1.
        $student1 = $users['student1'];
        $this->setUser($users['student1']);
        // Book the first user without any problem.
        $boinfo = new bo_info($settings);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        foreach ($groupsincoursecreated as $id => $group) {
            if (in_array($group->name, $data['bookingsettings']['addtogroupofcurrentcourse'])) {
                $this->assertTrue(groups_is_member($id, $student1->id));
            }
        }

        if (
            isset($data['bookingsettings']['addtogroupofcurrentcourse'])
            && in_array(MOD_BOOKING_ENROL_INTO_GROUP_OF_BOOKINGOPTION, $data['bookingsettings']['addtogroupofcurrentcourse'])
        ) {
            // We expect a new group to be created.
            $groupsincourseafterenrolment = groups_get_all_groups($booking->course);
            $noldgroups = count($groupsincoursecreated);
            $nnewgroups = count($groupsincourseafterenrolment);
            $this->assertFalse($nnewgroups == $noldgroups);
            $this->assertTrue($nnewgroups == ($noldgroups + 1));
        }

        // Additional user books option.
        $student2 = $users['student2'];
        $this->setUser($student2);

        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $this->assertEquals(groups_is_member($id, $student1->id), groups_is_member($id, $student2->id));
        $newgroups = groups_get_all_groups($booking->course);
        // No additional group should be created.
        if (
            isset($data['bookingsettings']['addtogroupofcurrentcourse'])
            && in_array(MOD_BOOKING_ENROL_INTO_GROUP_OF_BOOKINGOPTION, $data['bookingsettings']['addtogroupofcurrentcourse'])
        ) {
            $this->assertTrue(count($newgroups) == $nnewgroups);
        }

        // Cancel user to check, if group enrolment is deleted as well.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);

        $groupsofcourse = groups_get_all_groups($booking->course);
        foreach ($groupsofcourse as $group) {
            $members = groups_get_members($group->id);
            if (
                !empty($data['bookingsettings']['unenrolfromgroupofcurrentcourse'])
                && str_contains($group->idnumber, MOD_BOOKING_ENROL_GROUPTYPE_SOURCECOURSE)
            ) {
                $this->assertArrayNotHasKey($student2->id, $members);
            };
        }
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        return [
            'enroltoonegroupincourse' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookingsettings' => [
                        'cancancelbook' => 1,
                    ],
                    'additionalsettings' => [
                        'existingcoursegroups' => [
                            'booked',
                            'othergroup',
                        ],
                        'groupstobookinto' => [ // We need this to mock the setting of the booking.
                            'booked',
                        ],
                    ],
                ],
            ],
            'enroltomultiplegroupsincourse' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookingsettings' => [
                        'addtogroupofcurrentcourse' => [], // In this test, we uns only one booking instance.
                        'cancancelbook' => 1,
                    ],
                    'additionalsettings' => [
                        'existingcoursegroups' => [
                            'booked',
                            'alsobooked',
                            'alsobooked',
                            'othergroup',
                        ],
                        'groupstobookinto' => [ // We need this to mock the setting of the booking.
                            'booked',
                            'alsobooked',
                        ],
                    ],
                ],
            ],
            'enroltogroupofoptionandunenrol' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookingsettings' => [
                        'addtogroupofcurrentcourse' => [
                            MOD_BOOKING_ENROL_INTO_GROUP_OF_BOOKINGOPTION,
                        ], // In this test, we uns only one booking instance.
                        'cancancelbook' => 1,
                        'unenrolfromgroupofcurrentcourse' => "1",
                    ],
                    'additionalsettings' => [
                        'existingcoursegroups' => [
                            'booked',
                            'alsobooked',
                            'alsobooked',
                            'othergroup',
                        ],
                        'groupstobookinto' => [ // We need this to mock the setting of the booking.
                        ],
                    ],
                ],
            ],
            'enroltobothtypeofgroupsandunenrol' => [
                [
                    'coursesettings' => [
                        'firstcourse' => [
                            'enablecompletion' => 1,
                        ],
                    ],
                    'bookingsettings' => [
                        'addtogroupofcurrentcourse' => [
                            MOD_BOOKING_ENROL_INTO_GROUP_OF_BOOKINGOPTION,
                        ], // In this test, we uns only one booking instance.
                        'cancancelbook' => 1,
                        'unenrolfromgroupofcurrentcourse' => "1",
                    ],
                    'additionalsettings' => [
                        'existingcoursegroups' => [
                            'booked',
                            'alsobooked',
                            'alsobooked',
                            'othergroup',
                        ],
                        'groupstobookinto' => [
                            'booked',
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
                'coursestarttime_0' => strtotime('+ 2 day', time()),
                'courseendtime_0' => strtotime('+ 3 day', time()),
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
}
