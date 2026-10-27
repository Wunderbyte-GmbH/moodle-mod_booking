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

use Closure;
use mod_booking\form\option_form;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodleform;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Copying a slot booking option must carry its slot settings into the copy.
 *
 * The copy is opened like editoptions.php does it: optionid 0 (a new option) and copyoptionid pointing
 * at the source. The form is defined from exactly these arguments, so the slot settings only reach the
 * copy if the slot fields are part of the form already while the option type is not known yet.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\option\fields\slotbooking::instance_form_definition
 */
final class option_form_copy_slot_test extends booking_advanced_testcase {
    /** @var int cmid of the booking instance */
    private int $cmid;

    /** @var int id of the booking instance */
    private int $bookingid;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The form of a copy of a slot option holds the slot fields with the values of the source.
     *
     * @return void
     */
    public function test_copy_form_carries_slot_settings(): void {
        $sourceid = $this->create_slot_option();

        $form = $this->load_copy_form($sourceid);
        $mform = Closure::bind(fn() => $this->_form, $form, moodleform::class)();

        $this->assertTrue($mform->elementExists('slot_type'), 'The copy form has no slot fields.');
        $this->assertSame('fixed', $mform->exportValue('slot_type'));
        $this->assertSame('09:00', $mform->exportValue('slot_opening_time'));
        $this->assertSame('12:00', $mform->exportValue('slot_closing_time'));
        $this->assertEquals(2, $mform->exportValue('slot_max_participants_per_slot'));
        $this->assertEquals(3, $mform->exportValue('slot_max_slots_per_user'));
        $this->assertEquals(1, $mform->exportValue('slot_day_1'));
        $this->assertEquals(0, $mform->exportValue('slot_day_2'));
        $this->assertEquals(1, $mform->exportValue('slot_day_5'));
    }

    /**
     * A copy of a normal option does not get slot fields.
     *
     * @return void
     */
    public function test_copy_form_of_normal_option_has_no_slot_fields(): void {
        $this->create_slot_option();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $normal = $plugingenerator->create_option((object)[
            'bookingid' => $this->bookingid,
            'text' => 'Normal option',
            'course' => 0,
            'maxanswers' => 5,
        ]);
        singleton_service::destroy_instance();

        $form = $this->load_copy_form((int)$normal->id);
        $mform = Closure::bind(fn() => $this->_form, $form, moodleform::class)();

        $this->assertFalse($mform->elementExists('slot_type'));
    }

    /**
     * Opens the form like editoptions.php does for "duplicate booking option".
     *
     * @param int $copyoptionid
     * @return option_form
     */
    private function load_copy_form(int $copyoptionid): option_form {
        singleton_service::destroy_instance();
        $args = [
            'cmid' => $this->cmid,
            'id' => 0,
            'optionid' => 0,
            'bookingid' => $this->bookingid,
            'copyoptionid' => $copyoptionid,
            'returnurl' => '',
        ];
        $form = new option_form(null, null, 'post', '', [], true, $args, true);
        $form->set_data_for_dynamic_submission();
        return $form;
    }

    /**
     * Creates a booking instance with one fixed slot option.
     *
     * @return int option id
     */
    private function create_slot_option(): int {
        $course = self::getDataGenerator()->create_course();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $this->bookingid = (int)$booking->id;
        $this->cmid = (int)$booking->cmid;

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot option to copy',
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-31 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 2,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'calendar',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, [1, 5], true) ? 1 : 0;
        }

        $option = $plugingenerator->create_option((object)$record);
        singleton_service::destroy_instance();

        return (int)$option->id;
    }
}
