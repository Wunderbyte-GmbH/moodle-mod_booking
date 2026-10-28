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

use core_plugin_manager;
use mod_booking\local\interfaces\bookingextension\bookingrestriction_interface;

/**
 * Asks the booking extensions whether a user may book an option.
 *
 * An extension takes part with the class \bookingextension_<name>\local\bookingrestriction, which implements
 * bookingrestriction_interface. Only enabled extensions are asked. The first one that says no gives the reason.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingrestriction {
    /** @var array Reasons per option and user, for this request. */
    private static array $reasons = [];

    /**
     * Why the user may not book the option, or null if no extension stops it.
     *
     * @param int $optionid
     * @param int $userid
     * @return string|null
     */
    public static function get_reason(int $optionid, int $userid): ?string {
        $cachekey = $optionid . '_' . $userid;
        if (array_key_exists($cachekey, self::$reasons)) {
            return self::$reasons[$cachekey];
        }

        $reason = null;
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classname = "\\bookingextension_{$plugin->name}\\local\\bookingrestriction";
            if (!class_exists($classname) || !is_subclass_of($classname, bookingrestriction_interface::class)) {
                continue;
            }
            // Skip if the extension is switched off.
            if (!get_config('bookingextension_' . $plugin->name, str_replace('_', '', $plugin->name) . 'enabled')) {
                continue;
            }
            $reason = $classname::get_booking_restriction($optionid, $userid);
            if ($reason !== null) {
                break;
            }
        }

        self::$reasons[$cachekey] = $reason;
        return $reason;
    }

    /**
     * Forgets the answers of this request.
     *
     * @return void
     */
    public static function reset_static_cache(): void {
        self::$reasons = [];
    }
}
