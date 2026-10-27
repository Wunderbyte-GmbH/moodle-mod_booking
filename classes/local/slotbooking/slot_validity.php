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
 * End of the slot availability period: fixed date or rolling duration relative to now.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use core_date;

/**
 * Single source of truth for "until when are slots offered".
 */
class slot_validity {
    /** @var string[] Allowed units of the rolling duration. */
    public const UNITS = ['days', 'weeks', 'months'];

    /** @var string Unit used when the stored one is unknown. */
    public const DEFAULT_UNIT = 'months';

    /**
     * Whether the option uses a rolling duration instead of a fixed end date.
     *
     * @param object $config row of booking_slot_config
     * @return bool
     */
    public static function is_rolling(object $config): bool {
        return (int)($config->valid_duration_value ?? 0) > 0;
    }

    /**
     * Exclusive end timestamp of the availability period, 0 = no limit.
     *
     * Fixed date: the whole selected day is included (valid_until is midnight, so + one day).
     * Rolling: start of today (user timezone) plus the duration, so "3 weeks" is exactly 21 days.
     *
     * @param object $config row of booking_slot_config
     * @param int|null $now reference time, defaults to time()
     * @return int
     */
    public static function get_effective_valid_until(object $config, ?int $now = null): int {
        if (self::is_rolling($config)) {
            $unit = (string)($config->valid_duration_unit ?? '');
            if (!in_array($unit, self::UNITS, true)) {
                $unit = self::DEFAULT_UNIT;
            }
            $value = (int)$config->valid_duration_value;

            $today = (new \DateTimeImmutable('@' . ($now ?? time())))
                ->setTimezone(core_date::get_user_timezone_object())
                ->setTime(0, 0);

            return $today->modify('+' . $value . ' ' . $unit)->getTimestamp();
        }

        if (!empty($config->valid_until)) {
            return (int)$config->valid_until + DAYSECS;
        }

        return 0;
    }
}
