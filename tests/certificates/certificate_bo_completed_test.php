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
 * Tests for certificates when bookingoption is completed.
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


defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Class handling tests for isse of certificates when bookingoptions are completed.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class certificate_bo_completed_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test issue of certificates when bookingoption completed.
     *
     * @covers \mod_booking\booking_bookit::bookit
     * @covers \mod_booking\option\fields\certificate::issue_certificate
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

        if (!class_exists('tool_certificate\certificate')) {
            return;
        }

        // Set params requred for certificate issue.
        foreach ($data['configsettings'] as $configsetting) {
            set_config($configsetting['name'], $configsetting['value'], $configsetting['component']);
        }
        $standarddata = self::provide_standard_data();
        // Coursesettings.
        $courses = [];
        $this->setAdminUser();
        $certificate = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);
        foreach ($data['coursesettings'] as $shortname => $courssettings) {
            $course = $this->getDataGenerator()->create_course($courssettings); // Usually 1 course is sufficient.
            $courses[$shortname] = $course;
        };
        $users = [];
        foreach ($standarddata['users'] as $user) {
            $params = $standarddata['users']['params'] ?? [];
            $users[$user['name']] = $this->getDataGenerator()->create_user($params);
        }

        // Fetch standarddata for booking.
        $bdata = $standarddata['booking'];
        // Apply the custom settings for the first booking.
        if (isset($data['bookingsettings'])) {
            foreach ($data['bookingsettings'] as $key => $value) {
                $bdata[$key] = $value;
            }
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $users["bookingmanager"]->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // We enrol all users, this can be adapted if needed.
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option = $standarddata['option'];
        if (isset($data['optionsettings'])) {
            foreach ($data['optionsettings'] as $key => $value) {
                $option[$key] = $value;
            }
        }
        $expirydaterelative = $data['optionsettings']['certificatedata']['expirydaterelative'] ?? 0;
        $expirydateabsolute = $data['optionsettings']['certificatedata']['expirydateabsolute'] ?? 0;
        $expirydatetype = $data['optionsettings']['certificatedata']['expirydatetype'];
        $option['json'] = '{"certificate":' . $certificate->get_id() . ',
        "expirydateabsolute":' . $expirydateabsolute . ',
        "expirydatetype":' . $expirydatetype . ',
        "expirydaterelative":' . $expirydaterelative . '
        }';
        $option['bookingid'] = $booking1->id;
        $option['courseid'] = $course->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option((object) $option);

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
        $this->setAdminUser();
        if (empty($data['completionsettings']['multiple'])) {
            booking_activitycompletion([$student1->id], $booking1, $settings->cmid, $option1->id);
        } else {
            booking_activitycompletion([$student1->id, $student2->id], $booking1, $settings->cmid, $option1->id);
        }
        $certificates = $DB->get_records('tool_certificate_issues');
        $this->assertCount($expected['certcount'], $certificates);
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
        'certificate_setting_off' => [
            [
                'configsettings' => [
                    [
                        'component' => 'booking',
                        'name' => 'certificateon',
                        'value' => 0,
                    ],
                ],
                'coursesettings' => [
                    'firstcourse' => [
                        'enablecompletion' => 1,
                    ],
                ],
                'completionsettings' => [
                    'multiple' => 0,
                ],
                'optionsettings' => [
                        'useprice' => 0,
                        'certificatedata' => [
                            'expirydateabsolute' => strtotime('1 April 2085'),
                            'expirydatetype' => 1, // 2 is absolute expirydate
                        ],
                ],
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 0,
            ],
        ],
        'certificate_no_expirydate' => [
            [
                'configsettings' => [
                    [
                    'component' => 'booking',
                    'name' => 'certificateon',
                    'value' => 1,
                    ],
                ],
                'coursesettings' => [
                    'firstcourse' => [
                        'enablecompletion' => 1,
                    ],
                ],
                'completionsettings' => [
                    'multiple' => 0,
                ],
                'optionsettings' => [
                    'useprice' => 0,
                    'certificatedata' => [
                        'expirydatetype' => 0, // 0 is no expiry date.
                    ],
                ],
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 1,
            ],
        ],
        'certificate_absolute_expirydate' => [
            [
                'configsettings' => [
                    [
                        'component' => 'booking',
                        'name' => 'certificateon',
                        'value' => 1,
                    ],
                ],
                'coursesettings' => [
                    'firstcourse' => [
                        'enablecompletion' => 1,
                    ],
                ],
                'completionsettings' => [
                    'mutiple' => 0,
                ],
                'optionsettings' => [
                    'useprice' => 0,
                    'certificatedata' => [
                        'expirydateabsolute' => strtotime('1 April 2085'),
                        'expirydatetype' => 1, // 1 is absolute expirydate
                    ],
                ],
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 1,
            ],
        ],
        'certificate_relative_expirydate' => [
            [
                'configsettings' => [
                    [
                        'component' => 'booking',
                        'name' => 'certificateon',
                        'value' => 1,
                    ],
                ],
                'coursesettings' => [
                    'firstcourse' => [
                        'enablecompletion' => 1,
                    ],
                ],
                'completionsettings' => [
                    'mutiple' => 0,
                ],
                'optionsettings' => [
                    'useprice' => 0,
                    'certificatedata' => [
                        'expirydaterelative' => 60 * 60 * 24,
                        'expirydatetype' => 2, // 2 is relative expiry date.
                    ],
                ],
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 1,
            ],
        ],
        'certificate_absolute_expirydate_past' => [
            [
                'configsettings' => [
                    [
                        'component' => 'booking',
                        'name' => 'certificateon',
                        'value' => 1,
                    ],
                ],
                'coursesettings' => [
                    'firstcourse' => [
                        'enablecompletion' => 1,
                    ],
                ],
                'completionsettings' => [
                    'mutiple' => 0,
                ],
                'optionsettings' => [
                    'useprice' => 0,
                    'certificatedata' => [
                        'expirydateabsolute' => time() - 60,
                        'expirydatetype' => 1, // 1 is absolute expirydate
                    ],
                ],
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 0,
            ],
        ],
        'certificate_issue_multiple' => [
            [
                'configsettings' => [
                    [
                        'component' => 'booking',
                        'name' => 'certificateon',
                        'value' => 1,
                    ],
                ],
                'coursesettings' => [
                    'firstcourse' => [
                        'enablecompletion' => 1,
                    ],
                ],
                'completionsettings' => [
                    'multiple' => 1,
                ],
                'optionsettings' => [
                    'useprice' => 0,
                    'certificatedata' => [
                        'expirydaterelative' => 60 * 60 * 24,
                        'expirydatetype' => 2, // 2 is relative expiry date.
                    ],
                ],
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 2,
            ],
        ],
        'certificate_with_presencesettingon' => [
            [
                'configsettings' => [
                    [
                        'component' => 'booking',
                        'name' => 'certificateon',
                        'value' => 1,
                    ],
                    [
                        'component' => 'booking',
                        'name' => 'presencestatustoissuecertificate',
                        'value' => 1,
                    ],
                ],
                'coursesettings' => [
                    'firstcourse' => [
                        'enablecompletion' => 1,
                    ],
                ],
                'completionsettings' => [
                    'mutiple' => 0,
                ],
                'optionsettings' => [
                    'useprice' => 0,
                    'certificatedata' => [
                        'expirydateabsolute' => strtotime('1 April 2085'),
                        'expirydatetype' => 1, // 1 is absolute expirydate
                    ],
                ],
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 0, // When presencetoissuecertificate is on no certificate should be issued with completion.
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


    /**
     * Generator for Certificates.
     *
     * @return \component_generator_base
     *
     */
    protected function get_certificate_generator() {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }
}
