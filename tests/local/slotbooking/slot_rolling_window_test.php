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
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_dto;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that a rolling availability window limits what slot_availability offers and accepts.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_availability
 */
final class slot_rolling_window_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Vienna', 'Europe/Vienna');
        $this->setAdminUser();
    }

    /**
     * A slot inside the window is accepted, one behind it is rejected, and a slot right at the end
     * of the window is rejected (exclusive end).
     *
     * @return void
     */
    public function test_is_within_slot_openings_respects_rolling_window(): void {
        $optionid = $this->create_daily_slot_option();
        $this->set_rolling_duration($optionid, 3, 'weeks');

        [$start, $end] = $this->slot_on('+10 days');
        $this->assertTrue(slot_availability::is_within_slot_openings($optionid, $start, $end));

        [$start, $end] = $this->slot_on('+20 days');
        $this->assertTrue(slot_availability::is_within_slot_openings($optionid, $start, $end), 'Last day inside 3 weeks.');

        [$start, $end] = $this->slot_on('+21 days');
        $this->assertFalse(slot_availability::is_within_slot_openings($optionid, $start, $end), 'End of window is exclusive.');

        [$start, $end] = $this->slot_on('+60 days');
        $this->assertFalse(slot_availability::is_within_slot_openings($optionid, $start, $end));
    }

    /**
     * The calendar range generation stops at the end of the rolling window.
     *
     * @return void
     */
    public function test_get_slots_for_range_stops_at_rolling_window(): void {
        $optionid = $this->create_daily_slot_option();
        $this->set_rolling_duration($optionid, 3, 'weeks');

        $windowend = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Vienna')))->modify('+21 days')->getTimestamp();
        $slots = slot_availability::get_slots_for_range($optionid, time(), time() + 90 * DAYSECS);

        $this->assertNotEmpty($slots);
        $latestend = max(array_map(fn($slot) => (int)$slot[1], $slots));
        $this->assertLessThanOrEqual($windowend, $latestend, 'No slot may end behind the rolling window.');
    }

    /**
     * Without a rolling duration the same range is not cut short.
     *
     * @return void
     */
    public function test_get_slots_for_range_without_duration_is_not_cut(): void {
        $optionid = $this->create_daily_slot_option();

        $slots = slot_availability::get_slots_for_range($optionid, time(), time() + 90 * DAYSECS);
        $latestend = max(array_map(fn($slot) => (int)$slot[1], $slots));
        $this->assertGreaterThan(time() + 60 * DAYSECS, $latestend);
    }

    /**
     * A rolling window longer than the default 365 days is not cut at one year when the calendar
     * asks for the default range, and still ends at the window end.
     *
     * @return void
     */
    public function test_default_range_covers_windows_longer_than_a_year(): void {
        $optionid = $this->create_daily_slot_option();
        $this->set_rolling_duration($optionid, 18, 'months');

        $windowend = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Vienna')))->modify('+18 months')->getTimestamp();
        $slots = slot_availability::get_slots_with_status($optionid);

        $this->assertNotEmpty($slots);
        $latestend = max(array_map(fn($slot) => (int)$slot['end'], $slots));
        $this->assertGreaterThan(time() + 400 * DAYSECS, $latestend, 'Slots must reach past one year.');
        $this->assertLessThanOrEqual($windowend, $latestend);
    }

    /**
     * The picker meta tells the frontend the effective end of the period, also for a rolling window.
     *
     * @return void
     */
    public function test_build_meta_exposes_effective_end(): void {
        $optionid = $this->create_daily_slot_option();
        $userid = (int)self::getDataGenerator()->create_user()->id;

        $meta = slot_dto::build_meta($optionid, $userid);
        $this->assertSame(0, $meta['effectivevaliduntil'], 'No limit configured.');

        $this->set_rolling_duration($optionid, 3, 'weeks');
        $meta = slot_dto::build_meta($optionid, $userid);
        $windowend = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Vienna')))->modify('+21 days')->getTimestamp();
        $this->assertSame($windowend, $meta['effectivevaliduntil']);
        $this->assertSame(0, $meta['validuntil'], 'The stored fixed date stays untouched.');
    }

    /**
     * Without a rolling duration (and without valid_until) slots far in the future stay accepted.
     *
     * @return void
     */
    public function test_without_duration_far_future_slot_is_accepted(): void {
        $optionid = $this->create_daily_slot_option();

        [$start, $end] = $this->slot_on('+60 days');
        $this->assertTrue(slot_availability::is_within_slot_openings($optionid, $start, $end));
    }

    /**
     * Switching back to a fixed date makes the stored valid_until count again.
     *
     * @return void
     */
    public function test_fixed_date_still_applies_without_duration(): void {
        $optionid = $this->create_daily_slot_option();
        global $DB;
        $DB->set_field('booking_slot_config', 'valid_until', strtotime('midnight +15 days'), ['optionid' => $optionid]);
        $this->reset_option_cache($optionid);

        [$start, $end] = $this->slot_on('+10 days');
        $this->assertTrue(slot_availability::is_within_slot_openings($optionid, $start, $end));

        [$start, $end] = $this->slot_on('+20 days');
        $this->assertFalse(slot_availability::is_within_slot_openings($optionid, $start, $end));
    }

    /**
     * Saving the option form in rolling mode stores the duration and drops the fixed end date.
     *
     * @return void
     */
    public function test_saving_rolling_mode_stores_duration_and_clears_valid_until(): void {
        global $DB;
        $optionid = $this->create_daily_slot_option([
            'slot_valid_mode' => 'rolling',
            'slot_valid_duration_value' => 3,
            'slot_valid_duration_unit' => 'weeks',
            'slot_valid_until' => strtotime('2030-01-01 00:00:00 UTC'),
        ]);

        $config = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', MUST_EXIST);
        $this->assertSame(3, (int)$config->valid_duration_value);
        $this->assertSame('weeks', $config->valid_duration_unit);
        $this->assertSame(0, (int)$config->valid_until);
    }

    /**
     * Saving in fixed mode keeps the date and resets the duration, even if a duration was submitted.
     *
     * @return void
     */
    public function test_saving_fixed_mode_keeps_date_and_resets_duration(): void {
        global $DB;
        $until = strtotime('2030-01-01 00:00:00 UTC');
        $optionid = $this->create_daily_slot_option([
            'slot_valid_mode' => 'fixed',
            'slot_valid_duration_value' => 3,
            'slot_valid_duration_unit' => 'weeks',
            'slot_valid_until' => $until,
        ]);

        $config = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$config->valid_duration_value);
        $this->assertSame($until, (int)$config->valid_until);
    }

    /**
     * Callers that know nothing about the mode (import, wizard, AI skill) keep the fixed behaviour.
     *
     * @return void
     */
    public function test_saving_without_mode_stays_fixed(): void {
        global $DB;
        $optionid = $this->create_daily_slot_option(['slot_valid_until' => strtotime('2030-01-01 00:00:00 UTC')]);

        $config = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$config->valid_duration_value);
    }

    /**
     * An invalid duration or unit in rolling mode is normalised instead of stored as garbage.
     *
     * @return void
     */
    public function test_saving_rolling_mode_normalises_invalid_values(): void {
        global $DB;
        $optionid = $this->create_daily_slot_option([
            'slot_valid_mode' => 'rolling',
            'slot_valid_duration_value' => 0,
            'slot_valid_duration_unit' => 'parsecs',
        ]);

        $config = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$config->valid_duration_value, 'Rolling mode needs at least 1.');
        $this->assertSame('months', $config->valid_duration_unit);
    }

    /**
     * The option form is pre-filled with the stored rolling window.
     *
     * @return void
     */
    public function test_set_data_loads_rolling_window(): void {
        $optionid = $this->create_daily_slot_option([
            'slot_valid_mode' => 'rolling',
            'slot_valid_duration_value' => 3,
            'slot_valid_duration_unit' => 'weeks',
        ]);

        $data = $this->load_form_data($optionid);

        $this->assertSame('rolling', $data->slot_valid_mode);
        $this->assertSame(3, $data->slot_valid_duration_value);
        $this->assertSame('weeks', $data->slot_valid_duration_unit);
    }

    /**
     * An option with a fixed end date loads in fixed mode.
     *
     * @return void
     */
    public function test_set_data_loads_fixed_mode(): void {
        $until = strtotime('2030-01-01 00:00:00 UTC');
        $optionid = $this->create_daily_slot_option(['slot_valid_until' => $until]);

        $data = $this->load_form_data($optionid);

        $this->assertSame('fixed', $data->slot_valid_mode);
        $this->assertSame($until, $data->slot_valid_until);
    }

    /**
     * A new option (no stored config) starts in fixed mode with sensible duration defaults.
     *
     * @return void
     */
    public function test_set_data_defaults_for_new_option(): void {
        $data = new \stdClass();
        $settings = singleton_service::get_instance_of_booking_option_settings(0);
        \mod_booking\option\fields\slotbooking::set_data($data, $settings);

        $this->assertSame('fixed', $data->slot_valid_mode);
        $this->assertSame(3, $data->slot_valid_duration_value);
        $this->assertSame('months', $data->slot_valid_duration_unit);
    }

    /**
     * Import flow: a new option without any rolling keys gets the fixed-mode defaults.
     *
     * @return void
     */
    public function test_set_data_import_defaults(): void {
        $data = new \stdClass();
        $data->importing = true;
        $settings = singleton_service::get_instance_of_booking_option_settings(0);
        \mod_booking\option\fields\slotbooking::set_data($data, $settings);

        $this->assertSame('fixed', $data->slot_valid_mode);
        $this->assertSame(3, $data->slot_valid_duration_value);
        $this->assertSame('months', $data->slot_valid_duration_unit);
    }

    /**
     * Values given explicitly by the import file are kept.
     *
     * @return void
     */
    public function test_set_data_import_keeps_explicit_values(): void {
        $optionid = $this->create_daily_slot_option([
            'slot_valid_mode' => 'rolling',
            'slot_valid_duration_value' => 3,
            'slot_valid_duration_unit' => 'weeks',
        ]);

        $data = new \stdClass();
        $data->importing = true;
        $data->id = $optionid;
        $data->slot_valid_mode = 'fixed';
        $data->slot_valid_duration_value = 9;
        $data->slot_valid_duration_unit = 'days';
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        \mod_booking\option\fields\slotbooking::set_data($data, $settings);

        $this->assertSame('fixed', $data->slot_valid_mode);
        $this->assertSame(9, $data->slot_valid_duration_value);
        $this->assertSame('days', $data->slot_valid_duration_unit);
    }

    /**
     * Rolling mode needs a duration of at least 1.
     *
     * @return void
     */
    public function test_validation_rejects_rolling_duration_below_one(): void {
        $errors = $this->validate(['slot_valid_mode' => 'rolling', 'slot_valid_duration_value' => 0]);
        $this->assertArrayHasKey('slot_valid_duration_value', $errors);
        $this->assertSame(get_string('slot_error_duration_positive', 'mod_booking'), $errors['slot_valid_duration_value']);
    }

    /**
     * A valid rolling duration passes, and the hidden fixed dates are not checked against each other.
     *
     * @return void
     */
    public function test_validation_accepts_rolling_duration_and_ignores_fixed_dates(): void {
        $errors = $this->validate([
            'slot_valid_mode' => 'rolling',
            'slot_valid_duration_value' => 3,
            'slot_valid_duration_unit' => 'weeks',
            'slot_valid_from' => strtotime('2030-01-01 00:00:00 UTC'),
            'slot_valid_until' => 0,
        ]);
        $this->assertArrayNotHasKey('slot_valid_duration_value', $errors);
        $this->assertArrayNotHasKey('slot_valid_until', $errors);
    }

    /**
     * An unknown unit is rejected.
     *
     * @return void
     */
    public function test_validation_rejects_unknown_unit(): void {
        $errors = $this->validate([
            'slot_valid_mode' => 'rolling',
            'slot_valid_duration_value' => 3,
            'slot_valid_duration_unit' => 'parsecs',
        ]);
        $this->assertArrayHasKey('slot_valid_duration_unit', $errors);
    }

    /**
     * In fixed mode the old range check still applies and the duration is not validated.
     *
     * @return void
     */
    public function test_validation_fixed_mode_keeps_range_check(): void {
        $errors = $this->validate([
            'slot_valid_mode' => 'fixed',
            'slot_valid_duration_value' => 0,
            'slot_valid_from' => strtotime('2030-02-01 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2030-01-01 00:00:00 UTC'),
        ]);
        $this->assertArrayHasKey('slot_valid_until', $errors);
        $this->assertArrayNotHasKey('slot_valid_duration_value', $errors);
    }

    /**
     * Run the slot field validation with a otherwise valid fixed-slot form.
     *
     * @param array $overrides form values to set
     * @return array errors
     */
    private function validate(array $overrides): array {
        $data = $overrides + [
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'slot_type' => 'fixed',
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_max_participants_per_slot' => 1,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'list',
        ];
        $errors = [];
        \mod_booking\option\fields\slotbooking::validation($data, [], $errors);
        return $errors;
    }

    /**
     * Run the option form's data loading for an existing option.
     *
     * @param int $optionid
     * @return \stdClass
     */
    private function load_form_data(int $optionid): \stdClass {
        $data = new \stdClass();
        $data->id = $optionid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        \mod_booking\option\fields\slotbooking::set_data($data, $settings);
        return $data;
    }

    /**
     * One hour slot at 09:00 (user timezone) on the given relative day.
     *
     * @param string $relative e.g. "+10 days"
     * @return int[] [start, end]
     */
    private function slot_on(string $relative): array {
        $day = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Vienna')))->modify($relative);
        $start = $day->setTime(9, 0)->getTimestamp();
        return [$start, $start + HOURSECS];
    }

    /**
     * Store a rolling duration on the slot config like the option form will do.
     *
     * @param int $optionid
     * @param int $value
     * @param string $unit
     * @return void
     */
    private function set_rolling_duration(int $optionid, int $value, string $unit): void {
        global $DB;
        $DB->set_field('booking_slot_config', 'valid_until', 0, ['optionid' => $optionid]);
        $DB->set_field('booking_slot_config', 'valid_duration_value', $value, ['optionid' => $optionid]);
        $DB->set_field('booking_slot_config', 'valid_duration_unit', $unit, ['optionid' => $optionid]);
        $this->reset_option_cache($optionid);
    }

    /**
     * Make the next settings read hit the database again.
     *
     * @param int $optionid
     * @return void
     */
    private function reset_option_cache(int $optionid): void {
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Slot option open every day 09:00-12:00 without any validity dates.
     *
     * @param array $extra additional/overriding option form values
     * @return int option id
     */
    private function create_daily_slot_option(array $extra = []): int {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Rolling window option ' . uniqid('', true),
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
            'slot_valid_from' => 0,
            'slot_valid_until' => 0,
            'slot_max_participants_per_slot' => 1,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) ($extra + $record));
        singleton_service::destroy_instance();

        return (int) $option->id;
    }
}
