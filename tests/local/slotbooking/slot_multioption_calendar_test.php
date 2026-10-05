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

use mod_booking\bo_availability\conditions\slotbooking;
use mod_booking\form\condition\slotbooking_form;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the merged multi-option slot calendar ([bookingoptionview optionid="a,b,c"]) when one
 * of the merged options is exhausted for the user (max_slots_per_user reached, no own booked slot
 * left inside the visible range):
 * - an exhausted PRIMARY option must not hide the whole merged calendar,
 * - an exhausted option of any position must not be listed in the sidebar anymore.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class slot_multioption_calendar_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * An option the user can no longer book (no slots in the merged calendar) must not be listed
     * in the sidebar, while the option ids driving the colors stay complete and in order.
     *
     * @covers \mod_booking\bo_availability\conditions\slotbooking::render_page
     * @return void
     */
    public function test_sidebar_hides_option_exhausted_for_user(): void {
        [$optionids, $userid] = $this->create_three_slot_options();
        [$a, $b, $c] = $optionids;
        $this->exhaust_option_for_user($c, $userid);

        $this->assertEmpty(slot_dto::build_picker_slots($c, $userid));

        $result = (new slotbooking())->render_page($a, $userid, [$b, $c]);
        $data = $result['data'][0]['data'];

        $this->assertTrue($data['showsidebar']);
        $this->assertSame([$a, $b], array_map('intval', array_column($data['sidebaroptions'], 'optionid')));
        $this->assertSame([$a, $b, $c], json_decode($data['optionidsjson'], true));
    }

    /**
     * An exhausted primary option must neither hide the merged calendar nor show up in the sidebar.
     *
     * @covers \mod_booking\form\condition\slotbooking_form::definition
     * @covers \mod_booking\bo_availability\conditions\slotbooking::render_page
     * @return void
     */
    public function test_exhausted_primary_keeps_merged_calendar(): void {
        [$optionids, $userid] = $this->create_three_slot_options();
        [$a, $b, $c] = $optionids;
        $this->exhaust_option_for_user($a, $userid);

        $form = new slotbooking_form(null, [], 'post', '', [], true, [
            'id' => $a,
            'userid' => $userid,
            'additionalids' => json_encode([$b, $c]),
        ], true);
        $html = $form->render();

        $this->assertStringContainsString('data-region="slot-calendar-picker"', $html);
        $this->assertStringNotContainsString(get_string('slot_no_open_slots', 'mod_booking'), $html);

        $result = (new slotbooking())->render_page($a, $userid, [$b, $c]);
        $data = $result['data'][0]['data'];
        $this->assertSame([$b, $c], array_map('intval', array_column($data['sidebaroptions'], 'optionid')));
    }

    /**
     * Book a slot for the user outside the visible range, so max_slots_per_user (1) is used up
     * without leaving a visible own booked slot - the option has no picker slots left for them.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return void
     */
    private function exhaust_option_for_user(int $optionid, int $userid): void {
        global $DB;

        $start = strtotime('2020-01-07 09:00:00 UTC');
        $end = $start + 30 * MINSECS;
        $answer = (object) [
            'bookingid' => (int) $DB->get_field('booking_options', 'bookingid', ['id' => $optionid], MUST_EXIST),
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
        $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Create three fixed calendar slot options in one booking instance and an enrolled student.
     *
     * @return array{0:int[],1:int}
     */
    private function create_three_slot_options(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $optionids = [];
        foreach (['A', 'B', 'C'] as $room) {
            $record = [
                'bookingid' => $booking->id,
                'text' => 'Room ' . $room,
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
            ];
            for ($day = 1; $day <= 7; $day++) {
                $record['slot_day_' . $day] = 1;
            }
            $optionids[] = (int) $plugingenerator->create_option((object) $record)->id;
        }
        singleton_service::destroy_instance();

        return [$optionids, (int) $student->id];
    }
}
