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

use html_writer;
use mod_booking\local\modechecker;
use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoptiondetaillink {
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
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE
    ) {
        global $PAGE;
        $classname = substr(strrchr(get_called_class(), '\\'), 1);

        if (!empty($optionid)) {
            // The cachekey depends on the kind of placeholder and it's ttl.
            // If it's the same for all users, we don't use userid.
            // If it's the same for all options of a cmid, we don't use optionid.
            $cachekey = "$classname-$optionid";
            if (isset(placeholders_info::$placeholders[$cachekey])) {
                return placeholders_info::$placeholders[$cachekey];
            }

            $timeformat = get_string('strftimedate', 'langconfig');

            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

            $value = '';

            if ($settings->cmid) {
                if (!modechecker::is_ajax_or_webservice_request()) {
                    $returnurl = $PAGE->url->out();
                } else {
                    $returnurl = '/';
                }

                // The current page is not /mod/booking/optionview.php.
                $bookingoptiondetaillink = new moodle_url("/mod/booking/optionview.php", [
                    "optionid" => (int)$settings->id,
                    "cmid" => (int)$cmid,
                    "userid" => (int)$userid,
                    'returnto' => 'url',
                    'returnurl' => $returnurl,
                ]);

                $value = html_writer::link($bookingoptiondetaillink, $bookingoptiondetaillink->out());
            }

             // Save the value to profit from singleton.
             placeholders_info::$placeholders[$cachekey] = $value;
        } else {
            $classname = substr(strrchr(get_called_class(), '\\'), 1);
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
