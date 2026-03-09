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
class customfields extends \mod_booking\placeholders\placeholder_base {
    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param string $text
     * @param array $params
     * @param string $placeholder
     * @param bool $fieldexists
     * @param string $rulejson = ''
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        string &$text = '',
        array &$params = [],
        string $placeholder = '',
        bool &$fieldexists = true,
        string $rulejson = ''
    ): string {

        global $CFG, $DB;

        // We might have a param which is part of booking customfields fields.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $value = '';
        $searchstring = '{' . $placeholder . '}';
        $classname = substr(strrchr(get_called_class(), '\\'), 1);

        if (
            isset($settings->customfieldsfortemplates[$placeholder]["value"])
        ) {
            // The cachekey depends on the kind of placeholder and it's ttl.
            // If it's the same for all users, we don't use userid.
            // If it's the same for all options of a cmid, we don't use optionid.
            $cachekey = "$classname-$optionid-$placeholder";
            if (isset(placeholders_info::$placeholders[$cachekey])) {
                $value = placeholders_info::$placeholders[$cachekey];
                // Replace the reference text and return the value.
                $text = str_replace($searchstring, $value, $text);
                return $value;
            }

            if (
                is_string($settings->customfieldsfortemplates[$placeholder]['value'])
                || is_numeric($settings->customfieldsfortemplates[$placeholder]['value'])
            ) {
                $value = $settings->customfieldsfortemplates[$placeholder]['value'];
            } else if (is_array($settings->customfieldsfortemplates[$placeholder]['value'])) {
                $value = implode(', ', $settings->customfieldsfortemplates[$placeholder]['value']);
            }
            // Replace the reference text.
            $text = str_replace($searchstring, $value, $text);

            // Save the value to profit from singleton.
            placeholders_info::$placeholders[$cachekey] = $value;
        } else {
            /* When the user profile field shorname ends on "-related" (e.g. "companyname-related")
            then we'll take the profile field of the related user instead of the active user. */
            if (str_contains($placeholder, '-related')) {
                // We now know that we have to look for the related user's profile fields.
                $placeholder = str_replace('-related', '', $placeholder);
                $rulejson = json_decode($rulejson);
                if (
                    !empty($rulejson)
                    && !empty($rulejson->datafromevent)
                ) {
                    $class = $rulejson->datafromevent->eventname;
                    $event = $class::restore((array)$rulejson->datafromevent, []);
                    $eventdata = (object)$event->get_data();

                    if (!empty($eventdata->relateduserid)) {
                        // Userid is set to the related user.
                        $userid = $eventdata->relateduserid;
                    }
                } else {
                    $value = get_string('sthwentwrongwithplaceholder', 'mod_booking', $classname);
                }
            }

            // The cachekey depends on the kind of placeholder and it's ttl.
            // If it's the same for all users, we don't use userid.
            // If it's the same for all options of a cmid, we don't use optionid.
            $cachekey = "$classname-$userid-$placeholder";
            if (isset(placeholders_info::$placeholders[$cachekey])) {
                $value = placeholders_info::$placeholders[$cachekey];
                // Replace the reference text and return the value.
                $text = str_replace($searchstring, $value, $text);
                return $value;
            }

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
                // This is where we set the value.
                $value = $user->profile[$placeholder];

                // Replace the reference text and return the value.
                $text = str_replace($searchstring, $value, $text);

                // Save the value to profit from singleton.
                placeholders_info::$placeholders[$cachekey] = $value;
            } else {
                $fieldexists = false;
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
