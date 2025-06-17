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

namespace mod_booking\local;

use mod_booking\booking;
use mod_booking\singleton_service;
use moodle_url;

/**
 * Manage coursecategories in berta.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_user_field {
    /** @var string $key the shortname of a (custom) user profile field */
    public $key;

    /** @var string $value the value the field should be mocked to */
    public $value;

    /** @var string $password a password if given */
    protected $password;

    /** @var int $cmid a password if given */
    protected $cmid;

    /**
     * Constructor of override user field specific class for cmid.
     *
     * @param int $cmid
     *
     */
    public function __construct(int $cmid) {
        $this->cmid = $cmid;
    }
    /**
     * Matching the params with given fields and set as userprefs.
     * The entry is set like value_cmid:XY.
     *
     * @param string $param
     * @param int $userid
     *
     * @return bool
     *
     */
    public function set_userprefs(
        string $param,
        int $userid = 0
    ): bool {
        if (empty($param)) {
            return false;
        }
        // Validate format: must be something like "fieldname_value".
        if (!preg_match('/^([a-zA-Z0-9_]+)_([a-zA-Z0-9_]+)$/', $param, $matches)) {
            return false;
        }
        $this->key = $matches[1];
        $this->value = $matches[2];

        // Check if key is a standard or custom profile field.
        global $DB, $USER;

        if (empty($userid)) {
            $userid = $USER->id;
        }
        // 1. Check standard user fields.
        $userprofilefields = $DB->get_columns('user', true);
        if (in_array($this->key, array_keys($userprofilefields))) {
            set_user_preference($this->key, $this->value . ":::$this->cmid", $userid);
            return true;
        }

        // 2. Check custom user profile fields.
        $field = $DB->get_record('user_info_field', ['shortname' => $this->key], '*', IGNORE_MISSING);
        if ($field) {
            set_user_preference($this->key, $this->value . ":::$this->cmid", $userid);
            return true;
        }
        return false;
    }

    /**
     * Check if the password corresponds to the password defined in settings of booking instance.
     *
     * @param string $pwd
     *
     * @return bool
     *
     */
    public function password_is_valid(
        string $pwd = ''
    ): bool {
        $this->password = $pwd;
        $booking = singleton_service::get_instance_of_booking_by_cmid($this->cmid);

        $cvdata = booking::get_value_of_json_by_key($booking->id, 'circumventcond');
        if (!isset($cvdata->cvpwd)) {
            return false; // If key is not set at all block.
        }
        if (
            empty($cvdata->cvpwd) // Password empty means no limit.
            || (
                !empty($cvdata->cvpwd)
                && $cvdata->cvpwd === $pwd
            ) // If password is set, we need a match.
        ) {
            return true;
        }
        return false;
    }

    /**
     * Check if the user preference is set and verify if it's for the right (current) cmid.
     * If not, return empty string.
     *
     * @param string $profilefield
     * @param int $userid
     *
     * @return string
     *
     */
    public function get_value_for_user(string $profilefield, int $userid): string {
        $pref = get_user_preferences($profilefield, null, $userid);
        if (empty($pref)) {
            // No preference set.
            return "";
        }
        $array = explode(':::', $pref);
        if (!isset($array[1])) {
            return "";
        }
        [$fieldvalue, $cmid] = $array;
        if ($cmid != $this->cmid) {
            // Not the right cmid.
            return "";
        }
        return $fieldvalue;
    }

    /**
     * Get link to circumvent user profile field. Empty if not enabled in booking settings or data not consistent.
     *
     * @param int $optionid
     *
     * @return string
     *
     */
    public function get_circumvent_link(int $optionid): string {

        $booking = singleton_service::get_instance_of_booking_by_cmid($this->cmid);
        $cvdata = booking::get_value_of_json_by_key($booking->id, 'circumventcond');
        if (empty($cvdata)) {
            return "";
        }

        $bosettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($bosettings) || empty($bosettings->availability) || $bosettings->availability === "[]") {
            return "";
        }
        $availabilities = json_decode($bosettings->availability);
        foreach ($availabilities as $a) {
            if (
                (
                    $a->name === "userprofilefield_1_default"
                    || $a->name === "userprofilefield_2_custom"
                )
                && ( // For the moment, we only support conditions using positive string comparison: equals, contains.
                    $a->operator == "="
                    || $a->operator == "~"
                )
                && isset($a->value)
                && isset($a->profilefield)
            ) {
                $key = $a->profilefield . "_" . $a->value;
                break;
            }
        }
        if (!isset($key)) {
            return "";
        }

        $params = [
            "optionid" => (int)$optionid,
            "cmid" => (int)$this->cmid,
            'cvfield' => $key,
        ];
        if (isset($cvdata->cvpwd) && !empty($cvdata->cvpwd)) {
            $params['cvpwd'] = $cvdata->cvpwd;
        }
        $url = new moodle_url("/mod/booking/optionview.php", $params);

        return $url->out(false);
    }
}
