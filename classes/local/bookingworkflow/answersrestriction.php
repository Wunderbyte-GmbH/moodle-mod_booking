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
use mod_booking\booking_answers\scope_base;

/**
 * Class answersrestriction
 *
 * Collects the restrictions of all booking extensions which limit the booking answers
 * the current user may see (e.g. a supervisor who may only see their own team).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answersrestriction {
    /**
     * Static request cache, the restriction is resolved once per user, scope and scopeid.
     * @var array
     */
    private static $restrictions = [];

    /**
     * Returns the ids of the users whose booking answers the current user may see.
     *
     * Null means that no extension restricts the current user, so all answers of the
     * scope stay visible. An empty array means that the user is restricted, but there is
     * nobody left to show.
     *
     * If more than one extension restricts, every restriction narrows the result further.
     *
     * @param scope_base $scopeclass
     * @param int $scopeid
     * @return int[]|null
     */
    public static function get_visible_user_ids(scope_base $scopeclass, int $scopeid): ?array {
        global $USER;

        $cachekey = $USER->id . '_' . $scopeclass->return_classname() . '_' . $scopeid;
        if (array_key_exists($cachekey, self::$restrictions)) {
            return self::$restrictions[$cachekey];
        }

        $userids = null;

        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classname = "\\bookingextension_{$plugin->name}\\local\\answersrestriction";

            if (!class_exists($classname)) {
                continue;
            }

            // Skip if subplugin is disabled.
            if (!get_config('bookingextension_' . $plugin->name, str_replace('_', '', $plugin->name) . 'enabled')) {
                continue;
            }

            $restriction = $classname::restrict_to_user_ids($scopeclass, $scopeid);
            if ($restriction === null) {
                // This extension does not restrict the current user.
                continue;
            }

            // Every restricting extension can only narrow down what is left.
            $userids = $userids === null ? $restriction : array_intersect($userids, $restriction);
        }

        if ($userids !== null) {
            $userids = array_values(array_unique(array_map('intval', $userids)));
        }

        self::$restrictions[$cachekey] = $userids;

        return $userids;
    }

    /**
     * Resets the static request cache. Needed in tests where settings or the user change.
     *
     * @return void
     */
    public static function reset_static_cache(): void {
        self::$restrictions = [];
    }
}
