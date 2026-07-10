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

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\output\prepageinlinestart;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that [bookingoptionview ... inlinestartpage=slotbooking] keeps showing the slot
 * calendar even after the option is (fully) booked, instead of falling back to a bare
 * Book/Cancel button - the slotbooking condition is designed to stay visible throughout the
 * whole flow (see bo_availability\conditions\slotbooking::is_available()), but the generic
 * prepage pipeline used to stop offering it as soon as it was no longer the blocking condition.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_bookit::render_bookit_template_data
 */
final class slot_persistent_calendar_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Before any booking, inlinestartpage=slotbooking renders exactly the calendar (the
     * existing, unchanged "still bookable" path) - a single template entry.
     *
     * @return void
     */
    public function test_calendar_renders_alone_before_any_booking(): void {
        [$optionid, $userid] = $this->create_fixed_slot_option();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        [$templates, $datas] = booking_bookit::render_bookit_template_data($settings, $userid, true, 'slotbooking');

        $this->assertSame(['mod_booking/bookingpage/prepageinlinestart'], $templates);
        $this->assertInstanceOf(prepageinlinestart::class, $datas[0]);
    }

    /**
     * When the caller does not want the normal prepage-modal flow (e.g. because it is no
     * longer applicable once the option is booked), inlinestartpage=slotbooking must still
     * include the calendar ahead of whatever button/cancel controls the caller renders,
     * instead of the calendar being silently dropped - this is the regression guard for the
     * reported bug (calendar disappearing, replaced by a bare Book/Cancel button, once a slot
     * is booked).
     *
     * @return void
     */
    public function test_calendar_still_renders_when_prepage_modal_flow_is_off(): void {
        [$optionid, $userid] = $this->create_fixed_slot_option();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $this->assertNotEmpty($slots);
        $this->create_booked_slot_answer(
            $optionid,
            (int)$settings->bookingid,
            $userid,
            (int)$slots[0]['start'],
            (int)$slots[0]['end']
        );
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        [$templates, $datas] = booking_bookit::render_bookit_template_data($settings, $userid, false, 'slotbooking');

        $this->assertNotEmpty($templates);
        $this->assertSame(
            'mod_booking/bookingpage/prepageinlinestart',
            $templates[0],
            'The calendar must be the first rendered template even when the prepage-modal flow is off.'
        );
        $this->assertInstanceOf(prepageinlinestart::class, $datas[0]);
        $this->assertGreaterThan(
            1,
            count($templates),
            'The button/cancel controls must still render alongside the calendar, not be replaced by it.'
        );
    }

    /**
     * inlinestartpage=slotbooking must be a no-op for an option that has no slot booking
     * configured at all - the persistent-calendar behaviour is scoped to slotconfig, not to
     * every option that happens to receive this parameter.
     *
     * @return void
     */
    public function test_no_persistent_calendar_for_non_slot_option(): void {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Non-slot option ' . uniqid('', true),
            'course' => $course->id,
            'maxanswers' => 5,
        ]);
        $user = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        [$templates] = booking_bookit::render_bookit_template_data($settings, $user->id, true, 'slotbooking');

        $this->assertNotContains('mod_booking/bookingpage/prepageinlinestart', $templates);
    }

    /**
     * When the user already has a cancellable booked slot AND slot capacity remains (so the
     * inline calendar is shown to buy another slot), the "Cancel purchase" button must still be
     * surfaced alongside the calendar - this is the regression guard for the reported bug where
     * it disappeared entirely once alreadybooked/cancelmyself were fixed to no longer force the
     * flat "Booked + Cancel" fallback (which happened to render both) once slot capacity remained
     * and the flow switched to the inline-calendar path instead.
     *
     * @return void
     */
    public function test_cancel_button_shown_alongside_inline_calendar_when_capacity_remains(): void {
        global $DB;

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 10.0,
            'pricecatsortorder' => 1,
        ]);

        [$optionid, $userid] = $this->create_fixed_slot_option([
            'slot_max_slots_per_user' => 3,
            'useprice' => 1,
        ]);
        $bookingid = $this->get_bookingid($optionid);
        $DB->set_field('booking', 'cancancelbook', 1, ['id' => $bookingid]);
        $settingsforcmid = singleton_service::get_instance_of_booking_option_settings($optionid);
        \cache::make('mod_booking', 'cachedbookinginstances')->delete((int)$settingsforcmid->cmid);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $this->assertNotEmpty($slots);

        $this->create_booked_slot_answer(
            $optionid,
            (int)$settings->bookingid,
            $userid,
            (int)$slots[0]['start'],
            (int)$slots[0]['end']
        );
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        [$templates, $datas] = booking_bookit::render_bookit_template_data($settings, $userid, true, 'slotbooking');

        $this->assertContains('mod_booking/bookingpage/prepageinlinestart', $templates);
        $this->assertContains(
            'mod_booking/bookit_button',
            $templates,
            'The cancel button must be rendered alongside the inline calendar, not dropped.'
        );

        $cancelindex = array_search('mod_booking/bookit_button', $templates, true);
        $this->assertNotFalse($cancelindex);
        $canceldata = $datas[$cancelindex]->data ?? [];
        $class = (string)($canceldata['main']['class'] ?? '');
        $this->assertStringContainsString('bo-cancel-button', $class);
    }

    /**
     * Resolve the bookingid for an option, needed to flip the cancancelbook instance setting.
     *
     * @param int $optionid booking option id
     * @return int
     */
    private function get_bookingid(int $optionid): int {
        global $DB;
        return (int) $DB->get_field('booking_options', 'bookingid', ['id' => $optionid], MUST_EXIST);
    }

    /**
     * Insert a booked slot answer directly (bypassing the full checkout flow, matching the
     * pattern used by the other slotbooking tests, e.g. slot_move_service_provider_test).
     *
     * @param int $optionid booking option id
     * @param int $bookingid booking instance id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @param int $end slot end timestamp
     * @return int booking answer id
     */
    private function create_booked_slot_answer(int $optionid, int $bookingid, int $userid, int $start, int $end): int {
        global $DB;

        $answer = (object) [
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $start,
            'enddate' => $end,
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => [['start' => $start, 'end' => $end]], 'teachers' => []]);

        $baid = (int) $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);

        return $baid;
    }

    /**
     * Create a simple fixed slotbooking option, enrol a student, and return [optionid, userid].
     *
     * @param array $overrides option-form field overrides (slot_* keys, see classes/option/
     *                         fields/slotbooking.php)
     * @return array{0:int,1:int}
     */
    private function create_fixed_slot_option(array $overrides = []): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = array_merge([
            'bookingid' => $booking->id,
            'text' => 'Persistent calendar test option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 30 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'calendar',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 0,
            'slot_change_deadline_minutes' => '',
        ], $overrides);
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id];
    }
}
