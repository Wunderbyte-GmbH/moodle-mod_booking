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
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the calendar="compact" flag of the bookingoptionview shortcode: it asks for the compact
 * calendar of the slot booking (FullCalendar, shipped with mod_booking) instead of the month grid and
 * day timeline. It works without local_entities.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class slot_compact_calendar_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The flag reaches the prepage of the slot booking and is put on its container, which stays on
     * the page when the form inside it is reloaded.
     *
     * @covers \mod_booking\bo_availability\conditions\slotbooking::render_page
     * @return void
     */
    public function test_render_page_carries_compact_calendar(): void {
        [$optionid, $userid] = $this->create_slot_option();

        $result = (new slotbooking())->render_page($optionid, $userid, [], false, 'compact');
        $data = $result['data'][0]['data'];

        $this->assertSame('compact', $data['calendarstyle']);
        $html = $this->render_template($result['template'], $data);
        $this->assertStringContainsString('data-calendar="compact"', $html);

        // The calendar follows the Moodle calendar: language, first day of the week and time format.
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');
        set_config('calendar_startwday', 0);
        set_user_preference('calendar_timeformat', CALENDAR_TF_12);
        $data = (new slotbooking())->render_page($optionid, $userid, [], false, 'compact')['data'][0]['data'];
        $this->assertSame(str_replace('_', '-', current_language()), $data['calendarlocale']);
        $this->assertSame(0, $data['calendarfirstday']);
        $this->assertSame(1, $data['calendarhour12']);
        $html = $this->render_template($result['template'], $data);
        $this->assertStringContainsString('data-calendar-locale="' . $data['calendarlocale'] . '"', $html);
        $this->assertStringContainsString('data-calendar-firstday="0"', $html);
        $this->assertStringContainsString('data-calendar-hour12="1"', $html);

        set_user_preference('calendar_startwday', 1);
        set_user_preference('calendar_timeformat', CALENDAR_TF_24);
        $data = (new slotbooking())->render_page($optionid, $userid, [], false, 'compact')['data'][0]['data'];
        $this->assertSame(1, $data['calendarfirstday']);
        $this->assertSame(0, $data['calendarhour12']);
    }

    /**
     * Without the flag, and with a value that is not known, the standard calendar stays.
     *
     * @covers \mod_booking\bo_availability\conditions\slotbooking::render_page
     * @return void
     */
    public function test_render_page_without_or_with_unknown_flag_keeps_standard_calendar(): void {
        [$optionid, $userid] = $this->create_slot_option();

        foreach (['', 'something'] as $calendarstyle) {
            $result = (new slotbooking())->render_page($optionid, $userid, [], false, $calendarstyle);
            $data = $result['data'][0]['data'];

            $this->assertSame('', $data['calendarstyle'], "calendar=\"$calendarstyle\"");
            $html = $this->render_template($result['template'], $data);
            $this->assertStringNotContainsString('data-calendar=', $html, "calendar=\"$calendarstyle\"");
        }
    }

    /**
     * The shortcode passes calendar="compact" through to the inline slot calendar.
     *
     * @covers \mod_booking\shortcodes::bookingoptionview
     * @return void
     */
    public function test_shortcode_passes_compact_calendar(): void {
        global $PAGE;
        [$optionid, $userid] = $this->create_slot_option();
        $this->setUser($userid);
        $PAGE->set_url('/');

        $next = fn() => '';
        $env = (object) [];
        $html = shortcodes::bookingoptionview('bookingoptionview', [
            'optionid' => (string) $optionid,
            'inlinestartpage' => 'slotbooking',
            'calendar' => 'compact',
        ], null, $env, $next);
        $this->assertStringContainsString('data-calendar="compact"', $html);

        $html = shortcodes::bookingoptionview('bookingoptionview', [
            'optionid' => (string) $optionid,
            'inlinestartpage' => 'slotbooking',
        ], null, $env, $next);
        $this->assertStringContainsString('booking-slotbooking-prepage', $html);
        $this->assertStringNotContainsString('data-calendar=', $html);
    }

    /**
     * Render a mustache template of mod_booking.
     *
     * @param string $template template name
     * @param array $data template data
     * @return string
     */
    private function render_template(string $template, array $data): string {
        global $PAGE;
        $PAGE->set_url('/');
        return $PAGE->get_renderer('mod_booking')->render_from_template($template, $data);
    }

    /**
     * Create one fixed calendar slot option and an enrolled student.
     *
     * @return array{0:int,1:int}
     */
    private function create_slot_option(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Compact calendar option',
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'calendar',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }
        $optionid = (int) $plugingenerator->create_option((object) $record)->id;
        singleton_service::destroy_instance();

        return [$optionid, (int) $student->id];
    }
}
