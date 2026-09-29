<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace bookingextension_confirmation_supervisor\local;

use context_system;
use mod_booking\booking_answers\scope_base;
use mod_booking\local\interfaces\bookingextension\answersrestriction_interface;

/**
 * Class answersrestriction
 *
 * Limits the booking answers a supervisor sees in the bookings tracker (report2.php) and
 * in all other views built on the booking answers scopes to the answers of their own team.
 *
 * @package    bookingextension_confirmation_supervisor
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answersrestriction implements answersrestriction_interface {
    use supervisor_relation_trait;

    /**
     * Returns the ids of the users whose booking answers the current user may see.
     *
     * Null means that the current user is not restricted at all.
     *
     * @param scope_base $scopeclass
     * @param int $scopeid
     * @return int[]|null
     */
    public static function restrict_to_user_ids(scope_base $scopeclass, int $scopeid): ?array {
        global $USER;

        // The restriction is switched off by default, so nothing changes for existing sites.
        if (!get_config('bookingextension_confirmation_supervisor', 'restricttrackertomyteam')) {
            return null;
        }

        // Users who may approve for everybody (e.g. managers) also see everybody.
        if (has_capability('mod/booking:seealllisttoapprove', context_system::instance())) {
            return null;
        }

        // HR is responsible for the whole organisation and therefore not bound to a team.
        if (self::is_hr($USER->id)) {
            return null;
        }

        // Teachers and managers of the current scope keep the full view: the restriction only
        // applies to users whose access to the answers comes from their role as supervisor.
        if ($scopeclass->has_capability_in_scope($scopeid, 'mod/booking:managebookedusers')) {
            return null;
        }

        // A supervisor sees the answers of their team - and their own answers.
        return array_merge([(int) $USER->id], self::get_my_team_user_ids($USER->id));
    }
}
