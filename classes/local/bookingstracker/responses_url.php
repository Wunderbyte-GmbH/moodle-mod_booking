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
 * Where the links to the booked users of a booking option lead: Bookings tracker or "Manage responses".
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bookingstracker;

use cache_helper;
use context_system;
use moodle_url;

/**
 * Where the links to the booked users of a booking option lead: Bookings tracker or "Manage responses".
 *
 * The site setting booking/bookingstrackerdefault decides whether the links lead to the Bookings tracker
 * (report2.php) or to the old page "Manage responses" (report.php). New installations use the Bookings
 * tracker; on upgrade, sites that had activated the Bookings tracker keep using it
 * (Wunderbyte-GmbH/Wunderbyte-GmbH#2332).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responses_url {
    /** @var string Name of the site setting (plugin booking). */
    public const SETTING = 'bookingstrackerdefault';

    /**
     * Whether the links lead to the Bookings tracker.
     *
     * @return bool
     */
    public static function uses_bookingstracker(): bool {
        return !empty(get_config('booking', self::SETTING));
    }

    /**
     * Link to the booked users of a booking option.
     *
     * @param int $cmid course module id of the booking instance
     * @param int $optionid
     * @return moodle_url
     */
    public static function for_option(int $cmid, int $optionid): moodle_url {
        if (self::uses_bookingstracker()) {
            return new moodle_url('/mod/booking/report2.php', ['cmid' => $cmid, 'optionid' => $optionid]);
        }
        return new moodle_url('/mod/booking/report.php', ['id' => $cmid, 'optionid' => $optionid]);
    }

    /**
     * Link to the booked users of one date (session) of a booking option.
     *
     * "Manage responses" has no view of a single date, so it shows the whole option. Without the option id
     * (log entries of dates) the link keeps its previous form.
     *
     * @param int $cmid course module id of the booking instance
     * @param int $optionid 0 if unknown
     * @param int $optiondateid
     * @return moodle_url
     */
    public static function for_optiondate(int $cmid, int $optionid, int $optiondateid): moodle_url {
        if (self::uses_bookingstracker()) {
            $params = empty($optionid) ? [] : ['optionid' => $optionid];
            return new moodle_url('/mod/booking/report2.php', $params + ['optiondateid' => $optiondateid]);
        }
        $params = empty($optionid) ? ['optiondateid' => $optiondateid] : ['optionid' => $optionid];
        return new moodle_url('/mod/booking/report.php', ['id' => $cmid] + $params);
    }

    /**
     * Value of the setting for a site that upgrades from a version without it.
     *
     * The Bookings tracker used to be activated with booking/bookingstracker. Upgrade step 2026072200 removed that
     * value from the config, but the admin settings log (config_log) still holds its last saved value. A site
     * that had activated the Bookings tracker keeps using it, every other site keeps "Manage responses".
     *
     * @return int 1 = Bookings tracker, 0 = "Manage responses"
     */
    public static function default_for_upgrade(): int {
        global $DB;

        $current = get_config('booking', 'bookingstracker');
        if ($current !== false) {
            return empty($current) ? 0 : 1;
        }
        $logged = $DB->get_records(
            'config_log',
            ['plugin' => 'booking', 'name' => 'bookingstracker'],
            'timemodified DESC, id DESC',
            'id, value',
            0,
            1
        );
        $last = reset($logged);
        return ($last && !empty($last->value)) ? 1 : 0;
    }

    /**
     * Switches the whole site to the Bookings tracker (button on "Manage responses").
     *
     * Requires the site configuration capability. The change is logged like a change in the site administration,
     * where it can be undone.
     *
     * @return void
     */
    public static function switch_site_to_bookingstracker(): void {
        require_capability('moodle/site:config', context_system::instance());

        $oldvalue = get_config('booking', self::SETTING);
        set_config(self::SETTING, 1, 'booking');
        add_to_config_log(self::SETTING, $oldvalue === false ? null : $oldvalue, 1, 'booking');
        self::purge_caches();
    }

    /**
     * Drops the caches that hold links to the booked users (option settings and rendered tables).
     *
     * @return void
     */
    public static function purge_caches(): void {
        cache_helper::purge_by_event('setbackoptionsettings');
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackencodedtables');
        cache_helper::purge_by_event('changesinwunderbytetable');
    }
}
