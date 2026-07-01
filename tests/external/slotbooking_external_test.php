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
 * Tests for the slot booking external services.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_advanced_testcase;
use mod_booking\external\get_slots;
use mod_booking\external\get_booked_slots;
use mod_booking\external\save_slot_selection;
use mod_booking\external\release_slots;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for the slot booking webservices.
 *
 * @covers \mod_booking\external\get_slots
 * @covers \mod_booking\external\get_booked_slots
 * @covers \mod_booking\external\save_slot_selection
 * @covers \mod_booking\local\slotbooking\slot_mover
 */
final class slotbooking_external_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * get_slots returns selectable slots and the picker meta as valid JSON.
     */
    public function test_get_slots(): void {
        [$option, $userid] = $this->create_fixed_slot_option();

        $result = get_slots::execute($option->id, $userid);
        $result = \core_external\external_api::clean_returnvalue(get_slots::execute_returns(), $result);

        $slots = json_decode($result['slots'], true);
        $meta = json_decode($result['meta'], true);

        $this->assertNotEmpty($slots);
        $this->assertArrayHasKey('key', $slots[0]);
        $this->assertArrayHasKey('priceformatted', $slots[0]);
        $this->assertMatchesRegularExpression('/^\d+:\d+$/', $slots[0]['key']);
        $this->assertSame('fixed', $meta['slottype']);
        $this->assertSame(3, $meta['maxselection']);
    }

    /**
     * get_booked_slots returns the report structure as valid JSON.
     */
    public function test_get_booked_slots(): void {
        [$option, , $cmid] = $this->create_fixed_slot_option();

        $result = get_booked_slots::execute($cmid, $option->id);
        $result = \core_external\external_api::clean_returnvalue(get_booked_slots::execute_returns(), $result);

        $slots = json_decode($result['slots'], true);
        $details = json_decode($result['details'], true);

        // No bookings yet: report slots exist (fixed config) but nobody is booked.
        $this->assertIsArray($slots);
        $this->assertIsArray($details);
    }

    /**
     * save_slot_selection validates an open slot and caches it in the store.
     */
    public function test_save_slot_selection_persists_valid_selection(): void {
        [$option, $userid] = $this->create_fixed_slot_option();

        $slots = json_decode(get_slots::execute($option->id, $userid)["slots"], true);
        $this->assertNotEmpty($slots);
        $firstkey = $slots[0]['key'];

        $result = save_slot_selection::execute($option->id, $userid, json_encode([$firstkey]), '{}');
        $result = \core_external\external_api::clean_returnvalue(save_slot_selection::execute_returns(), $result);

        $this->assertTrue($result['valid']);
        $this->assertSame([], json_decode($result['errors'], true));
        $this->assertIsFloat($result['price']);

        $store = new slotbookingstore($userid, $option->id);
        $cached = $store->get_slotbooking_data();
        $this->assertNotEmpty($cached);
        $this->assertSame($firstkey, (string)$cached->slot_selection);
    }

    /**
     * save_slot_selection rejects a selection exceeding the per-user maximum.
     */
    public function test_save_slot_selection_rejects_too_many(): void {
        [$option, $userid] = $this->create_fixed_slot_option(1);

        $slots = json_decode(get_slots::execute($option->id, $userid)['slots'], true);
        $this->assertGreaterThanOrEqual(2, count($slots));
        $keys = [$slots[0]['key'], $slots[1]['key']];

        $result = save_slot_selection::execute($option->id, $userid, json_encode($keys), '{}');
        $result = \core_external\external_api::clean_returnvalue(save_slot_selection::execute_returns(), $result);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('slot_selection', json_decode($result['errors'], true));
    }

    /**
     * The release_slots webservice cancels a selected booked slot and keeps the remaining one.
     *
     * @covers \mod_booking\external\release_slots::execute
     */
    public function test_release_slots_selfservice(): void {
        global $DB;

        [$option, $userid, $cmid] = $this->create_fixed_slot_option();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->enable_self_rebooking($option->id);
        $this->enrol_owner_as_student($cmid, $userid);

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $keep = $slots[0];
        $drop = $slots[1];
        $baid = $this->create_booked_slot_answer_multi($option->id, (int)$settings->bookingid, $userid, [
            ['start' => (int)$keep['start'], 'end' => (int)$keep['end']],
            ['start' => (int)$drop['start'], 'end' => (int)$drop['end']],
        ]);
        $dropkey = (int)$drop['start'] . ':' . (int)$drop['end'];

        $this->setUser($userid);
        $result = release_slots::execute($option->id, $baid, json_encode([$dropkey]), '');
        $result = \core_external\external_api::clean_returnvalue(release_slots::execute_returns(), $result);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['released']);
        $this->assertSame(1, $result['remaining']);
        $this->assertFalse($result['cancelled']);

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $keys = array_map(
            static fn($s) => $s['start'] . ':' . $s['end'],
            slot_answer::get_slot_data($answer)['slots']
        );
        $this->assertSame([(int)$keep['start'] . ':' . (int)$keep['end']], $keys);
    }

    /**
     * Enable the self-rebooking opt-in for an option's slot config.
     *
     * @param int $optionid booking option id
     * @return void
     */
    private function enable_self_rebooking(int $optionid): void {
        global $DB;
        $DB->set_field('booking_slot_config', 'allow_self_rebooking', 1, ['optionid' => $optionid]);
    }

    /**
     * Enrol the booking owner as a student so the moveslotsself archetype grant applies.
     *
     * @param int $cmid course module id
     * @param int $userid owner user id
     * @return void
     */
    private function enrol_owner_as_student(int $cmid, int $userid): void {
        [$course] = get_course_and_cm_from_cmid($cmid, 'booking');
        $this->getDataGenerator()->enrol_user($userid, $course->id, 'student');
    }

    /**
     * Insert a booked slot answer holding one or more slot ranges and return its id.
     *
     * @param int $optionid booking option id
     * @param int $bookingid booking instance id
     * @param int $userid booking owner
     * @param array $slots list of ['start'=>int,'end'=>int]
     * @return int booking answer id
     */
    private function create_booked_slot_answer_multi(int $optionid, int $bookingid, int $userid, array $slots): int {
        global $DB;

        usort($slots, static fn($a, $b) => $a['start'] <=> $b['start']);
        $answer = (object)[
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => 0,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => (int)$slots[0]['start'],
            'enddate' => (int)$slots[count($slots) - 1]['end'],
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => $slots, 'teachers' => []]);

        return (int)$DB->insert_record('booking_answers', $answer);
    }

    /**
     * Create a fixed-slot booking option (Mon + Fri, 09:00-12:00, 60-min slots) in 2050.
     *
     * @param int $maxslots maximum slots per user
     * @return array{0:\stdClass, 1:int, 2:int} option, student user id, course module id
     */
    private function create_fixed_slot_option(int $maxslots = 3): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot WS option ' . uniqid('', true),
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
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => $maxslots,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, [1, 5], true) ? 1 : 0;
        }

        $option = $plugingenerator->create_option((object)$record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cmid = (int)$settings->cmid;

        singleton_service::destroy_instance();
        return [$option, (int)$student->id, $cmid];
    }
}
