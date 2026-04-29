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
 * Tests for mobile booking with custom forms.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\mobile\customformstore;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for mobile booking with custom forms.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mobile_customform_test extends advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Test mobile booking with custom form.
     *
     * @covers \mod_booking\local\mobile\customformstore::set_customform_data
     * @covers \mod_booking\local\mobile\customformstore::validation
     * @covers \mod_booking\bo_availability\conditions\customform::return_formelements
     */
    public function test_mobile_booking_with_custom_form(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $bdata = [
            'course' => $course->id,
            'name' => 'Test Booking',
            'eventtype' => 'Test event',
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        // Create booking option directly.
        $option = new stdClass();
        $option->bookingid = $booking->id;
        $option->identifier = uniqid();
        $option->text = 'Test Option';
        $option->description = '<p>Test</p>';
        $option->address = '';
        $option->location = '';
        $option->institution = '';

        $optionid = $DB->insert_record('booking_options', $option);

        // Create custom form with 4 fields.
        $formsarray = new stdClass();
        for ($i = 1; $i <= 4; $i++) {
            $formsarray->{$i} = new stdClass();
            if ($i <= 2) {
                $formsarray->{$i}->formtype = 'select';
                $formsarray->{$i}->value = "V1 => O1\nV2 => O2";
                $formsarray->{$i}->notempty = 1;
            } else if ($i === 3) {
                $formsarray->{$i}->formtype = 'shorttext';
                $formsarray->{$i}->value = '';
                $formsarray->{$i}->notempty = 0;
            } else {
                $formsarray->{$i}->formtype = 'advcheckbox';
                $formsarray->{$i}->value = '';
                $formsarray->{$i}->notempty = 1;
            }
            $formsarray->{$i}->label = "Field $i";
        }

        $availability = json_encode([(object)[
            'id' => MOD_BOOKING_BO_COND_JSON_CUSTOMFORM,
            'formsarray' => ['1' => $formsarray],
        ]]);

        $bookingoption = $DB->get_record('booking_options', ['id' => $optionid]);
        $bookingoption->availability = $availability;
        $DB->update_record('booking_options', $bookingoption);

        singleton_service::destroy_instance();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $customformstore = new customformstore($user->id, $optionid);
        $formdata = (object)[
            'id' => $optionid,
            'userid' => $user->id,
            'customform_select_1' => 'V1',
            'customform_select_2' => 'V1',
            'customform_shorttext_3' => 'text',
            'customform_advcheckbox_4' => 1,
        ];
        $customformstore->set_customform_data($formdata);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $customform = \mod_booking\bo_availability\conditions\customform::return_formelements($settings);

        $errors = $customformstore->validation($customform, (array)$formdata);
        if (empty($errors)) {
            $this->assertTrue(true);
        } else {
            // Print returned validation errors for easier debugging.
            echo "✗ Validation errors:\n";
            foreach ($errors as $field => $error) {
                echo "  - $field: $error\n";
            }
            $this->fail("Form validation should pass but got errors: " . implode(", ", $errors));
        }
    }
}
