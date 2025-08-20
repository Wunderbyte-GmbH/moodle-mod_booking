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

namespace mod_booking\local\confirmationworkflow;

/**
 * Utility class to check bookingextension subplugin confirmation capability.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      2025 Mahdi Poustini
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirmation {
    /**
     * Checks all bookingextension subplugins for confirm capability.
     *
     * @param int $optionid ID of the booking option.
     * @param int $approverid ID of the user trying to confirm.
     * @param int $userid ID of the user being confirmed.
     * @return array [$allowed (bool), $message (string), $reload (bool)]
     */
    public static function check_confirm_capability(int $optionid, int $approverid, int $userid): array {
        global $USER;

        $allowedtoconfirm = false;
        $returnmessage = get_string('notallowedtoconfirm', 'mod_booking');
        $reload = false;

        foreach (\core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classname = "\\bookingextension_{$plugin->name}\\local\\confirmbooking";

            if (class_exists($classname)) {
                // Skip if subplugin is disabled.
                if (!get_config('bookingextension_' . $plugin->name, $plugin->name . '_enabled')) {
                    continue;
                }

                [$allowed, $message, $reloadflag] =
                    $classname::has_capability_to_confirm_booking($optionid, $approverid, $userid);

                if ($allowed) {
                    return [true, '', false]; // Short-circuit on first positive.
                } else {
                    $returnmessage = $message;
                    $reload = $reloadflag ?? false;
                }
            }
        }

        return [$allowedtoconfirm, $returnmessage, $reload];
    }
}
