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
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

use mod_booking\placeholders\placeholders_info;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 * Returns a link to a course the bookingoption is related to.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrollink {

    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $installmentnr
     * @param int $duedate
     * @param float $price
     * @param string $text
     * @param array $params
     * @param int $descriptionparam
     * @param string $rulejson
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        string &$text = '',
        array &$params = [],
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
        string $rulejson = ''
        ) {

        $value = "";
        $classname = substr(strrchr(get_called_class(), '\\'), 1);

        // The cachekey depends on the kind of placeholder and it's ttl.
        // If it's the same for all users, we don't use userid.
        // If it's the same for all options of a cmid, we don't use optionid.
        $rulejson = json_decode($rulejson);
        if (empty($rulejson) || empty($rulejson->datafromevent)) {
            return $value;
        }

        $class = $rulejson->datafromevent->eventname;
        $event = $rulejson->datafromevent;
        if ($class != '\mod_booking\event\enrollink_triggered') {
            return $value;
        }

        if (
            isset($event->other) &&
            isset($event->other->erlid)
        ) {
            // Hashed ID of enrollink.
            $erlid = $event->other->erlid;
        } else {
            return $value;
        }

        // TODO: Check caching!
        $bid = $event->other->bundleid;
        $cachekey = "$classname-$bid";
        if (isset(placeholders_info::$placeholders[$cachekey])) {
            return placeholders_info::$placeholders[$cachekey];
        }

        $value = \mod_booking\enrollink::create_enrollink($erlid);

        // Save the value to profit from singleton.
        placeholders_info::$placeholders[$cachekey] = $value;

        return $value;
    }

    /**
     * Function determine if placeholder class should be called at all.
     *
     * @return bool
     *
     */
    public static function is_applicable(): bool {
        return true;
    }
}
