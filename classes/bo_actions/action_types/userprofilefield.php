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
 * Already booked condition (item has been booked).
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_actions\action_types;

use coding_exception;
use context_module;
use core_reportbuilder\local\helpers\user_profile_fields;
use mod_booking\bo_actions\booking_action;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userprofilefield extends booking_action {

    /**
     * Apply action.
     * @param stdClass $actiondata
     * @param ?int $userid
     * @return int // Status. 0 is do nothing, 1 aborts after application right away.
     */
    public function apply_action(stdClass $actiondata, int $userid = 0) {

        global $USER, $CFG;

        if (!empty($userid)) {
            $user = singleton_service::get_instance_of_user($userid);
        } else {
            $user = $USER;
        }

        require_once("$CFG->dirroot/user/profile/lib.php");

        profile_load_data($user);

        // There are two ways to acces the profile fields, we have to support both.
        $key = "profile_field_" . $actiondata->boactionselectuserprofilefield;
        if (isset($user->profile[$actiondata->boactionselectuserprofilefield])
            || isset($user->{$key})) {

            switch ($actiondata->boactionuserprofileoperator) {
                case 'set':
                    $user->{$key} = $actiondata->boactionuserprofilefieldvalue;
                    break;
                case 'add':

                    $number = intval($user->profile[$actiondata->boactionselectuserprofilefield]);
                    $user->{$key} =
                            $number + $actiondata->boactionuserprofilefieldvalue;
                    break;
                case 'subtract':
                    $number = intval($user->profile[$actiondata->boactionselectuserprofilefield]);

                    $user->{$key} =
                            $number - $actiondata->boactionuserprofilefieldvalue;
                    break;
                case 'adddate':

                    // First we check if the user has already a value in the field.

                    if (empty($user->{$key})) {

                        $settings = singleton_service::get_instance_of_booking_option_settings($actiondata->optionid);
                        $startdate = !empty($settings->coursestarttime) ? $settings->coursestarttime : null;
                    } else {
                        list($startstring, $endstring) = explode(' - ', $user->{$key});

                        $startdate = strtotime($endstring, time()) ?? null;
                    }

                    $enddate = strtotime($actiondata->boactionuserprofilefieldvalue, $startdate);

                    if (!empty($startstring)) {
                        $startdate = strtotime($startstring, time());
                    } else if (empty($startdate)) {
                        $startdate = time();
                    }

                    $user->{$key} = userdate($startdate) . " - " . userdate($enddate);
                    break;
            }
            profile_save_data($user);
        }

        return 0; // We will allow all other after actions like events.
    }

    /**
     * Add action to mform
     *
     * @param mixed $mform
     *
     * @return void
     *
     */
    public static function add_action_to_mform(&$mform) {

        // Choose the user profile field which is used to store each user's price category.
        $userprofilefields = profile_get_custom_fields();
        if (!empty($userprofilefields)) {
            $userprofilefieldsarray = [];

            $userprofilefieldsarray[0] = get_string('noselection', 'mod_booking');

            // Create an array of key => value pairs for the dropdown.
            foreach ($userprofilefields as $userprofilefield) {
                $userprofilefieldsarray[$userprofilefield->shortname] = $userprofilefield->name;
            }
        }

        $mform->addElement('text', 'boactionname', get_string('boactionname', 'mod_booking'));

        $mform->addElement('select', 'boactionselectuserprofilefield', get_string('userprofilefield', 'mod_booking'),
            $userprofilefieldsarray);

        $operatorarray = [
            'set' => get_string('actionoperator:set', 'mod_booking'),
            'add' => get_string('add'),
            'subtract' => get_string('actionoperator:subtract', 'mod_booking'),
            'adddate' => get_string('actionoperator:adddate', 'mod_booking'),
        ];

        $mform->addElement('select', 'boactionuserprofileoperator', get_string('actionoperator', 'mod_booking'),
            $operatorarray);

        $mform->addElement('text', 'boactionuserprofilefieldvalue', get_string('boactionuserprofilefieldvalue', 'mod_booking'));

    }
}
