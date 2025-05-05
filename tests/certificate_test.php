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
 * Tests for certificates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 David Ala
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use tool_certificate\template;


defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Class handling tests for certificates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class certificate_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test booking, cancelation, option has started etc.
     *
     * @covers \condition\bookitbutton::is_available
     * @covers \condition\alreadybooked::is_available
     * @covers \condition\fullybooked::is_available
     * @covers \condition\confirmation::render_page
     * @covers \condition\notifymelist::is_available
     * @covers \condition\isloggedin::is_available
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_certificate(array $data, array $expected): void {
        global $DB, $CFG;
        $standarddata = self::provide_standard_data();

        $certificatedata = (object) [
            'id' => 0,
            'name' => "Test",
            'shared' => "1",
            'width' => 297,
            'height' => 210,
            'leftmargin' => 0,
            'rightmargin' => 0,
            'contextid' => 1,

        ];
        $t = template::create($certificatedata);
        $tid = $t->get_id();
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

        // We enrol all users, this can be adapted if needed.
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
        $expirydateabsolute = $data['optionsettings'][0]['certificatedata']['expirydateabsolute'];
        $option['json'] = '{"certificate":' . $tid . ', "expirydateabsolute":' . $expirydateabsolute . '}';
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
        // Book the users.
        $student1 = $users['student1'];
        $this->setUser($users['student1']);
        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($expected['bookitresults'][0], $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);


        $student2 = $users['student2'];
        $this->setUser($users['student2']);
        // Book the Second user without any problem.
        $boinfo = new bo_info($settings);

        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals($expected['bookitresults'][0], $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        booking_activitycompletion([$student1->id], $booking1, $settings->cmid, $option1->id);
        $certificates = $DB->get_records('tool_certificate_issues');
        $this->assertCount($expected['onecertificate']['certcount'], $certificates);
        self::teardown();
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        return [
            'onecertificate' => [
                ['pluginsettings' => [
                        [
                            'component' => 'booking',
                            'key' => 'certificateon',
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
                    'optionsettings' => [
                [
                'useprice' => 0,
                'certificatedata' => [
                'expirydateabsolute' => 17,
                ],
                ],
                ],
                ],
                [
                    'bookitresults' => [
                        MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    ],
                    'onecertificate' => [
                        'certcount' => 1,
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
            'useprice' => 0,
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
    public function teardown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
