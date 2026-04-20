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
 * Class booking
 *
 * @package    mod_booking
 * @copyright  2026 YOUR NAME <your@email.com>
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
        global $USER;

        // Every user can book for himself/herself.
        if ($USER->id === $agentid || $agentid === $userid) {
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
}
