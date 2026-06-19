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

namespace mod_booking\local\bookingworkflow;

/**
 * Class bookforothers
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookforothers {
    /**
     * Checks all bookingextension subplugins for confirm capability.
     *
     * @param int $optionid ID of the booking option.
     * @param int $agentid ID of the user trying to book for other user.
     * @param int $userid ID of the user being booked for.
     * @return array [$allowed (bool), $message (string), $reload (bool)]
     */
    public static function check_booking_capability(int $optionid, int $agentid, int $userid): array {
        // Every user can book for himself/herself.
        if ($agentid === $userid) {
            return [true, '', false];
        }

        // Users with the unrestricted "bookforothers" capability (e.g. cashiers) are always allowed.
        $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = \context_module::instance($settings->cmid);
        if (has_capability('mod/booking:bookforothers', $context, $agentid)) {
            return [true, '', false];
        }

        $allowedtobook = false;
        $returnmessage = get_string('notallowedtobookforothers', 'mod_booking');
        $reload = false;

        foreach (\core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classname = "\\bookingextension_{$plugin->name}\\local\\bookforothers";

            if (class_exists($classname)) {
                // Skip if subplugin is disabled.
                if (!get_config('bookingextension_' . $plugin->name, str_replace('_', '', $plugin->name) . 'enabled')) {
                    continue;
                }

                [$allowed, $message, $reloadflag] =
                    $classname::has_capability_to_book_for_others($optionid, $agentid, $userid);

                if ($allowed) {
                    return [true, '', false]; // Short-circuit on first positive.
                } else {
                    $returnmessage = $message;
                    $reload = $reloadflag ?? false;
                }
            }
        }

        return [$allowedtobook, $returnmessage, $reload];
    }

    /**
     * Returns the ids of the users this agent is allowed to book for, for use in user pickers.
     * Returns null when the agent is unrestricted (eg. has the "bookforothers" capability),
     * meaning no filtering should be applied.
     *
     * @param int $optionid
     * @param int $agentid
     * @return int[]|null
     */
    public static function get_bookable_target_ids(int $optionid, int $agentid): ?array {
        $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = \context_module::instance($settings->cmid);

        // Unrestricted agents (eg. cashiers) are not limited to a team.
        if (has_capability('mod/booking:bookforothers', $context, $agentid)) {
            return null;
        }

        $ids = [];
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classname = "\\bookingextension_{$plugin->name}\\local\\bookforothers";

            if (class_exists($classname) && method_exists($classname, 'get_my_team_user_ids')) {
                // Skip if subplugin is disabled.
                if (!get_config('bookingextension_' . $plugin->name, str_replace('_', '', $plugin->name) . 'enabled')) {
                    continue;
                }

                $ids = array_merge($ids, $classname::get_my_team_user_ids($agentid));
            }
        }

        return array_unique($ids);
    }
}
