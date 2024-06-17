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

use mod_booking\output\optiondates_only;
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
class dates {

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
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE) {

        global $PAGE;

        $classname = substr(strrchr(get_called_class(), '\\'), 1);

        if (!empty($userid)) {

            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

            if (empty($cmid)) {
                $cmid = $settings->cmid;
            }

            // The cachekey depends on the kind of placeholder and it's ttl.
            // If it's the same for all users, we don't use userid.
            // If it's the same for all options of a cmid, we don't use optionid.
            $currlang = current_language();
            $cachekey = "$classname-$currlang-$optionid-$userid";
            if (isset(placeholders_info::$placeholders[$cachekey])) {
                return placeholders_info::$placeholders[$cachekey];
            }
            /** @var renderer $output*/
            $output = $PAGE->get_renderer('mod_booking');

            // Render optiontimes using a template.
            $data = new optiondates_only($settings);
            $value = $output->render_optiondates_only($data);

            // Save the value to profit from singleton.
            placeholders_info::$placeholders[$cachekey] = $value;

        } else {
            $value = get_string('sthwentwrongwithplaceholder', 'mod_booking', $classname);
        }

        return $value;
    }
}
