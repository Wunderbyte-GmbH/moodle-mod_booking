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
use mod_booking\option\dates_handler;
use mod_booking\placeholders\placeholders_info;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the {datescompact} placeholder and the hidetimezonesindates setting.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class datescompact_test extends booking_advanced_testcase {
    /**
     * Create a booking option with the given session times.
     *
     * @param array $sessions array of [starttimestamp, endtimestamp] pairs
     * @return stdClass the created option
     */
    private function create_option_with_sessions(array $sessions): stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $bookingmodule = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ]);

        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $bookingmodule->id;
        $record->text = 'Test option datescompact';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 2;
        $record->useprice = 0;
        $record->importing = 1;
        foreach (array_values($sessions) as $i => [$start, $end]) {
            $record->{"optiondateid_$i"} = "0";
            $record->{"daystonotify_$i"} = "0";
            $record->{"coursestarttime_$i"} = $start;
            $record->{"courseendtime_$i"} = $end;
        }

        return $plugingenerator->create_option($record);
    }

    /**
     * Sessions on the same day are combined into one line, days are separated by <br>.
     *
     * @covers \mod_booking\placeholders\placeholders\datescompact
     * @return void
     */
    public function test_datescompact_combines_same_day_sessions(): void {
        global $USER;

        // Three sessions on day 1, two on day 2, one on day 3 and one spanning two days.
        $sessions = [
            [strtotime('20 August 2050 10:00'), strtotime('20 August 2050 12:00')],
            [strtotime('20 August 2050 13:00'), strtotime('20 August 2050 15:00')],
            [strtotime('20 August 2050 17:00'), strtotime('20 August 2050 18:00')],
            [strtotime('21 August 2050 10:00'), strtotime('21 August 2050 12:00')],
            [strtotime('21 August 2050 14:00'), strtotime('21 August 2050 15:00')],
            [strtotime('22 August 2050 09:00'), strtotime('22 August 2050 10:30')],
            [strtotime('25 August 2050 09:00'), strtotime('26 August 2050 17:00')],
        ];
        $option = $this->create_option_with_sessions($sessions);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $fmtdate = get_string('strftimedate', 'langconfig');
        $fmttime = get_string('strftimetime', 'langconfig');
        $and = get_string('and', 'mod_booking');
        $time = fn(int $ts) => userdate($ts, $fmttime);

        $expected = implode('<br>', [
            userdate($sessions[0][0], $fmtdate) . ', '
                . $time($sessions[0][0]) . '-' . $time($sessions[0][1]) . ', '
                . $time($sessions[1][0]) . '-' . $time($sessions[1][1]) . ' ' . $and . ' '
                . $time($sessions[2][0]) . '-' . $time($sessions[2][1]),
            userdate($sessions[3][0], $fmtdate) . ', '
                . $time($sessions[3][0]) . '-' . $time($sessions[3][1]) . ' ' . $and . ' '
                . $time($sessions[4][0]) . '-' . $time($sessions[4][1]),
            userdate($sessions[5][0], $fmtdate) . ', '
                . $time($sessions[5][0]) . '-' . $time($sessions[5][1]),
            // The session spanning two days keeps the full date string of {dates}.
            dates_handler::prettify_datetime($sessions[6][0], $sessions[6][1])->datestring,
        ]);

        placeholders_info::$placeholders = [];
        $result = placeholders_info::render_text('{datescompact}', $settings->cmid, $option->id, (int) $USER->id);

        $this->assertSame($expected, $result);
    }

    /**
     * With hidetimezonesindates active, no timezone strings are rendered.
     *
     * @covers \mod_booking\placeholders\placeholders\datescompact
     * @covers ::booking_format_userdate_with_timezone_abbr
     * @return void
     */
    public function test_hidetimezonesindates(): void {
        set_config('timezone', 'Europe/Berlin');
        set_config('forcetimezone', '99');

        $sessions = [
            [strtotime('20 August 2050 10:00'), strtotime('20 August 2050 12:00')],
            [strtotime('20 August 2050 13:00'), strtotime('20 August 2050 15:00')],
        ];
        $option = $this->create_option_with_sessions($sessions);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // A user in a timezone differing from the site timezone gets the abbreviation appended.
        $student = $this->getDataGenerator()->create_user(['timezone' => 'America/New_York']);
        $this->setUser($student);

        placeholders_info::$placeholders = [];
        $result = placeholders_info::render_text('{datescompact}', $settings->cmid, $option->id, (int) $student->id);
        $this->assertStringContainsString('(EDT)', $result);

        $resultdates = placeholders_info::render_text('{dates}', $settings->cmid, $option->id, (int) $student->id);
        $this->assertStringContainsString('(EDT)', $resultdates);

        // With the setting active, no timezone strings are rendered anymore.
        set_config('hidetimezonesindates', 1, 'booking');

        placeholders_info::$placeholders = [];
        $result = placeholders_info::render_text('{datescompact}', $settings->cmid, $option->id, (int) $student->id);
        $this->assertStringNotContainsString('(EDT)', $result);

        $resultdates = placeholders_info::render_text('{dates}', $settings->cmid, $option->id, (int) $student->id);
        $this->assertStringNotContainsString('(EDT)', $resultdates);
    }
}
