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
use mod_booking\placeholders\placeholders_info;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the per-request placeholder cache (placeholders_info::$placeholders):
 * purge on option changes, reload in the rule mail adhoc task and per-user cachekeys of the date placeholders.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class placeholders_cache_test extends booking_advanced_testcase {
    /**
     * Create course, booking instance and one option with the given sessions.
     *
     * @param array $sessions array of [starttimestamp, endtimestamp] pairs
     * @return array keys: option, settings, record, course, bookingmodule, plugingenerator
     */
    private function setup_option(array $sessions): array {
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
        $record->text = 'Test option placeholder cache';
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
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        return [
            'option' => $option,
            'settings' => $settings,
            'record' => $record,
            'course' => $course,
            'bookingmodule' => $bookingmodule,
            'plugingenerator' => $plugingenerator,
        ];
    }

    /**
     * purge_for_option() drops exactly the entries whose second key segment is the option id.
     *
     * @covers \mod_booking\placeholders\placeholders_info::purge_for_option
     * @return void
     */
    public function test_purge_for_option_drops_only_entries_of_that_option(): void {
        placeholders_info::$placeholders = [
            'dates-5-7' => 'option 5, user 7',
            'startdate-5' => 'option 5',
            'customfields-5-myfield' => 'option 5, customfield',
            'dates-55-7' => 'option 55, user 7',
            'dates-6-5' => 'option 6, user 5',
            'dates-15-5' => 'option 15, user 5',
        ];

        placeholders_info::purge_for_option(5);

        $this->assertSame(
            [
                'dates-55-7' => 'option 55, user 7',
                'dates-6-5' => 'option 6, user 5',
                'dates-15-5' => 'option 15, user 5',
            ],
            placeholders_info::$placeholders
        );
        placeholders_info::$placeholders = [];
    }

    /**
     * Updating the dates of an option purges its cached placeholders, so the next render shows the new dates.
     *
     * @covers \mod_booking\placeholders\placeholders_info::purge_for_option
     * @covers \mod_booking\singleton_service::destroy_booking_option_singleton
     * @return void
     */
    public function test_placeholder_cache_is_purged_when_option_dates_change(): void {
        global $USER;

        $start1 = strtotime('20 August 2050 10:00');
        $end1 = strtotime('20 August 2050 12:00');
        $start2 = strtotime('21 August 2050 14:00');
        $end2 = strtotime('21 August 2050 15:00');
        $env = $this->setup_option([[$start1, $end1]]);
        $option = $env['option'];
        $settings = $env['settings'];
        $record = $env['record'];

        $newday = userdate($start2, get_string('strftimedate', 'langconfig'));
        $cachekey = "datescompact-{$option->id}-{$USER->id}";

        placeholders_info::$placeholders = [];
        $before = placeholders_info::render_text('{datescompact}', $settings->cmid, $option->id, (int) $USER->id);
        $this->assertStringNotContainsString($newday, $before);
        $this->assertArrayHasKey($cachekey, placeholders_info::$placeholders);

        // Add a second date through the regular update path - no manual cache reset.
        $update = new stdClass();
        $update->id = $option->id;
        $update->cmid = $settings->cmid;
        $update->bookingid = $record->bookingid;
        $update->text = $record->text;
        $update->chooseorcreatecourse = 1;
        $update->courseid = $record->courseid;
        $update->maxanswers = 2;
        $update->optiondateid_0 = "0";
        $update->daystonotify_0 = "0";
        $update->coursestarttime_0 = $start1;
        $update->courseendtime_0 = $end1;
        $update->optiondateid_1 = "0";
        $update->daystonotify_1 = "0";
        $update->coursestarttime_1 = $start2;
        $update->courseendtime_1 = $end2;
        booking_option::update($update);

        $this->assertArrayNotHasKey($cachekey, placeholders_info::$placeholders);
        $after = placeholders_info::render_text('{datescompact}', $settings->cmid, $option->id, (int) $USER->id);
        $this->assertStringContainsString($newday, $after);
        placeholders_info::$placeholders = [];
    }
    /**
     * The userdate() based placeholders are cached per user (language and timezone of the recipient).
     *
     * @covers \mod_booking\placeholders\placeholders\startdate
     * @covers \mod_booking\placeholders\placeholders\starttime
     * @covers \mod_booking\placeholders\placeholders\enddate
     * @covers \mod_booking\placeholders\placeholders\endtime
     * @covers \mod_booking\placeholders\placeholders\pollstartdate
     * @return void
     */
    public function test_date_placeholders_are_cached_per_user(): void {
        set_config('timezone', 'Europe/Berlin');
        set_config('forcetimezone', '99');

        $start = strtotime('20 August 2050 10:00');
        $end = strtotime('20 August 2050 12:00');
        $env = $this->setup_option([[$start, $end]]);
        $option = $env['option'];
        $settings = $env['settings'];

        $usera = $this->getDataGenerator()->create_user(['timezone' => 'America/New_York']);
        $userb = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Berlin']);
        $fmttime = get_string('strftimetime', 'langconfig');

        placeholders_info::$placeholders = [];
        $this->setUser($usera);
        $fora = placeholders_info::render_text('{starttime}', $settings->cmid, $option->id, (int) $usera->id);
        $this->setUser($userb);
        $forb = placeholders_info::render_text('{starttime}', $settings->cmid, $option->id, (int) $userb->id);

        $this->assertSame(userdate($start, $fmttime, 'America/New_York'), $fora);
        $this->assertSame(userdate($start, $fmttime, 'Europe/Berlin'), $forb);
        $this->assertNotSame($fora, $forb);

        foreach (['startdate', 'starttime', 'enddate', 'endtime', 'pollstartdate'] as $placeholder) {
            $this->setUser($usera);
            placeholders_info::render_text('{' . $placeholder . '}', $settings->cmid, $option->id, (int) $usera->id);
            $this->setUser($userb);
            placeholders_info::render_text('{' . $placeholder . '}', $settings->cmid, $option->id, (int) $userb->id);
            $this->assertArrayHasKey("$placeholder-{$option->id}-{$usera->id}", placeholders_info::$placeholders);
            $this->assertArrayHasKey("$placeholder-{$option->id}-{$userb->id}", placeholders_info::$placeholders);
            $this->assertArrayNotHasKey("$placeholder-{$option->id}", placeholders_info::$placeholders);
        }
        placeholders_info::$placeholders = [];
    }
}
