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

use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Picking the answer the self-service move works on.
 *
 * "Book again" lets one user hold several active answers on the same option, and not all of them
 * are movable - one whose slots have all started, or a leftover carrying no slot at all, is not.
 * The lookup used to fetch an arbitrary one and give up if that one failed, which made the move
 * button and the move tab vanish while a perfectly movable booking sat right next to it.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_mover::get_self_rebookable_answer
 */
final class slot_rebookable_answer_pick_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A slotless leftover answer must not hide a movable booking held next to it.
     *
     * @return void
     */
    public function test_a_slotless_answer_does_not_hide_the_movable_booking(): void {
        [$optionid, $userid, $goodbaid] = $this->create_option_with_two_answers();

        $answer = slot_mover::get_self_rebookable_answer($optionid, $userid);

        $this->assertNotNull($answer, 'The movable booking must still be found.');
        $this->assertSame($goodbaid, (int)$answer->id, 'Exactly the answer holding a future slot must be returned.');
    }

    /**
     * With no movable answer at all the lookup still says so.
     *
     * @return void
     */
    public function test_without_any_movable_answer_the_lookup_returns_null(): void {
        [$optionid, $userid, $goodbaid] = $this->create_option_with_two_answers();

        // Strip the good answer's slot payload as well, leaving nothing that could be moved.
        // Written directly rather than through set_slot_data(), which merges into the existing
        // payload and would leave the slot itself in place.
        global $DB;
        $DB->set_field('booking_answers', 'json', '{}', ['id' => $goodbaid]);
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        $this->assertNull(slot_mover::get_self_rebookable_answer($optionid, $userid));
    }

    /**
     * One option, one user, two booked answers: a slotless leftover and a real future slot.
     *
     * The leftover is written FIRST, so an implementation that just grabs the first row it finds
     * picks the broken one - which is exactly the situation that broke in practice.
     *
     * @return array{0:int, 1:int, 2:int} optionid, userid, id of the movable answer
     */
    private function create_option_with_two_answers(): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id, 'cancancelbook' => 1]);

        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Rebookable pick ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_opening_time' => '10:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 1,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'list',
            'slot_allow_self_rebooking' => 1,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);
        $optionid = (int)$option->id;
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $slots = slot_dto::build_picker_slots($optionid, (int)$student->id);

        $makeanswer = function (int $start, int $end) use ($DB, $settings, $student, $optionid): int {
            $answer = (object)[
                'bookingid' => (int)$settings->bookingid,
                'userid' => (int)$student->id,
                'optionid' => $optionid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
                'completed' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
                'timebooked' => time(),
                'startdate' => $start,
                'enddate' => $end,
                'json' => '{}',
            ];
            $answer->id = $DB->insert_record('booking_answers', $answer);
            slot_answer::set_slot_data($answer, $start > 0
                ? ['slots' => [['start' => $start, 'end' => $end]], 'num_slots' => 1]
                : ['slots' => [], 'num_slots' => 0]);
            $DB->update_record('booking_answers', $answer);
            return (int)$answer->id;
        };

        // The slotless leftover first, the real booking second - see the docblock.
        $makeanswer(0, 0);
        $goodbaid = $makeanswer((int)$slots[0]['start'], (int)$slots[0]['end']);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return [$optionid, (int)$student->id, $goodbaid];
    }
}
