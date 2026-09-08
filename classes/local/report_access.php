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
 * Access rule for the entry of the booking option report (report.php).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use context_module;

/**
 * Access rule for the entry of the booking option report (report.php).
 *
 * Kept separate from report.php so the rule is unit-testable - the same split
 * as \mod_booking\local\bookingstracker\report2_access, which implements the
 * (wider) entry rule of report2.php.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_access {
    /**
     * Whether the current user may open report.php for the given option:
     * teachers of the option and holders of mod/booking:viewreports get in,
     * everybody else needs the fallback capability mod/booking:readresponses.
     *
     * @param int $cmid
     * @param int $optionid
     * @return bool
     */
    public static function has_report_access(int $cmid, int $optionid): bool {
        $context = context_module::instance($cmid);

        if (booking_check_if_teacher($optionid)) {
            return true;
        }
        if (has_capability('mod/booking:viewreports', $context)) {
            return true;
        }

        return has_capability('mod/booking:readresponses', $context);
    }

    /**
     * Require access to report.php. Users without access get the same
     * "readresponses" capability exception as before the rule was extracted.
     *
     * @param int $cmid
     * @param int $optionid
     * @return void
     * @throws \required_capability_exception
     */
    public static function require_report_access(int $cmid, int $optionid): void {
        if (!self::has_report_access($cmid, $optionid)) {
            require_capability('mod/booking:readresponses', context_module::instance($cmid));
        }
    }
}
