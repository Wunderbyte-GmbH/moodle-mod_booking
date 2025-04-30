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

use dml_exception;
use Exception;
use mod_booking\booking;
use mod_booking\singleton_service;

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

    /**
     * Matching the params with given fields and set as userprefs.
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
            set_user_preference($this->key, $this->value, $userid);
            return true;
        }

        // 2. Check custom user profile fields.
        $field = $DB->get_record('user_info_field', ['shortname' => $this->key], '*', IGNORE_MISSING);
        if ($field) {
            set_user_preference($this->key, $this->value, $userid);
            return true;
        }
        return false;
    }

    /**
     * Check if the password corresponds to the password defined in settings of booking instance.
     *
     * @param int $cmid
     * @param string $pwd
     *
     * @return bool
     *
     */
    public function password_is_valid(
        int $cmid,
        string $pwd = '',
    ): bool {
        $this->password = $pwd;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

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
}