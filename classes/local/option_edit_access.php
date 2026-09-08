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
 * Access rule for the booking option form (editoptions.php).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use context_module;
use moodle_exception;

/**
 * Access rule for the booking option form (editoptions.php).
 *
 * Kept separate from editoptions.php so the rule is unit-testable - the same
 * split as \mod_booking\local\report_access for report.php.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_edit_access {
    /**
     * Whether the current user may open the option form: the three editing
     * layers of mod_booking.
     *
     * Note that "own" means "is a teacher of the option", not "has created
     * it" - see booking_check_if_teacher().
     *
     * @param int $cmid
     * @param int $optionid 0 when a new option is created
     * @return bool
     */
    public static function can_edit_option(int $cmid, int $optionid): bool {
        $context = context_module::instance($cmid);

        // Either the user has the general capability to update booking options...
        if (has_capability('mod/booking:updatebooking', $context)) {
            return true;
        }
        // ... or they have the capability to edit their own options and are actually editing their own option.
        if (has_capability('mod/booking:addeditownoption', $context) && booking_check_if_teacher($optionid)) {
            return true;
        }
        // ... or they have the capability to add options and are creating a new option (optionid is 0).
        return has_capability('mod/booking:addoption', $context) && empty($optionid);
    }

    /**
     * Require access to the option form. Users without access get the same
     * exception as before the rule was extracted.
     *
     * @param int $cmid
     * @param int $optionid
     * @return void
     * @throws moodle_exception
     */
    public static function require_edit_option(int $cmid, int $optionid): void {
        if (!self::can_edit_option($cmid, $optionid)) {
            throw new moodle_exception('nopermissions');
        }
    }
}
