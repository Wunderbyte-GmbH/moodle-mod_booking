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

namespace mod_booking\local\slotbooking;

use advanced_testcase;

/**
 * Tests for the effective end of the slot availability period (fixed date or rolling duration).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_validity
 */
final class slot_validity_test extends advanced_testcase {
    /**
     * Fixed 2026-10-27 12:34 in Europe/Vienna, used as "now" in all tests.
     *
     * @return int
     */
    private function now(): int {
        return (new \DateTimeImmutable('2026-10-27 12:34:00', new \DateTimeZone('Europe/Vienna')))->getTimestamp();
    }

    /**
     * Midnight of a date in Europe/Vienna.
     *
     * @param string $date Y-m-d
     * @return int
     */
    private function midnight(string $date): int {
        return (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('Europe/Vienna')))->getTimestamp();
    }

    /**
     * Build a slot config as stored in booking_slot_config.
     *
     * @param int $validuntil
     * @param int $durationvalue
     * @param string $durationunit
     * @return \stdClass
     */
    private function config(int $validuntil, int $durationvalue = 0, string $durationunit = 'months'): \stdClass {
        return (object)[
            'valid_until' => $validuntil,
            'valid_duration_value' => $durationvalue,
            'valid_duration_unit' => $durationunit,
        ];
    }

    /**
     * Set up the user timezone used for the calculation.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Vienna', 'Europe/Vienna');
        $this->setAdminUser();
    }

    /**
     * A fixed date includes the whole selected day (exclusive end = next midnight).
     */
    public function test_fixed_date_includes_the_selected_day(): void {
        $until = $this->midnight('2027-03-01');
        $this->assertSame($until + DAYSECS, slot_validity::get_effective_valid_until($this->config($until), $this->now()));
    }

    /**
     * Neither a date nor a duration means no limit.
     */
    public function test_no_limit_returns_zero(): void {
        $this->assertSame(0, slot_validity::get_effective_valid_until($this->config(0), $this->now()));
    }

    /**
     * Weeks: 3 weeks from the start of today.
     */
    public function test_rolling_weeks(): void {
        $this->assertSame(
            $this->midnight('2026-11-17'),
            slot_validity::get_effective_valid_until($this->config(0, 3, 'weeks'), $this->now())
        );
    }

    /**
     * Days: 10 days from the start of today.
     */
    public function test_rolling_days(): void {
        $this->assertSame(
            $this->midnight('2026-11-06'),
            slot_validity::get_effective_valid_until($this->config(0, 10, 'days'), $this->now())
        );
    }

    /**
     * Months: calendar months, also across the end of the year.
     */
    public function test_rolling_months(): void {
        $this->assertSame(
            $this->midnight('2027-01-27'),
            slot_validity::get_effective_valid_until($this->config(0, 3, 'months'), $this->now())
        );
    }

    /**
     * A rolling duration wins over a stale valid_until that is still stored.
     */
    public function test_rolling_duration_wins_over_stored_valid_until(): void {
        $this->assertSame(
            $this->midnight('2026-11-17'),
            slot_validity::get_effective_valid_until($this->config($this->midnight('2030-01-01'), 3, 'weeks'), $this->now())
        );
    }

    /**
     * The window moves with the clock: one day later the end is one day later.
     */
    public function test_window_moves_with_now(): void {
        $config = $this->config(0, 3, 'weeks');
        $today = slot_validity::get_effective_valid_until($config, $this->now());
        $tomorrow = slot_validity::get_effective_valid_until($config, $this->now() + DAYSECS);
        $this->assertSame($this->midnight('2026-11-18'), $tomorrow);
        $this->assertSame($today + DAYSECS, $tomorrow);
    }

    /**
     * The end of the window does not depend on the time of day.
     */
    public function test_time_of_day_is_irrelevant(): void {
        $config = $this->config(0, 3, 'weeks');
        $this->assertSame(
            slot_validity::get_effective_valid_until($config, $this->midnight('2026-10-27')),
            slot_validity::get_effective_valid_until($config, $this->midnight('2026-10-27') + DAYSECS - 1)
        );
    }

    /**
     * An unknown unit falls back to months instead of producing a wrong window.
     */
    public function test_unknown_unit_falls_back_to_months(): void {
        $this->assertSame(
            $this->midnight('2027-01-27'),
            slot_validity::get_effective_valid_until($this->config(0, 3, 'parsecs'), $this->now())
        );
    }

    /**
     * A config row from before the upgrade (no duration columns) behaves like a fixed date.
     */
    public function test_legacy_config_without_duration_fields(): void {
        $until = $this->midnight('2027-03-01');
        $this->assertSame(
            $until + DAYSECS,
            slot_validity::get_effective_valid_until((object)['valid_until' => $until], $this->now())
        );
    }

    /**
     * Rolling mode is detected from the stored duration.
     */
    public function test_is_rolling(): void {
        $this->assertTrue(slot_validity::is_rolling($this->config(0, 3, 'weeks')));
        $this->assertFalse(slot_validity::is_rolling($this->config($this->midnight('2027-03-01'))));
        $this->assertFalse(slot_validity::is_rolling((object)['valid_until' => 0]));
    }
}
