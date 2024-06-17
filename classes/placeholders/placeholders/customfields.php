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
class customfields {

    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param string $text
     * @param array $params
     * @param string $placeholder
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        string &$text = '',
        array &$params = [],
        string $placeholder = '') {

        global $CFG;

        // We might have a param which is part of booking customfields fields.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $value = '';

        if (isset($settings->customfields[$placeholder])
            && is_string($settings->customfields[$placeholder])) {
            $value = $settings->customfields[$placeholder];

            $searchstring = '{' . $placeholder . '}';
            $text = str_replace($searchstring, $value, $text);
        } else {
            $user = singleton_service::get_instance_of_user($userid);
            if (empty($user->profile)) {

                require_once("$CFG->dirroot/user/profile/lib.php");
                profile_load_data($user);

                foreach ($user as $userkey => $uservalue) {
                    if (substr($userkey, 0, 14) == "profile_field_") {
                        $profilefieldkey = str_replace('profile_field_', '', $userkey);
                        $user->profile[$profilefieldkey] = $uservalue;
                    }
                }
            }
            if (isset($user->profile[$placeholder])) {
                $value = $user->profile[$placeholder];
            }
        }

        return $value;
    }

    /**
     * This function returns the keys and values of the localized placeholders.
     * @return string
     */
    public static function return_placeholder_text() {

        return get_string('customfieldsplaceholdertext', 'mod_booking');
    }
}
