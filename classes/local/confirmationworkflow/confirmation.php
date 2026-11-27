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

use context;
use context_module;
use context_system;
use mod_booking\singleton_service;

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
     * Whether the current user may confirm (or deny) the answers of a booking option regardless of
     * any confirmation workflow.
     *
     * The capability mod/booking:alwayscanapprove is checked in the module context of the option,
     * so a role assigned on a course category or a course is sufficient for the options below it.
     * Role assignments on system level are inherited by the module context and work as before.
     *
     * @param int $optionid ID of the booking option, 0 when there is no specific option.
     * @return bool
     */
    public static function can_always_approve(int $optionid = 0): bool {
        return has_capability('mod/booking:alwayscanapprove', self::get_context_for_option($optionid));
    }

    /**
     * Returns the module context of a booking option.
     *
     * Falls back to the system context when no context can be determined, e.g. without an option
     * (shortcodes listing answers of many options) or when the course module is missing.
     *
     * @param int $optionid ID of the booking option, 0 when there is no specific option.
     * @return context
     */
    public static function get_context_for_option(int $optionid = 0): context {
        if (empty($optionid)) {
            return context_system::instance();
        }

        try {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            if (!empty($settings->cmid)) {
                $context = context_module::instance((int) $settings->cmid, IGNORE_MISSING);
                if (!empty($context)) {
                    return $context;
                }
            }
        } catch (\Throwable $e) {
            // An option which can not be loaded has no context: the system context is the strictest check.
            return context_system::instance();
        }

        return context_system::instance();
    }

    /**
     * Checks all bookingextension subplugins for confirm capability.
     *
     * @param int $optionid ID of the booking option.
     * @param int $approverid ID of the user trying to confirm.
     * @param int $userid ID of the user being confirmed.
     * @return array [$allowed (bool), $message (string), $reload (bool), $approvedby (string)]
     *               $approvedby is the name of the subplugin whose workflow granted the
     *               confirmation (e.g. 'confirmation_trainer'), or '' when nobody approved.
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
                if (!get_config('bookingextension_' . $plugin->name, str_replace('_', '', $plugin->name) . 'enabled')) {
                    continue;
                }

                [$allowed, $message, $reloadflag] =
                    $classname::has_capability_to_confirm_booking($optionid, $approverid, $userid);

                if ($allowed) {
                    return [true, '', false, $plugin->name]; // Short-circuit on first positive.
                } else if (!empty($message)) {
                    // Plugins without jurisdiction over the option (or over this user) return
                    // an empty message; it must not overwrite the specific message of the
                    // responsible workflow.
                    $returnmessage = $message;
                    $reload = $reloadflag ?? false;
                }
            }
        }

        return [$allowedtoconfirm, $returnmessage, $reload, ''];
    }

    /**
     * Summary of get_required_confirmation_count
     * @param int $optionid
     * @return int
     */
    public static function get_required_confirmation_count(int $optionid): int {
        // Default value. Remains zero when no plugin is enabeld.
        $requiredconfirmationscount = 0;

        // Check each plugin to get required number of confirmation.
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classname = "\\bookingextension_{$plugin->name}\\local\\confirmbooking";

            if (class_exists($classname)) {
                // Skip if subplugin is disabled.
                if (!get_config('bookingextension_' . $plugin->name, str_replace('_', '', $plugin->name) . 'enabled')) {
                    continue;
                }

                // Skipt if desired function not exists.
                if (!method_exists($classname, 'get_required_confirmation_count')) {
                    continue;
                }

                // We always consider the max number of required conformation. It's required when multiple plugin are enabled.
                $newvalue = $classname::get_required_confirmation_count($optionid);
                if ($newvalue > $requiredconfirmationscount) {
                    $requiredconfirmationscount = $newvalue;
                }
            }
        }

        return $requiredconfirmationscount;
    }
}
