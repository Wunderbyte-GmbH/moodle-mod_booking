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
 * Tests for the extra date columns of the sign-in sheet.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\signinsheet\signinsheet_config;
use mod_booking\signinsheet\signinsheet_generator;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * The extra columns for dates ("Add extra columns for dates") are labelled with the date of the
 * session ("August 27th" / "27. August"); the start time is added when the option has more than
 * one session on that day ("Aug. 27th, 3:00 pm" / "27. Aug., 15:00").
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\signinsheet\signinsheet_generator::render_html
 */
final class extra_session_columns_test extends booking_advanced_testcase {
    /**
     * Booking option with five sessions: two on May 2nd, two on August 28th, one on August 29th.
     *
     * @return array keys: bookingoption, settings, sessions (start timestamps in order)
     */
    private function create_option_with_sessions(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        $sessions = [
            [strtotime('2 May 2050 10:00'), strtotime('2 May 2050 12:00')],
            [strtotime('2 May 2050 14:00'), strtotime('2 May 2050 16:00')],
            [strtotime('28 August 2050 10:00'), strtotime('28 August 2050 12:00')],
            [strtotime('28 August 2050 14:00'), strtotime('28 August 2050 16:00')],
            [strtotime('29 August 2050 10:00'), strtotime('29 August 2050 12:00')],
        ];
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Sessions option';
        foreach ($sessions as $i => [$start, $end]) {
            $record->{"optiondateid_$i"} = "0";
            $record->{"daystonotify_$i"} = "0";
            $record->{"coursestarttime_$i"} = $start;
            $record->{"courseendtime_$i"} = $end;
        }
        $option = $plugingenerator->create_option($record);

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Bianchi']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $user->id]);

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return [
            'bookingoption' => singleton_service::get_instance_of_booking_option($settings->cmid, $option->id),
            'settings' => $settings,
            'sessions' => array_column($sessions, 0),
        ];
    }

    /**
     * Renders the sheet in HTML template mode with the given "extra columns for dates" value.
     *
     * @param booking_option $bookingoption
     * @param int $extrasessioncols 0 = all sessions, -1 = none, otherwise the id of one session
     * @return string
     */
    private function render(booking_option $bookingoption, int $extrasessioncols): string {
        set_config('signinsheetmode', 'htmltemplate', 'booking');
        set_config('signinsheethtml', '', 'booking');
        $pdfoptions = signinsheet_config::pdfoptions_from_config(['signinextrasessioncols' => $extrasessioncols]);
        $pdfoptions->saveasformat = 'pdf';
        return (new signinsheet_generator($pdfoptions, $bookingoption))->render_html();
    }

    /**
     * Labels of the extra column header cells, in order - only cells whose text is centered count.
     *
     * @param string $html
     * @return string[]
     */
    private function column_headers(string $html): array {
        preg_match_all(
            '/<th class="vertical-text" style="text-align: center; vertical-align: middle;">(.*?)<\/th>/',
            $html,
            $matches
        );
        return $matches[1];
    }

    /**
     * All sessions (English): days with two sessions carry the abbreviated month with a dot, the
     * ordinal day and the time in lowercase am/pm; a day with one session shows the full date only.
     * The month "May" is not abbreviated and therefore gets no dot.
     */
    public function test_columns_for_all_sessions_add_time_on_days_with_several_sessions(): void {
        $env = $this->create_option_with_sessions();

        $html = $this->render($env['bookingoption'], 0);

        $this->assertSame(
            ['May 2nd, 10:00 am', 'May 2nd, 2:00 pm', 'Aug. 28th, 10:00 am', 'Aug. 28th, 2:00 pm', 'August 29th'],
            $this->column_headers($html)
        );
        // Every user row got one empty cell per column (five, after the empty cell of the template).
        $this->assertMatchesRegularExpression('/Bianchi, Anna.*?(<td><\/td>\s*){6}<\/tr>/s', $html);
    }

    /**
     * A single selected session: with the time when its day has another session, without otherwise.
     */
    public function test_column_for_one_session_adds_time_only_if_its_day_has_several_sessions(): void {
        $env = $this->create_option_with_sessions();
        [$may1, $may2, $aug1, $aug2, $aug3] = $env['sessions'];
        $sessionids = [];
        foreach ($env['settings']->sessions as $id => $session) {
            $sessionids[(int) $session->coursestarttime] = (int) $id;
        }

        $this->assertSame(['Aug. 28th, 2:00 pm'], $this->column_headers($this->render($env['bookingoption'], $sessionids[$aug2])));
        $this->assertSame(['August 29th'], $this->column_headers($this->render($env['bookingoption'], $sessionids[$aug3])));
        $this->assertSame(['May 2nd, 10:00 am'], $this->column_headers($this->render($env['bookingoption'], $sessionids[$may1])));

        // No extra columns at all.
        $this->assertSame([], $this->column_headers($this->render($env['bookingoption'], -1)));
    }

    /**
     * The German patterns: "27. August" and "27. Aug., 15:00" (the plugin ships the strings, the
     * abbreviated month comes with its dot from the date formatting of the language).
     */
    public function test_german_label_patterns(): void {
        global $CFG;

        // The German strings are read from the plugin itself: the test site has no German language
        // pack, so the string manager would fall back to English for 'de'.
        $string = [];
        include($CFG->dirroot . '/mod/booking/lang/de/booking.php');
        $a = ['{$a->day}' => 27, '{$a->dayordinal}' => '27th', '{$a->month}' => 'August', '{$a->monthabbr}' => 'Aug.'];
        $this->assertSame('27. August', strtr($string['signinsheetcolumndate'], $a));
        $this->assertSame('27. Aug., 15:00', strtr($string['signinsheetcolumndatetime'], $a + ['{$a->time}' => '15:00']));

        // The English patterns, for comparison.
        $a = (object) ['day' => 27, 'dayordinal' => '27th', 'month' => 'August', 'monthabbr' => 'Aug.', 'time' => '3:00 pm'];
        $this->assertSame('August 27th', get_string('signinsheetcolumndate', 'mod_booking', $a));
        $this->assertSame('Aug. 27th, 3:00 pm', get_string('signinsheetcolumndatetime', 'mod_booking', $a));
    }
}
