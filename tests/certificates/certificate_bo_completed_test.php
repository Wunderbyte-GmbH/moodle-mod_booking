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
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use mod_booking\event\certificate_issued;

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
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Test issue of certificates when bookingoption completed.
     *
     * @covers \mod_booking\booking_bookit::bookit
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
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
        $student1 = $users['student1'];

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
        // Create first option which is required for certificate issuance in second option.
        $option1 = $standarddata['option'];
        $option1['bookingid'] = $booking1->id;
        $option1['courseid'] = $course->id;
        $option1['text'] = 'first option';
        $option1obj = $plugingenerator->create_option((object) $option1);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1obj->id);

        if (isset($data['completeotheroption'])) {
            $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
            $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
            $bookingoption1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
            $bookingoption1->toggle_user_completion($student1->id);
            // We book a second option.
            $option2 = $standarddata['option'];
            $option2['bookingid'] = $booking1->id;
            $option2['courseid'] = $course->id;
            $option2['text'] = 'second option';
            $option2obj = $plugingenerator->create_option((object) $option2);
            $settings2 = singleton_service::get_instance_of_booking_option_settings($option2obj->id);
            $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
            $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
            // We only book the other user if allotheroptionscompleted is set.
            if (!empty($data['allotheroptionscompleted'])) {
                $bookingoption2 = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
                $bookingoption2->toggle_user_completion($student1->id);
            }
        }

        $option = $standarddata['option'];
        if (isset($data['optionsettings'])) {
            foreach ($data['optionsettings'] as $key => $value) {
                $option[$key] = $value;
            }
        }

        $otherrequiredoptions = [];
        if (isset($data['optionsettings']['certificatedata']['otherrequiredoptions'])) {
            $otherrequiredoptions[] = $option1obj->id;
            // Add second option if it was created.
            if (isset($option2obj)) {
                $otherrequiredoptions[] = $option2obj->id;
            }
        }

        $expirydaterelative = $data['optionsettings']['certificatedata']['expirydaterelative'] ?? 0;
        $expirydateabsolute = $data['optionsettings']['certificatedata']['expirydateabsolute'] ?? 0;
        $expirydatetype = $data['optionsettings']['certificatedata']['expirydatetype'];

        if (!empty($otherrequiredoptions)) {
            $certificaterequiredmode = $data['optionsettings']['certificatedata']['certificaterequiredoptionsmode'] ?? 0;
            $requiredoptionsstr = implode('","', $otherrequiredoptions);
            $option['json'] = '{"certificate":' . $certificate->get_id() . ',
            "expirydateabsolute":' . $expirydateabsolute . ',
            "expirydatetype":' . $expirydatetype . ',
            "expirydaterelative":' . $expirydaterelative . ',
            "certificaterequiresotheroptions":["' . $requiredoptionsstr . '"],
            "certificaterequiredoptionsmode":' . $certificaterequiredmode . '}';
        } else {
            $option['json'] = '{"certificate":' . $certificate->get_id() . ',
            "expirydateabsolute":' . $expirydateabsolute . ',
            "expirydatetype":' . $expirydatetype . ',
            "expirydaterelative":' . $expirydaterelative . '}';
        }

        $option['bookingid'] = $booking1->id;
        $option['courseid'] = $course->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option((object) $option);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // So far for the basic setup.
        // Now proceed to logic of the testcase.
        // Book the users.
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
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $sink = $this->redirectEvents();

        if (empty($data['completionsettings']['multiple'])) {
            $bookingoption->toggle_user_completion($student1->id);
        } else {
            $bookingoption->toggle_users_completion([$student1->id, $student2->id]);
        }
        $certificates = $DB->get_records('tool_certificate_issues');

        // Check if file was created.
        foreach ($certificates as $issue) {
            $filestorage = get_file_storage();
            $file = (object) [
                'contextid' => \context_system::instance()->id,
                'component' => 'tool_certificate',
                'filearea'  => 'issues',
                'itemid'    => $issue->id,
                'filepath'  => '/',
                'filename'  => $issue->code . '.pdf',
            ];
            $storedfile = $filestorage->get_file(
                $file->contextid,
                $file->component,
                $file->filearea,
                $file->itemid,
                $file->filepath,
                $file->filename
            );
            $this->assertNotEmpty($storedfile, 'No stored file found');
        }
        $this->assertCount($expected['certcount'], $certificates);

        // Get captured events.
        $events = $sink->get_events();
        $sink->close();

        // Filter for certificate_issued events.
        $certificateissudevents = array_filter($events, function ($event) {
            return $event instanceof certificate_issued;
        });
        $this->assertCount($expected['certcount'], $certificateissudevents);

        self::teardown();
    }

    /**
     * Test issue of certificates when bookingoption completed.
     *
     * @covers \mod_booking\booking_bookit::bookit
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     *
     * @param array $data
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider_action
     */
    public function test_certificate_action(array $data): void {
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
        $student1 = $users['student1'];

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
        // Create first option which is required for certificate issuance in second option.
        $option1 = $standarddata['option'];
        $option1['bookingid'] = $booking1->id;
        $option1['courseid'] = $course->id;
        $option1['text'] = 'first option';
        $option1obj = $plugingenerator->create_option((object) $option1);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1obj->id);

        if (isset($data['completeotheroption']) && !empty($data['allotheroptionscompleted'])) {
            $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
            $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
            $bookingoption1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
            $bookingoption1->toggle_user_completion($student1->id);
        }

        $option = $standarddata['option'];
        if (isset($data['optionsettings'])) {
            foreach ($data['optionsettings'] as $key => $value) {
                $option[$key] = $value;
            }
        }

        $otherrequiredoptions = isset($data['optionsettings']['certificatedata']['otherrequiredoptions']) ? $option1obj->id : 0;

        $expirydaterelative = $data['optionsettings']['certificatedata']['expirydaterelative'] ?? 0;
        $expirydateabsolute = $data['optionsettings']['certificatedata']['expirydateabsolute'] ?? 0;
        $expirydatetype = $data['optionsettings']['certificatedata']['expirydatetype'];
        $option['json'] = '{"certificate":' . $certificate->get_id() . ',
        "expirydateabsolute":' . $expirydateabsolute . ',
        "expirydatetype":' . $expirydatetype . ',
        "expirydaterelative":' . $expirydaterelative . '
        ,"certificaterequiresotheroptions":["' . $otherrequiredoptions . '"]}';

        $option['bookingid'] = $booking1->id;
        $option['courseid'] = $course->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option((object) $option);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // So far for the basic setup.
        // Now proceed to logic of the testcase.
        // Book the users.
        $this->setUser($users['student1']);
        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        $this->setAdminUser();
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $sink = $this->redirectEvents();
        $bookingoption->toggle_user_completion($student1->id);
        $certificates = $DB->get_records('tool_certificate_issues');
        // Get captured events.
        $events = $sink->get_events();

        // Filter for certificate_issued events.
        $certificateissudevents = array_filter($events, function ($event) {
            return $event instanceof certificate_issued;
        });
        // No certificates issues because "presencestatustoissuecertificate" setting was set to status 3.
        $this->assertEmpty($certificates);
        $this->assertEmpty($certificateissudevents);

        set_config('presencestatustoissuecertificate', '0', 'booking');

        // That's the only answer we need to the action.
        $answer = $DB->get_record('booking_answers', ['userid' => $student1->id]);
        $table = new manageusers_table('manageuserstable');
        $actiondata = '{"type":"wb_action_button","id":"-1","methodname":"trigger_certificate_booking_answers",' .
        '"formname":"","nomodal":"0","selectionmandatory":"1","titlestring":"issuecertificate",'
        . '"bodystring":"issuecertificatebody","submitbuttonstring":"apply",'
        . '"component":"mod_booking","initialized":"true","checkedids":["' . $answer->id . '"]}';
        $table->action_trigger_certificate_booking_answers(0, $actiondata);
        $certificates = $DB->get_records('tool_certificate_issues');

        $events = $sink->get_events();
        $certificateissudevents = array_filter($events, function ($event) {
            return $event instanceof certificate_issued;
        });

        $this->assertNotEmpty($certificates);
        $this->assertNotEmpty($certificateissudevents);

        $sink->close();
        self::teardown();
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider_action(): array {
        return [
        'toggle_via_table' => [
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
                        'value' => 3,
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
            ],
        ];
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
        'other_option_required_not_fulfilled' => [
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
                        'expirydatetype' => 0,
                        'otherrequiredoptions' => 1, // Here is the requirement.
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
        'other_option_required_that_is_fulfilled_with_mode_all' => [
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
                        'expirydatetype' => 0,
                        'otherrequiredoptions' => 1, // Here is the requirement.
                        'certificaterequiredoptionsmode' => 0, // All required options must be completed.
                    ],
                ],
                'completeotheroption' => 1,
                'allotheroptionscompleted' => 1,
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 1,
            ],
        ],
        'other_option_required_that_is_fulfilled_with_mode_one' => [
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
                        'expirydatetype' => 0,
                        'otherrequiredoptions' => 1, // Here is the requirement.
                        'certificaterequiredoptionsmode' => 1, // One required options must be completed.
                    ],
                ],
                'completeotheroption' => 1,
                'allotheroptionscompleted' => 0,
            ],
            [
                'bookitresults' => [
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ],
                    'certcount' => 1,
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
     * Generator for Certificates.
     *
     * @return \component_generator_base
     *
     */
    protected function get_certificate_generator() {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }
}
