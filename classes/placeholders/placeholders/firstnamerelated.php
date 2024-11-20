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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class firstnamerelated {
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
     * @param string $rulejson = ''
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
        $value = '';
        $rulejson = json_decode($rulejson);
        $classname = substr(strrchr(get_called_class(), '\\'), 1);
        if (
            !empty($rulejson)
            && !empty($rulejson->datafromevent)
        ) {
            $class = $rulejson->datafromevent->eventname;
            $event = $class::restore((array)$rulejson->datafromevent, []);
            $eventdata = (object)$event->get_data();

            if (!empty($eventdata->relateduserid)) {
                $userid = $eventdata->relateduserid;
                // The cachekey depends on the kind of placeholder and it's ttl.
                // If it's the same for all users, we don't use userid.
                // If it's the same for all options of a cmid, we don't use optionid.
                $cachekey = "$classname-$userid";
                if (isset(placeholders_info::$placeholders[$cachekey])) {
                    return placeholders_info::$placeholders[$cachekey];
                }

                $user = singleton_service::get_instance_of_user($userid);
                $value = $user->firstname;

                // Save the value to profit from singleton.
                placeholders_info::$placeholders[$cachekey] = $value;
            }
        } else {
            $value = get_string('sthwentwrongwithplaceholder', 'mod_booking', $classname);
        }

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
