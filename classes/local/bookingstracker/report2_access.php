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
 * Access rules for the scopes of the bookings tracker (report2.php).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bookingstracker;

use context_course;
use context_module;
use context_system;

/**
 * Access rules for the scopes of the bookings tracker (report2.php).
 *
 * Kept separate from report2.php so the rules are unit-testable.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report2_access {
    /**
     * Whether the current user may view the option and optiondate scopes.
     *
     * Everyone who could open the old report.php gets access (teachers of the
     * option, mod/booking:viewreports, fallback mod/booking:readresponses -
     * see report.php:188-192), plus mod/booking:updatebooking, which gated the
     * tracker so far. Viewing does NOT grant any write action: every update
     * (presence, notes, deleting answers, messages, ratings, enrolments,
     * certificates ...) keeps its own capability check in the corresponding
     * dynamic form or table action.
     *
     * @param int $cmid
     * @param int $optionid
     * @return bool
     */
    public static function has_option_scope_access(int $cmid, int $optionid): bool {
        $context = context_module::instance($cmid);

        if (booking_check_if_teacher($optionid)) {
            return true;
        }
        if (has_capability('mod/booking:viewreports', $context)) {
            return true;
        }
        if (has_capability('mod/booking:readresponses', $context)) {
            return true;
        }
        // The audience of the tracker before the access was widened.
        if (has_capability('mod/booking:updatebooking', $context)) {
            return true;
        }

        return false;
    }

    /**
     * Whether the current user may view the system scope (all bookings of the
     * whole site): managebookedusers checked in the SYSTEM context, so only a
     * global role assignment counts (the system context has no parents).
     *
     * @return bool
     */
    public static function has_system_scope_access(): bool {
        return has_capability('mod/booking:managebookedusers', context_system::instance());
    }

    /**
     * Whether the current user may view the course scope: managebookedusers
     * checked in the COURSE context, so a course role assignment (or an
     * inherited global one) counts - a module-level assignment does not.
     *
     * @param int $courseid
     * @return bool
     */
    public static function has_course_scope_access(int $courseid): bool {
        return has_capability('mod/booking:managebookedusers', context_course::instance($courseid));
    }

    /**
     * Whether the current user may view the instance scope: managebookedusers
     * checked in the MODULE context (module, course or global assignment).
     *
     * @param int $cmid
     * @return bool
     */
    public static function has_instance_scope_access(int $cmid): bool {
        return has_capability('mod/booking:managebookedusers', context_module::instance($cmid));
    }
}
