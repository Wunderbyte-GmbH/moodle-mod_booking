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

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\bo_availability\bo_info;
use mod_booking\local\mobile\customformstore;

/**
 * Tests for all_userbookings::other_cols.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class all_userbookings_test extends advanced_testcase {
    /**
     * Test setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
     * Covers the formfield_ branch for all customform field subtypes.
     *
     * @dataProvider other_cols_formfield_provider
     * @covers \mod_booking\all_userbookings::other_cols
     * @covers \mod_booking\bo_availability\conditions\customform::get_customform_field_value
     *
     * @param array $bookinginstance
     * @param array $bookingoption
     * @param array $bookinganswer
     * @param array $scenario
     */
    public function test_other_cols_formfield_all_subtypes(
        array $bookinginstance,
        array $bookingoption,
        array $bookinganswer,
        array $scenario
    ): void {
        global $DB;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();

        $bookinginstance['course'] = $course->id;
        $booking = $this->getDataGenerator()->create_module('booking', $bookinginstance);

        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->courseid = $course->id;
        $record->text = 'Option for ' . $scenario['formtype'];
        $record->description = 'Option for ' . $scenario['formtype'];
        $record->chooseorcreatecourse = 1;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = $scenario['formtype'];
        $record->bo_cond_customform_label_1_1 = $scenario['label'];

        if (!empty($scenario['fieldvalue'])) {
            $record->bo_cond_customform_value_1_1 = $scenario['fieldvalue'];
        }

        foreach ($bookingoption as $key => $value) {
            $record->{$key} = $value;
        }

        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Book the student.
        $this->setUser($student);
        $result = booking_bookit::bookit('option', $settings->id, $student->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $id);

        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $student->id,
            $scenario['answerkey'] => $scenario['answervalue'],
        ];
        $customformstore = new customformstore($student->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        $result = booking_bookit::bookit('option', $settings->id, $student->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Validate the stored customform answer in the database.
        $this->setAdminUser();
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        // Validate student's response.
        $bookedusers = $option->get_all_users_booked();
        $this->assertCount(1, $bookedusers);
        $bookeduser = reset($bookedusers);
        $this->assertSame($scenario['expected'], $bookeduser->{$scenario['answerkey']});

        $bookingoptioninstance = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $cm = get_coursemodule_from_id('booking', $settings->cmid, 0, false, MUST_EXIST);

        $table = new all_userbookings('test-' . $scenario['formtype'], $bookingoptioninstance, $cm, $settings->id);

        $values = (object)[
            'optionid' => $settings->id,
            'userid' => $student->id,
        ];

        $actual = $table->other_cols('formfield_1', $values);
        $this->assertSame($scenario['expected'], $actual);
    }

    /**
     * Data provider for formfield_* scenarios.
     *
     * @return array
     */
    public static function other_cols_formfield_provider(): array {
        $bookinginstance = [
            'name' => 'Booking for all_userbookings::other_cols',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        $bookingoption = [
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'maxanswers' => 5,
            'maxoverbooking' => 0,
        ];
        $bookinganswer = ['waitinglist' => 0];

        return [
            'static' => [
                $bookinginstance,
                $bookingoption,
                $bookinganswer,
                [
                    'formtype' => 'static',
                    'label' => 'Static label',
                    'fieldvalue' => 'Static display',
                    'answerkey' => 'customform_static_1',
                    'answervalue' => 'Static answer',
                    'expected' => 'Static answer',
                ],
            ],
            'advcheckbox' => [
                $bookinginstance,
                $bookingoption,
                $bookinganswer,
                [
                    'formtype' => 'advcheckbox',
                    'label' => 'Accept terms',
                    'fieldvalue' => '',
                    'answerkey' => 'customform_advcheckbox_1',
                    'answervalue' => '1',
                    'expected' => '1',
                ],
            ],
            'shorttext' => [
                $bookinginstance,
                $bookingoption,
                $bookinganswer,
                [
                    'formtype' => 'shorttext',
                    'label' => 'Short text',
                    'fieldvalue' => 'Default text',
                    'answerkey' => 'customform_shorttext_1',
                    'answervalue' => 'My custom text',
                    'expected' => 'My custom text',
                ],
            ],
            'select' => [
                $bookinginstance,
                $bookingoption,
                $bookinganswer,
                [
                    'formtype' => 'select',
                    'label' => 'Choose one',
                    'fieldvalue' => "A" . PHP_EOL . "B" . PHP_EOL . "C",
                    'answerkey' => 'customform_select_1',
                    'answervalue' => 'B',
                    'expected' => 'B',
                ],
            ],
            'url' => [
                $bookinginstance,
                $bookingoption,
                $bookinganswer,
                [
                    'formtype' => 'url',
                    'label' => 'Website',
                    'fieldvalue' => '',
                    'answerkey' => 'customform_url_1',
                    'answervalue' => 'https://example.org',
                    'expected' => 'https://example.org',
                ],
            ],
            'mail' => [
                $bookinginstance,
                $bookingoption,
                $bookinganswer,
                [
                    'formtype' => 'mail',
                    'label' => 'Email',
                    'fieldvalue' => '',
                    'answerkey' => 'customform_mail_1',
                    'answervalue' => 'student@example.org',
                    'expected' => 'student@example.org',
                ],
            ],
            'deleteinfoscheckboxuser' => [
                $bookinginstance,
                $bookingoption,
                $bookinganswer,
                [
                    'formtype' => 'deleteinfoscheckboxuser',
                    'label' => 'Delete info',
                    'fieldvalue' => '',
                    'answerkey' => 'customform_deleteinfoscheckboxuser_1',
                    'answervalue' => '1',
                    'expected' => '1',
                ],
            ],
            // Type 'enrolusersaction' skipped because dedicated tests already available - rules_enrollink_test. 
        ];
    }
}
