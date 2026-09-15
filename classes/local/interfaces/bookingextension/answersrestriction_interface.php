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

namespace mod_booking\local\interfaces\bookingextension;

use mod_booking\booking_answers\scope_base;

/**
 * Class answersrestriction_interface
 *
 * A booking extension can implement this interface to limit the booking answers the
 * current user is allowed to see in the bookings tracker (report2.php) and in all
 * other views built on the booking answers scopes.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface answersrestriction_interface {
    /**
     * Returns the ids of the users whose booking answers the current user may see.
     *
     * Return null if this extension does not restrict the current user at all - this is
     * the default and means that all answers of the scope stay visible. An empty array
     * means that the user is restricted but has nobody to look at, so no answer is shown.
     *
     * The scope class is handed over so the implementation can check capabilities in the
     * context of the current scope (see scope_base::has_capability_in_scope).
     *
     * @param scope_base $scopeclass the class of the scope which is being displayed
     * @param int $scopeid optionid | optiondateid | cmid | courseid | 0
     * @return int[]|null
     */
    public static function restrict_to_user_ids(scope_base $scopeclass, int $scopeid): ?array;
}
