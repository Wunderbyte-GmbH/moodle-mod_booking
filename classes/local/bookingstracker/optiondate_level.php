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

namespace mod_booking\local\bookingstracker;

use moodle_exception;
use moodle_url;

/**
 * Whether the Bookings tracker (report2.php) supports the level of a single date (optiondate) of a booking option.
 *
 * By default the Bookings tracker has five levels: system, course, booking instance, booking option and date.
 * On the level of a date, presence and notes are tracked per date. Sites that only want to track presence for
 * the whole booking option switch the level off with the site setting booking/bookingstrackerdisableoptiondatelevel.
 *
 * This class is the single place that reads the setting. Everything that depends on the level of a date asks
 * here: the page report2.php, the download of its tables, the dialogs for presence and notes and the presence
 * counter, which counts the presences of the dates.
 *
 * The ticket scanner (scan.php) is not part of the Bookings tracker and keeps checking in per date.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondate_level {
    /** @var string Name of the site setting (plugin booking). Ticked = level of a date switched off. */
    public const SETTING = 'bookingstrackerdisableoptiondatelevel';

    /** @var string Name of the scope of a single date, see \mod_booking\booking_answers\scopes\optiondate. */
    public const SCOPE = 'optiondate';

    /**
     * Whether the Bookings tracker offers the level of a single date.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return empty(get_config('booking', self::SETTING));
    }

    /**
     * Whether the Bookings tracker shows the presence counter.
     *
     * The counter counts the presences stored per date, so it needs the level of a date.
     *
     * @return bool
     */
    public static function presence_counter_enabled(): bool {
        return self::is_enabled() && !empty(get_config('booking', 'bookingstrackerpresencecounter'));
    }

    /**
     * Stops a request that works on the level of a date while the level is switched off.
     *
     * Hiding links is not enough: the download and the dialogs are reachable with a hand-made request.
     *
     * @param string $scope scope of the request, e.g. option or optiondate
     * @return void
     * @throws moodle_exception if the scope is optiondate and the level is switched off
     */
    public static function require_scope_enabled(string $scope): void {
        if ($scope === self::SCOPE && !self::is_enabled()) {
            throw new moodle_exception('bookingstrackeroptiondateleveldisabled', 'mod_booking');
        }
    }

    /**
     * Where a link to the view of a date leads while the level is switched off: the booking option of the date.
     *
     * @param int $optionid
     * @return moodle_url
     */
    public static function fallback_url(int $optionid): moodle_url {
        return new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid]);
    }
}
