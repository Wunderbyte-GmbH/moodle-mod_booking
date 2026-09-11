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

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\mobile\customformstore;
use mod_booking\booking_option;
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
final class mobile_customform_test extends booking_advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
        $this->assertEmpty($errors, "Form validation should pass but got errors: " . implode(", ", $errors));
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Data provider for booking module settings.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Customform Validation Test',
            'eventtype' => 'Test',
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
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }

    /**
     * Insert a record directly into booking_answers for test setup.
     *
     * @param int $bookingid
     * @param int $optionid
     * @param int $userid
     * @param int $waitinglist  0 = booked, 1 = waitinglist, 2 = reserved
     * @param int $places       Number of places this answer consumes.
     */
    private function insert_booking_answer(
        int $bookingid,
        int $optionid,
        int $userid,
        int $waitinglist,
        int $places = 1
    ): void {
        global $DB;
        $record = new stdClass();
        $record->bookingid = $bookingid;
        $record->userid = $userid;
        $record->optionid = $optionid;
        $record->waitinglist = $waitinglist;
        $record->places = $places;
        $record->timemodified = time();
        $record->timecreated = time();
        $record->completed = 0;
        $record->frombookingid = 0;
        $record->numrec = 0;
        $record->status = 0;
        $DB->insert_record('booking_answers', $record);
    }

    /**
     * Test that enrolusersaction validation rejects zero and non-integer values.
     *
     * @covers \mod_booking\local\mobile\customformstore::validation
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolusersaction_validation_invalid_value(array $bdata): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata['course'] = $course->id;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option invalid value test';
        $record->maxanswers = 10;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        $option = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $customform = \mod_booking\bo_availability\conditions\customform::return_formelements($settings);

        $user = $this->getDataGenerator()->create_user();
        $customformstore = new customformstore($user->id, $option->id);

        // Value 0 → must produce error:chooseint.
        $data = ['id' => $option->id, 'userid' => $user->id, 'customform_enrolusersaction_1' => 0];
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayHasKey('customform_enrolusersaction_1', $errors);
        $this->assertEquals(
            get_string('error:chooseint', 'mod_booking'),
            $errors['customform_enrolusersaction_1']
        );

        // Non-numeric string → error:enrolusersactionnotnumeric.
        $data['customform_enrolusersaction_1'] = 'abc';
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayHasKey('customform_enrolusersaction_1', $errors);
        $this->assertEquals(
            get_string('error:enrolusersactionnotnumeric', 'mod_booking'),
            $errors['customform_enrolusersaction_1']
        );

        // Decimal with dot → error:enrolusersactionnotnumeric.
        $data['customform_enrolusersaction_1'] = '1.5';
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayHasKey('customform_enrolusersaction_1', $errors);
        $this->assertEquals(
            get_string('error:enrolusersactionnotnumeric', 'mod_booking'),
            $errors['customform_enrolusersaction_1']
        );

        // Decimal with comma → error:enrolusersactionnotnumeric.
        $data['customform_enrolusersaction_1'] = '1,5';
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayHasKey('customform_enrolusersaction_1', $errors);
        $this->assertEquals(
            get_string('error:enrolusersactionnotnumeric', 'mod_booking'),
            $errors['customform_enrolusersaction_1']
        );

        // Negative value → fails integer-only regex → error:enrolusersactionnotnumeric.
        $data['customform_enrolusersaction_1'] = '-3';
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayHasKey('customform_enrolusersaction_1', $errors);
        $this->assertEquals(
            get_string('error:enrolusersactionnotnumeric', 'mod_booking'),
            $errors['customform_enrolusersaction_1']
        );
    }

    /**
     * Test that capacity enforcement counts both booked and reserved users.
     *
     * Scenario: maxanswers=5, 2 booked + 1 reserved → freeonlist=2.
     * - value 2 → allowed (exactly at free capacity)
     * - value 3 → rejected with capacity error mentioning 2 free places
     *
     * @covers \mod_booking\local\mobile\customformstore::validation
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolusersaction_validation_capacity_enforcement(array $bdata): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata['course'] = $course->id;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option capacity enforcement';
        $record->maxanswers = 5;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        $option = $plugingenerator->create_option($record);

        // 2 booked + 1 reserved → usersonlist=3, freeonlist = 5 - 3 = 2.
        $this->insert_booking_answer(
            $booking->id,
            $option->id,
            $this->getDataGenerator()->create_user()->id,
            MOD_BOOKING_STATUSPARAM_BOOKED
        );
        $this->insert_booking_answer(
            $booking->id,
            $option->id,
            $this->getDataGenerator()->create_user()->id,
            MOD_BOOKING_STATUSPARAM_BOOKED
        );
        $this->insert_booking_answer(
            $booking->id,
            $option->id,
            $this->getDataGenerator()->create_user()->id,
            MOD_BOOKING_STATUSPARAM_RESERVED
        );

        // Purge cache and PHP singleton so validation reads fresh DB data.
        booking_option::purge_cache_for_answers($option->id);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $customform = \mod_booking\bo_availability\conditions\customform::return_formelements($settings);

        $user = $this->getDataGenerator()->create_user();
        $customformstore = new customformstore($user->id, $option->id);
        $basedata = ['id' => $option->id, 'userid' => $user->id];

        // Value 2 = exactly freeonlist → no error.
        $data = $basedata + ['customform_enrolusersaction_1' => 2];
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayNotHasKey(
            'customform_enrolusersaction_1',
            $errors,
            'Value 2 should be within capacity (freeonlist=2).'
        );

        // Purge cache and answers singleton so the next validation reads fresh from DB.
        booking_option::purge_cache_for_answers($option->id);
        singleton_service::destroy_booking_answers($option->id);

        // Value 3 > freeonlist=2 → must fail.
        $data = $basedata + ['customform_enrolusersaction_1' => 3];
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayHasKey(
            'customform_enrolusersaction_1',
            $errors,
            'Value 3 should exceed capacity (freeonlist=2).'
        );
        $this->assertEquals(
            get_string('error:enrolusersactionexceedscapacity', 'mod_booking', 2),
            $errors['customform_enrolusersaction_1'],
            'Error message should report 2 free places.'
        );
    }

    /**
     * Test that reserved users alone are counted towards capacity.
     *
     * Scenario: maxanswers=5, 0 booked + 4 reserved → freeonlist=1.
     * - value 1 → allowed
     * - value 2 → rejected (proves reservations are counted, not ignored)
     *
     * @covers \mod_booking\local\mobile\customformstore::validation
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolusersaction_validation_reserved_counted(array $bdata): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata['course'] = $course->id;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option reserved counted';
        $record->maxanswers = 5;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        $option = $plugingenerator->create_option($record);

        // 0 booked, 4 reserved → usersonlist=4, freeonlist = 5 - 4 = 1.
        for ($i = 0; $i < 4; $i++) {
            $this->insert_booking_answer(
                $booking->id,
                $option->id,
                $this->getDataGenerator()->create_user()->id,
                MOD_BOOKING_STATUSPARAM_RESERVED
            );
        }

        // Purge cache and PHP singleton so validation reads fresh DB data.
        booking_option::purge_cache_for_answers($option->id);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $customform = \mod_booking\bo_availability\conditions\customform::return_formelements($settings);

        $user = $this->getDataGenerator()->create_user();
        $customformstore = new customformstore($user->id, $option->id);
        $basedata = ['id' => $option->id, 'userid' => $user->id];

        // Value 1 = freeonlist → no error.
        $data = $basedata + ['customform_enrolusersaction_1' => 1];
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayNotHasKey(
            'customform_enrolusersaction_1',
            $errors,
            'Value 1 should be within capacity (only 1 place free due to 4 reservations).'
        );

        booking_option::purge_cache_for_answers($option->id);
        singleton_service::destroy_booking_answers($option->id);

        // Value 2 > freeonlist=1 → error. Proves reserved users are counted.
        $data = $basedata + ['customform_enrolusersaction_1' => 2];
        $errors = $customformstore->validation($customform, $data);
        $this->assertArrayHasKey(
            'customform_enrolusersaction_1',
            $errors,
            'Value 2 should exceed capacity (only 1 free, 4 reservations not counted would wrongly allow this).'
        );
        $this->assertEquals(
            get_string('error:enrolusersactionexceedscapacity', 'mod_booking', 1),
            $errors['customform_enrolusersaction_1'],
            'Error message should report 1 free place left.'
        );
    }

    /**
     * Test that unlimited maxanswers (0) skips capacity check entirely.
     *
     * @covers \mod_booking\local\mobile\customformstore::validation
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolusersaction_validation_unlimited_maxanswers(array $bdata): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata['course'] = $course->id;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option unlimited maxanswers';
        // Maxanswers not set, defaults to 0 (unlimited).
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        $option = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->assertEquals(0, $settings->maxanswers, 'maxanswers should be 0 (unlimited).');

        $customform = \mod_booking\bo_availability\conditions\customform::return_formelements($settings);

        $user = $this->getDataGenerator()->create_user();
        $customformstore = new customformstore($user->id, $option->id);
        $basedata = ['id' => $option->id, 'userid' => $user->id];

        // Any positive value → no capacity error when maxanswers is unlimited.
        foreach ([1, 50, 999] as $value) {
            $data = $basedata + ['customform_enrolusersaction_1' => $value];
            $errors = $customformstore->validation($customform, $data);
            $this->assertArrayNotHasKey(
                'customform_enrolusersaction_1',
                $errors,
                "Value $value should be allowed when maxanswers is unlimited (0)."
            );
        }
    }
}
