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
 * Table used for aggregated booking-option rows in the bookings tracker.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

/**
 * Table used for aggregated booking-option rows in the bookings tracker.
 */
class aggregated_options_table extends manageusers_table {
    /**
     * Add deterministic secondary columns to the default newest-first sort.
     *
     * The table API exposes one default column. The aggregated query can return
     * several options with the same second-resolution timecreated value, so its
     * generated ORDER BY needs explicit ascending tie-breakers.
     *
     * @return string
     */
    public function get_sql_sort(): string {
        $sort = parent::get_sql_sort();
        // PostgreSQL adds explicit NULL placement to Moodle's generated sort.
        if (preg_match('/^timecreated\s+DESC(?:\s+NULLS\s+LAST)?$/i', trim($sort))) {
            $sort .= ', id DESC';
        } else if (empty($sort)) {
            $sort = 'timecreated DESC, id DESC';
        }
        return $sort;
    }
}
