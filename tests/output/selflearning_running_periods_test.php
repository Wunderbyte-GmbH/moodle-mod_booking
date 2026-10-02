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

use mod_booking\output\bookingoption_description;
use mod_booking\output\col_coursestarttime;
use mod_booking\placeholders\placeholders_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Remaining time of self-learning courses: every running period of the user is shown (Wunderbyte-GmbH/Wunderbyte-GmbH#2543).
 *
 * A period starts at the booked date (timebooked, fallback timecreated for old answers without it) and lasts the
 * option's duration. Answers with status booked, reserved and previously booked count as long as their period runs.
 * The answer data mirrors showroom option 71 ("Booking Pro License - 1 year") on 2026-10-02.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selflearning_running_periods_test extends booking_advanced_testcase {
    /** @var int One year, the duration of the showroom license option. */
    private const DURATION = 31536000;

    /** @var int Now: 2026-10-02 19:00 Europe/Vienna, the evening the case was reported. */
    private const NOW = 1790960400;

    /** @var stdClass */
    private $option;

    /** @var stdClass */
    private $student;

    /** @var stdClass */
    private $bookingmodule;

    /**
     * Creates a self-learning option with a duration of one year and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::set_mock_time(self::NOW);

        $course = $this->getDataGenerator()->create_course();
        $this->bookingmodule = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $this->student = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $this->bookingmodule->id;
        $record->text = 'Booking Pro License - 1 year';
        $record->description = 'Booking Pro License - 1 year';
        $record->importing = 1;
        $record->selflearningcourse = 1;
        $record->duration = self::DURATION;
        $record->useprice = 0;
        $this->option = $plugingenerator->create_option($record);

        $this->setUser($this->student);
    }

    /**
     * Inserts a booking answer in the shape of a real one.
     *
     * @param int $waitinglist
     * @param int $timecreated
     * @param int|null $timebooked
     * @param int $timemodified
     * @param int $userid defaults to the student
     * @return int answer id
     */
    private function add_answer(int $waitinglist, int $timecreated, ?int $timebooked, int $timemodified, int $userid = 0): int {
        global $DB;
        return (int) $DB->insert_record('booking_answers', (object) [
            'bookingid' => $this->bookingmodule->id,
            'userid' => $userid ?: $this->student->id,
            'optionid' => $this->option->id,
            'waitinglist' => $waitinglist,
            'timecreated' => $timecreated,
            'timebooked' => $timebooked,
            'timemodified' => $timemodified,
            'completed' => 0,
        ]);
    }

    /**
     * Drops all cached answers so the next read sees the inserted records.
     */
    private function reload(): void {
        booking_option::purge_cache_for_answers($this->option->id);
        singleton_service::destroy_instance();
        placeholders_info::$placeholders = [];
    }

    /**
     * Template data of the option list column for the current user.
     *
     * @return array
     */
    private function column(): array {
        global $PAGE;
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $column = new col_coursestarttime($this->option->id, null, $settings->cmid);
        return $column->export_for_template($PAGE->get_renderer('mod_booking'));
    }

    /**
     * Template data of the option detail page for the current user.
     *
     * @return array
     */
    private function description(): array {
        $description = new bookingoption_description($this->option->id, null, MOD_BOOKING_DESCRIPTION_WEBSITE);
        return $description->get_returnarray();
    }

    /**
     * The rendered {datesandentities} placeholder for the student.
     *
     * @return string
     */
    private function placeholder(): string {
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        placeholders_info::$placeholders = [];
        return placeholders_info::render_text(
            '{datesandentities}',
            $settings->cmid,
            $this->option->id,
            (int) $this->student->id
        );
    }

    /**
     * Remaining time strings the templates must show, oldest period first.
     *
     * @param int[] $starts
     * @return array
     */
    private function expected(array $starts): array {
        return array_map(fn(int $start) => ['timeremaining' => format_time($start + self::DURATION - self::NOW)], $starts);
    }

    /**
     * Trigger case before the data repair: the migrated answer (timecreated 2026-04-08) was touched after the
     * renewal on 2026-10-01 and therefore loaded last. Both periods still run, so both are shown, oldest first,
     * the renewal from its booked date.
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     * @covers \mod_booking\booking_answers\booking_answers::get_running_selflearning_periods
     */
    public function test_renewal_and_migrated_answer_both_running_are_listed_oldest_first(): void {
        $migrated = strtotime('2026-04-08 16:53:40 Europe/Vienna');
        $renewalcreated = strtotime('2026-10-01 13:07:19 Europe/Vienna');
        $renewalbooked = strtotime('2026-10-01 13:10:38 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $migrated, $migrated, strtotime('2026-10-01 13:53:46 Europe/Vienna'));
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $renewalcreated, $renewalbooked, $renewalbooked);
        $this->reload();

        $expected = $this->expected([$migrated, $renewalbooked]);
        foreach (['column' => $this->column(), 'description' => $this->description()] as $where => $data) {
            $this->assertSame($expected, $data['timeremainings'], $where);
            $this->assertSame($expected[0]['timeremaining'], $data['timeremaining'], $where);
            $this->assertTrue($data['selflearningcourseshowdurationinfo'], $where);
            $this->assertEmpty($data['selflearningcourseshowdurationinfoexpired'], $where);
        }
    }

    /**
     * Trigger case after the data repair: the old license (bought 2025-10-01) expired and is previously booked,
     * only the renewal runs. Shown: one period from the renewal's booked date - not "expired".
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_expired_previous_license_is_hidden_and_renewal_is_shown(): void {
        $old = strtotime('2025-10-01 08:21:36 Europe/Vienna');
        $renewalbooked = strtotime('2026-10-01 13:10:38 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, $old, $old, strtotime('2026-10-02 18:30:00 Europe/Vienna'));
        $renewalcreated = strtotime('2026-10-01 13:07:19 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $renewalcreated, $renewalbooked, $renewalbooked);
        $this->reload();

        $expected = $this->expected([$renewalbooked]);
        foreach (['column' => $this->column(), 'description' => $this->description()] as $where => $data) {
            $this->assertSame($expected, $data['timeremainings'], $where);
            $this->assertEmpty($data['selflearningcourseshowdurationinfoexpired'], $where);
        }
    }

    /**
     * A previously booked license that still runs (license for another site, bought 2026-01-23) is shown
     * together with the current one (2026-08-04), oldest first.
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_running_previously_booked_answer_is_shown_with_current_answer(): void {
        $previous = strtotime('2026-01-23 10:29:00 Europe/Vienna');
        $currentbooked = strtotime('2026-08-04 11:15:44 Europe/Vienna');
        // The current answer is inserted first and modified last, so neither id nor timemodified give the order.
        $currentcreated = strtotime('2026-08-04 11:09:23 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $currentcreated, $currentbooked, self::NOW);
        $this->add_answer(MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, $previous, $previous, $previous);
        $this->reload();

        $expected = $this->expected([$previous, $currentbooked]);
        $this->assertSame($expected, $this->column()['timeremainings']);
        $this->assertSame($expected, $this->description()['timeremainings']);
    }

    /**
     * The period starts at the booked date (checkout), not at the creation of the answer (cart).
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_period_starts_at_timebooked(): void {
        $created = strtotime('2026-10-01 13:07:19 Europe/Vienna');
        $booked = strtotime('2026-10-01 13:10:38 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $created, $booked, $booked);
        $this->reload();

        $this->assertSame($this->expected([$booked]), $this->column()['timeremainings']);
        $this->assertSame($this->expected([$booked]), $this->description()['timeremainings']);
    }

    /**
     * Data provider: empty timebooked values of old answers.
     *
     * @return array
     */
    public static function missing_timebooked_provider(): array {
        return [
            'null' => [null],
            'zero' => [0],
        ];
    }

    /**
     * Old answers without a booked date keep the old behaviour: the period starts at timecreated.
     *
     * @dataProvider missing_timebooked_provider
     * @param int|null $timebooked
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     * @covers \mod_booking\placeholders\placeholders\datesandentities
     */
    public function test_old_answer_without_timebooked_falls_back_to_timecreated(?int $timebooked): void {
        $created = strtotime('2026-02-26 09:53:00 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $created, $timebooked, $created);
        $this->reload();

        $this->assertSame($this->expected([$created]), $this->column()['timeremainings']);
        $this->assertSame($this->expected([$created]), $this->description()['timeremainings']);
        $this->assertSame($this->expected_placeholder([$created]), $this->placeholder());
    }

    /**
     * Only expired periods: "expired" is shown, as before, and no remaining time.
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     * @covers \mod_booking\placeholders\placeholders\datesandentities
     */
    public function test_only_expired_periods_show_expired(): void {
        $old = strtotime('2025-06-26 10:12:00 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $old, $old, $old);
        $this->reload();

        foreach (['column' => $this->column(), 'description' => $this->description()] as $where => $data) {
            $this->assertEmpty($data['timeremainings'] ?? [], $where);
            $this->assertArrayNotHasKey('timeremaining', $data, $where);
            $this->assertNull($data['selflearningcourseshowdurationinfo'], $where);
            $this->assertTrue($data['selflearningcourseshowdurationinfoexpired'], $where);
        }
        $this->assertSame(
            get_string('selflearningcourseplaceholder', 'mod_booking') . ' '
                . get_string('selflearningcourseplaceholderdurationexpired', 'mod_booking'),
            $this->placeholder()
        );
    }

    /**
     * Without any answer of the user the generic duration info is shown, as before.
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     * @covers \mod_booking\placeholders\placeholders\datesandentities
     */
    public function test_without_answer_the_duration_info_is_shown(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, self::NOW - 86400, self::NOW - 86400, self::NOW - 86400, $other->id);
        $this->reload();

        foreach (['column' => $this->column(), 'description' => $this->description()] as $where => $data) {
            $this->assertEmpty($data['timeremainings'] ?? [], $where);
            $this->assertArrayNotHasKey('timeremaining', $data, $where);
            $this->assertTrue($data['selflearningcourseshowdurationinfo'], $where);
            $this->assertEmpty($data['selflearningcourseshowdurationinfoexpired'], $where);
            $this->assertSame(format_time(self::DURATION), $data['duration'], $where);
        }
        $this->assertSame(get_string('selflearningcourseplaceholder', 'mod_booking'), $this->placeholder());
    }

    /**
     * A previously booked answer alone (no active answer) still shows its running period, but never "expired".
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_expired_previously_booked_answer_alone_shows_no_expired_info(): void {
        $old = strtotime('2025-05-07 10:23:00 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, $old, $old, $old);
        $this->reload();

        foreach (['column' => $this->column(), 'description' => $this->description()] as $where => $data) {
            $this->assertEmpty($data['timeremainings'] ?? [], $where);
            $this->assertTrue($data['selflearningcourseshowdurationinfo'], $where);
            $this->assertEmpty($data['selflearningcourseshowdurationinfoexpired'], $where);
        }
    }

    /**
     * A reserved answer (in the cart) shows its remaining time from timecreated, as before.
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_reserved_answer_shows_remaining_time(): void {
        $created = self::NOW - 600;
        $this->add_answer(MOD_BOOKING_STATUSPARAM_RESERVED, $created, null, $created);
        $this->reload();

        $this->assertSame($this->expected([$created]), $this->column()['timeremainings']);
        $this->assertSame($this->expected([$created]), $this->description()['timeremainings']);
    }

    /**
     * Deleted answers and answers of other users never count.
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_deleted_and_foreign_answers_are_ignored(): void {
        $other = $this->getDataGenerator()->create_user();
        $mine = strtotime('2026-03-24 17:10:15 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_DELETED, self::NOW - 86400, self::NOW - 86400, self::NOW - 86400);
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, self::NOW - 3600, self::NOW - 3600, self::NOW - 3600, $other->id);
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $mine, $mine, $mine);
        $this->reload();

        $this->assertSame($this->expected([$mine]), $this->column()['timeremainings']);
        $this->assertSame($this->expected([$mine]), $this->description()['timeremainings']);
    }

    /**
     * The setting "hide duration" hides everything, also with several running periods.
     *
     * @covers \mod_booking\output\col_coursestarttime
     * @covers \mod_booking\output\bookingoption_description
     */
    public function test_hide_duration_setting_hides_all_periods(): void {
        set_config('selflearningcoursehideduration', 1, 'booking');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, self::NOW - 100 * 86400, self::NOW - 100 * 86400, self::NOW);
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, self::NOW - 86400, self::NOW - 86400, self::NOW - 86400);
        $this->reload();

        foreach (['column' => $this->column(), 'description' => $this->description()] as $where => $data) {
            $this->assertNull($data['selflearningcourseshowdurationinfo'], $where);
            $this->assertEmpty($data['timeremainings'] ?? [], $where);
            $this->assertArrayNotHasKey('timeremaining', $data, $where);
        }
    }

    /**
     * Placeholder text: one duration sentence per running period, oldest first, rounded up to full hours.
     *
     * @covers \mod_booking\placeholders\placeholders\datesandentities
     */
    public function test_placeholder_lists_every_running_period(): void {
        $migrated = strtotime('2026-04-08 16:53:40 Europe/Vienna');
        $renewalbooked = strtotime('2026-10-01 13:10:38 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, $migrated, $migrated, self::NOW);
        $renewalcreated = strtotime('2026-10-01 13:07:19 Europe/Vienna');
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, $renewalcreated, $renewalbooked, $renewalbooked);
        $this->reload();

        $this->assertSame($this->expected_placeholder([$migrated, $renewalbooked]), $this->placeholder());
    }

    /**
     * Expected placeholder text for the given period starts.
     *
     * @param int[] $starts
     * @return string
     */
    private function expected_placeholder(array $starts): string {
        $value = get_string('selflearningcourseplaceholder', 'mod_booking');
        foreach ($starts as $start) {
            $seconds = (int) ceil(($start + self::DURATION - self::NOW) / 3600) * 3600;
            $value .= ' ' . get_string('selflearningcourseplaceholderduration', 'mod_booking', format_time($seconds));
        }
        return $value;
    }

    /**
     * Options with dates are not affected: no remaining time data at all.
     *
     * @covers \mod_booking\output\col_coursestarttime
     */
    public function test_option_with_dates_has_no_remaining_time(): void {
        global $DB;
        $DB->set_field('booking_options', 'type', 0, ['id' => $this->option->id]);
        $this->add_answer(MOD_BOOKING_STATUSPARAM_BOOKED, self::NOW - 86400, self::NOW - 86400, self::NOW - 86400);
        booking_option::purge_cache_for_option($this->option->id);
        $this->reload();

        $data = $this->column();
        $this->assertArrayNotHasKey('timeremainings', $data);
        $this->assertArrayNotHasKey('timeremaining', $data);
        $this->assertArrayNotHasKey('selflearningcourse', $data);
    }
}
