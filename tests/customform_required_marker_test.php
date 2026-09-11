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
 * Tests that mandatory custom form fields are marked as required in the prepage modal.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\condition\customform_form;
use mod_booking\tests\booking_advanced_testcase;
use ReflectionProperty;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests that mandatory custom form fields are marked as required in the prepage modal.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class customform_required_marker_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Elements with notempty carry the core required marker, the others do not.
     *
     * @covers \mod_booking\form\condition\customform_form::definition
     */
    public function test_mandatory_elements_are_marked_as_required(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Test Booking',
            'eventtype' => 'Test event',
        ]);

        $option = new stdClass();
        $option->bookingid = $booking->id;
        $option->identifier = uniqid();
        $option->text = 'Test Option';
        $option->description = '<p>Test</p>';
        $option->address = '';
        $option->location = '';
        $option->institution = '';
        $optionid = $DB->insert_record('booking_options', $option);

        // One mandatory element per supported type, one optional one and a static text.
        // The static text carries notempty on purpose: it has no input, so it must stay untouched.
        $formsarray = new stdClass();
        $definitions = [
            1 => ['formtype' => 'shorttext', 'notempty' => 1, 'value' => ''],
            2 => ['formtype' => 'select', 'notempty' => 1, 'value' => "V1 => O1\nV2 => O2"],
            3 => ['formtype' => 'advcheckbox', 'notempty' => 1, 'value' => ''],
            4 => ['formtype' => 'mail', 'notempty' => 1, 'value' => ''],
            5 => ['formtype' => 'url', 'notempty' => 1, 'value' => ''],
            6 => ['formtype' => 'shorttext', 'notempty' => 0, 'value' => ''],
            7 => ['formtype' => 'static', 'notempty' => 1, 'value' => 'Just a text'],
        ];
        foreach ($definitions as $index => $definition) {
            $formsarray->{$index} = (object)($definition + ['label' => 'Field ' . $index]);
        }

        $bookingoption = $DB->get_record('booking_options', ['id' => $optionid]);
        $bookingoption->availability = json_encode([(object)[
            'id' => MOD_BOOKING_BO_COND_JSON_CUSTOMFORM,
            'formsarray' => ['1' => $formsarray],
        ]]);
        $DB->update_record('booking_options', $bookingoption);

        singleton_service::destroy_instance();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $form = new customform_form(null, null, 'post', '', [], true, [
            'id' => (string)$optionid,
            'userid' => (string)$user->id,
        ], true);

        $property = new ReflectionProperty($form, '_form');
        $property->setAccessible(true);
        /** @var \MoodleQuickForm $mform */
        $mform = $property->getValue($form);

        foreach ($definitions as $index => $definition) {
            $identifier = 'customform_' . $definition['formtype'] . '_' . $index;
            $this->assertTrue($mform->elementExists($identifier), 'Element ' . $identifier . ' is missing.');
            if (!empty($definition['notempty']) && $definition['formtype'] !== 'static') {
                $this->assertTrue(
                    $mform->isElementRequired($identifier),
                    'Mandatory element ' . $identifier . ' is not marked as required.'
                );
            } else {
                $this->assertFalse(
                    $mform->isElementRequired($identifier),
                    'Optional element ' . $identifier . ' must not be marked as required.'
                );
            }
        }
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
}
