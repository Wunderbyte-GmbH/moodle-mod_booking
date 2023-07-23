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

        if (isset($user->profile[$actiondata->boactionselectuserprofile])) {

            switch ($actiondata->boactionuserprofileoperator) {
                case 'set':
                    $user->profile[$actiondata->boactionselectuserprofile] = $actiondata->boactionuserprofilevalue;
                    break;
                case 'add':

                    $number = intval($user->profile[$actiondata->boactionselectuserprofile]);
                    $key = "profile_field_$actiondata->boactionselectuserprofile";
                    $user->{$key} =
                            $number + $actiondata->boactionuserprofilevalue;
                    break;
                case 'substract':
                    $number = intval($user->profile[$actiondata->boactionselectuserprofile]);

                    $key = "profile_field_$actiondata->boactionselectuserprofile";
                    $user->{$key} =
                            $number - $actiondata->boactionuserprofilevalue;
                    break;
            }
        }

        profile_save_data($user);

        return 0; // We will allow all other after actions like events.
    }

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

        $mform->addElement('select', 'boactionselectuserprofile', get_string('userprofilefield', 'mod_booking'),
            $userprofilefieldsarray);

        $operatorarray = [
            'set' => get_string('actionoperator:set', 'mod_booking'),
            'add' => get_string('add'),
            'substract' => get_string('actionoperator:substract', 'mod_booking'),
        ];

        $mform->addElement('select', 'boactionuserprofileoperator', get_string('actionoperator', 'mod_booking'),
            $operatorarray);

        $mform->addElement('text', 'boactionuserprofilevalue', get_string('boactionuserprofilevalue', 'mod_booking'));

    }
}