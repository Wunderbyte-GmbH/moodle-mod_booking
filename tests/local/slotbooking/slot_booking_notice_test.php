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
 * Tests for the minimum notice before slot start for new slot bookings.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use core_external\external_api;
use mod_booking\bo_availability\conditions\slotbooking;
use mod_booking\external\save_slot_selection;
use mod_booking\form\condition\slotbooking_form;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\slot_notice_policy;
use mod_booking\tests\booking_advanced_testcase;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Minimum notice for booking a slot (Wunderbyte-GmbH/moodle-mod_booking#1691).
 *
 * @covers \mod_booking\local\slotbooking\slot_notice_policy
 * @covers \mod_booking\local\slotbooking\slot_availability::evaluate_slot_for_user
 * @covers \mod_booking\local\slotbooking\slot_availability::get_slots_with_status_for_range
 * @covers \mod_booking\bo_availability\conditions\slotbooking::add_json_to_booking_answer
 * @covers \mod_booking\external\save_slot_selection
 */
final class slot_booking_notice_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Default 0: a slot that starts soon is offered and can be booked, as before.
     */
    public function test_default_zero_keeps_current_behaviour(): void {
        [$optionid, , $student] = $this->create_slot_option();
        $this->setUser($student);
        $now = time();

        $this->assertSame(0, slot_notice_policy::resolve_notice_minutes($optionid));
        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $now + HOURSECS, $now + 2 * HOURSECS, (int)$student->id)
        );
        $starts = $this->open_slot_starts($optionid, (int)$student->id);
        $this->assertNotEmpty($starts);
        $this->assertLessThan($now + 2 * HOURSECS, min($starts), 'A slot that starts soon is still offered.');
    }

    /**
     * A slot inside the notice is not offered, and a direct booking attempt is rejected with a
     * clear message - in the webservice and when the booking answer is saved.
     */
    public function test_slot_inside_notice_is_not_offered_and_rejected(): void {
        [$optionid, , $student] = $this->create_slot_option();
        set_config('slot_booking_notice_minutes', 1440, 'booking');
        $near = $this->first_slot_starting_after($optionid, (int)$student->id, time() + HOURSECS);
        $message = get_string('slot_error_booking_notice', 'mod_booking', format_time(DAYSECS));

        $this->setUser($student);
        $now = time();

        // Not offered.
        $starts = $this->open_slot_starts($optionid, (int)$student->id);
        $this->assertNotEmpty($starts);
        $this->assertGreaterThanOrEqual($now + DAYSECS, min($starts));

        // Rejected on the server with a clear message.
        $evaluation = slot_availability::evaluate_slot_for_user($optionid, $near[0], $near[1], (int)$student->id);
        $this->assertFalse($evaluation['bookable']);
        $this->assertSame($message, $evaluation['errormessage']);

        $result = save_slot_selection::execute($optionid, (int)$student->id, json_encode([$near[0] . ':' . $near[1]]), '{}');
        $result = external_api::clean_returnvalue(save_slot_selection::execute_returns(), $result);
        $this->assertFalse($result['valid']);
        $this->assertSame($message, json_decode($result['errors'], true)['slot_selection'] ?? '');

        $store = new slotbookingstore((int)$student->id, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $near[0] . ':' . $near[1],
            'slot_teacher_selection' => json_encode([]),
        ]);
        $newanswer = (object)[
            'optionid' => $optionid,
            'userid' => (int)$student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');
        slotbooking::add_json_to_booking_answer($newanswer, (int)$student->id);
    }

    /**
     * A slot outside the notice can be booked by the user.
     */
    public function test_slot_outside_notice_can_be_booked(): void {
        global $DB;

        [$optionid, $cmid, $student] = $this->create_slot_option();
        set_config('slot_booking_notice_minutes', 1440, 'booking');
        $far = $this->first_slot_starting_after($optionid, (int)$student->id, time() + 2 * DAYSECS);

        $this->setUser($student);
        $result = save_slot_selection::execute($optionid, (int)$student->id, json_encode([$far[0] . ':' . $far[1]]), '{}');
        $result = external_api::clean_returnvalue(save_slot_selection::execute_returns(), $result);
        $this->assertTrue($result['valid'], (string)$result['errors']);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertTrue((bool)$option->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED));

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid' => $student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertNotEmpty($answer);
        $this->assertSame($far[0], (int)$answer->startdate);
    }

    /**
     * The notice is inherited site -> booking instance -> option ("Use default").
     */
    public function test_notice_is_inherited_site_instance_option(): void {
        global $DB;

        set_config('slot_booking_notice_minutes', 1440, 'booking');

        [$inheritall] = $this->create_slot_option();
        $this->assertSame(1440, slot_notice_policy::resolve_notice_minutes($inheritall));
        $this->assertNull($DB->get_field('booking_slot_config', 'booking_notice_minutes', ['optionid' => $inheritall]));

        [$frominstance] = $this->create_slot_option([], ['slot_booking_notice_minutes' => 120]);
        $this->assertSame(120, slot_notice_policy::resolve_notice_minutes($frominstance));

        [$optionzero] = $this->create_slot_option(['slot_booking_notice_minutes' => 0], ['slot_booking_notice_minutes' => 120]);
        $this->assertSame(0, slot_notice_policy::resolve_notice_minutes($optionzero), 'The option value 0 wins.');

        [$option30] = $this->create_slot_option(['slot_booking_notice_minutes' => 30]);
        $this->assertSame(30, slot_notice_policy::resolve_notice_minutes($option30));
        $this->assertSame('30', (string)$DB->get_field('booking_slot_config', 'booking_notice_minutes', ['optionid' => $option30]));
    }

    /**
     * When users move a booking themselves, a target slot inside the notice is not offered.
     */
    public function test_move_target_inside_notice_is_not_offered(): void {
        [$optionid, , $student] = $this->create_slot_option(['slot_allow_self_rebooking' => 1]);
        set_config('slot_booking_notice_minutes', 1440, 'booking');
        $near = $this->first_slot_starting_after($optionid, (int)$student->id, time() + HOURSECS);

        $this->setUser($student);
        $now = time();

        $targets = slot_mover::available_target_slots($optionid, (int)$student->id, [], []);
        $this->assertNotEmpty($targets);
        foreach ($targets as $target) {
            $this->assertGreaterThanOrEqual($now + DAYSECS, (int)$target['start']);
        }
        $this->assertFalse(
            slot_availability::is_slot_available($optionid, $near[0], $near[1], (int)$student->id)
        );
    }

    /**
     * Someone who books for another user (e.g. cashier or teacher) is not limited by the notice.
     */
    public function test_booking_for_another_user_is_not_limited(): void {
        [$optionid, , $student] = $this->create_slot_option();
        set_config('slot_booking_notice_minutes', 1440, 'booking');
        $now = time();

        $this->setAdminUser();
        $starts = $this->open_slot_starts($optionid, (int)$student->id);
        $this->assertLessThan($now + 2 * HOURSECS, min($starts), 'The slot list for another user keeps the near slots.');

        $near = $this->first_slot_starting_after($optionid, (int)$student->id, $now + HOURSECS);
        $this->assertTrue(slot_availability::is_slot_available($optionid, $near[0], $near[1], (int)$student->id));
    }

    /**
     * A slot the user already holds (reserved in the cart) is not lost at checkout, even when it
     * is now inside the notice - the notice was checked when it was put in the cart.
     */
    public function test_reserved_slot_inside_notice_survives_checkout(): void {
        global $DB;

        [$optionid, , $student, $bookingid] = $this->create_slot_option();
        $near = $this->first_slot_starting_after($optionid, (int)$student->id, time() + HOURSECS);

        // Reserved while no notice applied, then the notice is set.
        $baid = $this->insert_answer($optionid, $bookingid, (int)$student->id, $near, MOD_BOOKING_STATUSPARAM_RESERVED);
        set_config('slot_booking_notice_minutes', 1440, 'booking');

        $this->setUser($student);
        $this->assertFalse(slot_notice_policy::blocks_slot($optionid, (int)$student->id, $near[0], $near[1]));

        $store = new slotbookingstore((int)$student->id, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $near[0] . ':' . $near[1],
            'slot_teacher_selection' => json_encode([]),
        ]);
        $newanswer = (object)[
            'optionid' => $optionid,
            'userid' => (int)$student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ];
        slotbooking::add_json_to_booking_answer($newanswer, (int)$student->id, $baid);
        $this->assertSame($near[0], (int)$newanswer->startdate);
        $this->assertNotEmpty($DB->get_record('booking_answers', ['id' => $baid]));
    }

    /**
     * User-defined slots: the open windows start at the earliest bookable start.
     */
    public function test_userdefined_windows_start_after_notice(): void {
        [$optionid, , $student] = $this->create_slot_option(['slot_type' => 'userdefined']);
        set_config('slot_booking_notice_minutes', 720, 'booking');

        $this->setUser($student);
        $now = time();

        $method = new \ReflectionMethod(slotbooking_form::class, 'get_custom_open_days');
        $method->setAccessible(true);
        $days = $method->invokeArgs(null, [$optionid, (int)$student->id]);

        $this->assertNotEmpty($days);
        foreach ($days as $day) {
            $this->assertGreaterThanOrEqual($now + 12 * HOURSECS, (int)$day['openfrom']);
        }
    }

    /**
     * Start times of the slots the user can still choose (open or warning).
     *
     * @param int $optionid
     * @param int $userid
     * @return int[]
     */
    private function open_slot_starts(int $optionid, int $userid): array {
        $starts = [];
        foreach (slot_availability::get_slots_with_status($optionid, $userid) as $slot) {
            if (in_array((string)$slot['status'], ['open', 'warning'], true)) {
                $starts[] = (int)$slot['start'];
            }
        }
        return $starts;
    }

    /**
     * The first slot of the option grid that starts at or after the given time.
     *
     * Read as admin (no notice applies), so the near slots are listed too.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $after timestamp
     * @return int[] [start, end]
     */
    private function first_slot_starting_after(int $optionid, int $userid, int $after): array {
        global $USER;

        $previous = $USER;
        $this->setAdminUser();
        foreach (slot_availability::get_slots_with_status($optionid, $userid) as $slot) {
            if ((int)$slot['start'] >= $after && $slot['status'] === 'open') {
                $this->setUser($previous);
                return [(int)$slot['start'], (int)$slot['end']];
            }
        }
        $this->fail('No open slot found after ' . userdate($after));
    }

    /**
     * Insert one answer for a single slot.
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param int[] $range [start, end]
     * @param int $waitinglist booking status
     * @return int booking answer id
     */
    private function insert_answer(int $optionid, int $bookingid, int $userid, array $range, int $waitinglist): int {
        global $DB;

        $answer = (object)[
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => $waitinglist,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $range[0],
            'enddate' => $range[1],
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, [
            'slots' => [['start' => $range[0], 'end' => $range[1]]],
            'teachers' => [],
        ]);
        $baid = (int)$DB->insert_record('booking_answers', $answer);

        // The answers were read before (and cached), so drop the cache after the direct insert.
        booking_option::purge_cache_for_answers($optionid);
        slot_availability::clear_request_cache($optionid);
        singleton_service::destroy_instance();
        return $baid;
    }

    /**
     * Create a slot option with hourly slots on every day, all day long.
     *
     * @param array $overrides option form values
     * @param array $instance booking instance values
     * @return array{0:int, 1:int, 2:\stdClass, 3:int} option id, cmid, student, booking id
     */
    private function create_slot_option(array $overrides = [], array $instance = []): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id] + $instance);
        $student = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = $overrides + [
            'bookingid' => $booking->id,
            'text' => 'Slot option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 0,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 2 * HOURSECS,
            'slot_custom_min_duration' => HOURSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '00:00',
            'slot_closing_time' => '23:59',
            'slot_valid_from' => 0,
            'slot_valid_until' => 0,
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);
        singleton_service::destroy_instance();
        $cmid = (int)singleton_service::get_instance_of_booking_option_settings($option->id)->cmid;

        return [(int)$option->id, $cmid, $student, (int)$booking->id];
    }
}
