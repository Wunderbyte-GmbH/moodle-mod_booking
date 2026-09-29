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

/**
 * Trait supervisor_relation_trait.
 *
 * @package     bookingextension_confirmation_supervisor
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_confirmation_supervisor\local;

use mod_booking\singleton_service;

/**
 * Shared profile-field-based supervisor/deputy/HR relationship checks.
 * Used by both confirmbooking and bookforothers, so the "who can act for whom"
 * logic only lives in one place.
 */
trait supervisor_relation_trait {
    /**
     * Determines if user is HR.
     * @param mixed $approverid
     * @return bool
     */
    private static function is_hr($approverid): bool {
        // Check if user is HR. So we need to check bookig extension configuration.
        $hrids = explode(
            ',',
            get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers')
        );

        return in_array($approverid, $hrids);
    }

    /**
     * Determines if approver is supervisor if given user.
     * @param mixed $approverid
     * @param mixed $userid
     * @return bool
     */
    private static function is_supervisor($approverid, $userid): bool {
        $supervisorfieldshortname = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $user = singleton_service::get_instance_of_user($userid, true);
        if (
            $user
            && isset($user->profile[$supervisorfieldshortname])
            && $user->profile[$supervisorfieldshortname] == $approverid
        ) {
            return true;
        }
        return false;
    }

    /**
     * Determines if appriver is deputy of the supervisor of the given user.
     * @param mixed $approverid
     * @param mixed $userid
     * @return bool
     */
    private static function is_deputy($approverid, $userid) {
        $supervisorfield = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $user = singleton_service::get_instance_of_user($userid, true);
        if ($user && !empty($user->profile[$supervisorfield])) {
            // Found the supervisor of the user. (user is owner of the answer).
            $svid = $user->profile[$supervisorfield]; // Supervisor ID.
            $sv = singleton_service::get_instance_of_user($svid, true); // Supervisor.

            if ($sv) {
                $deputies = self::get_deputies($sv);
                foreach ($deputies as $deputyid) {
                    if ($deputyid == $approverid) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Returns deputies of as an array of integers.
     * @param mixed $user
     * @return string[]
     */
    public static function get_deputies($user = null): array {
        if (empty($user)) {
            global $USER;
            $user = $USER;
        }

        if (empty($user)) {
            return [];
        }

        // Load custom profile fileds if not exists in user object.
        $user = singleton_service::get_instance_of_user($user->id, true);

        $deputyfield = get_config('bookingextension_confirmation_supervisor', 'deputy');
        if ($user && isset($user->profile[$deputyfield]) && !empty($user->profile[$deputyfield])) {
            // Deputy Found.
            return explode(',', $user->profile[$deputyfield]);
        }
        return [];
    }

    /**
     * Returns the ids of all users supervised (directly, or via a deputised supervisor) by the given agent.
     *
     * @param int $agentid
     * @return int[]
     */
    public static function get_my_team_user_ids(int $agentid): array {
        global $DB;

        $supervisorfieldshortname = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $supervisorfieldid = $DB->get_field(
            'user_info_field',
            'id',
            ['shortname' => $supervisorfieldshortname],
            IGNORE_MISSING
        );
        if (!$supervisorfieldid) {
            return [];
        }

        // Agent is supervisor for these directly, but also for the team of any supervisor who has
        // appointed the agent as their deputy.
        $supervisorids = [$agentid];

        $deputyfieldshortname = get_config('bookingextension_confirmation_supervisor', 'deputy');
        $deputyfieldid = $deputyfieldshortname ? $DB->get_field(
            'user_info_field',
            'id',
            ['shortname' => $deputyfieldshortname],
            IGNORE_MISSING
        ) : false;

        if ($deputyfieldid) {
            $deputyrecords = $DB->get_records('user_info_data', ['fieldid' => $deputyfieldid]);
            foreach ($deputyrecords as $deputyrecord) {
                $deputyids = array_filter(array_map('trim', explode(',', $deputyrecord->data)));
                if (in_array((string) $agentid, $deputyids)) {
                    $supervisorids[] = $deputyrecord->userid;
                }
            }
        }

        [$insql, $inparams] = $DB->get_in_or_equal($supervisorids);
        $sql = "SELECT userid FROM {user_info_data} WHERE fieldid = ? AND data $insql";
        return array_keys($DB->get_records_sql($sql, array_merge([$supervisorfieldid], $inparams)));
    }
}
