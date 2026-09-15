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

namespace mod_booking\filters;

use local_wunderbyte_table\filters\types\toggle;

/**
 * This class returns the bookable_startingsoon filter, a toggle filter showing only booking
 * options which are currently bookable AND take place within the next days (28 by default,
 * the number of days can be passed as a parameter). Options which already started before
 * today are excluded; options starting today are included.
 * Since it has a slightly more complex implementation, we create it here once,
 * so it can be reused in every instance of mod_booking\table\bookingoptions_wbtable.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookable_startingsoon {
    /**
     * Default number of days ahead within which a booking option counts as "taking place soon".
     *
     * @var int
     */
    public const DAYSAHEAD = 28;

    /**
     * This method returns an instance of the toggle filter configured for bookable_startingsoon.
     * It can be reused in every instance of mod_booking\table\bookingoptions_wbtable.
     * @param int $days number of days ahead, defaults to DAYSAHEAD
     * @return toggle
     */
    public static function get(int $days = self::DAYSAHEAD): toggle {
        if ($days < 1) {
            $days = self::DAYSAHEAD;
        }
        // Toggle: only show booking options which are bookable and start within the next days.
        $toggle = new toggle(
            'bookablestartingsoon',
            get_string('filterbookablestartingsoon', 'mod_booking', $days)
        );
        // The filter depends on the current time, so we must not cache it.
        $toggle->bypass_cache();
        // Options which already started before today must not match the filter,
        // options starting today are included. The timestamp is embedded as a
        // literal because the subquery must contain exactly one placeholder (:where).
        $todaymidnight = strtotime('today');
        // In the following query, we calculate available places (alias: bookablestartingsoon)
        // and export the booking option ID, available places and the course start time
        // for each option as bookablestartingsoontbl.
        // Then, we select the booking option IDs to pass them to the IN operator.
        $subsql = "id IN (
                SELECT id FROM (
                    SELECT sbo.id, sbo.coursestarttime,
                    CASE WHEN
                        sbo.maxanswers=0
                        OR (sbo.maxanswers - COUNT(CASE WHEN sba.waitinglist IN (0, 2) THEN 1 END)) > 0
                    THEN '1'
                    ELSE '0' END AS bookablestartingsoon
                    FROM {booking_options} sbo
                    LEFT JOIN {booking_answers} sba ON sba.optionid = sbo.id
                    WHERE sbo.coursestarttime >= {$todaymidnight}
                    GROUP BY sbo.id, sbo.coursestarttime, sbo.maxanswers, sba.optionid
                ) bookablestartingsoontbl
                WHERE :where
        )";

        $toggle->set_sql($subsql, 'bookablestartingsoon');
        // When the toggle is on, we filter on bookablestartingsoon = '1' ...
        $toggle->set_toggle_value('1', get_string('filterbookablestartingsoon', 'mod_booking', $days));
        // ... and on options starting within the next days.
        $toggle->set_second_column(
            'coursestarttime',
            strtotime('today + ' . $days . ' days'),
            '<='
        );

        return $toggle;
    }
}
