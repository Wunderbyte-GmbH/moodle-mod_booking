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
class userprofilefield implements booking_action {

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

    /**
     * This actually only translates the action values and stores them in the json property of the data object.
     * @param stdClass &$data form data reference
     */
    public static function save_action(stdClass &$data) {

        $settings = singleton_service::get_instance_of_booking_option_settings($data->optionid);

        $optionvalues = $settings->return_settings_as_stdclass();

        $optionvalues->optionid = $optionvalues->id;

        $optionvalues->json = 'xx';

        $context = context_module::instance($data->cmid);

        booking_update_options($optionvalues, $context, UPDATE_OPTIONS_PARAM_REDUCED);

    }

    /**
     * Returns description
     * @return string
     * @throws coding_exception
     */
    public static function get_name_of_action() {

        $classname = get_called_class();

        // We only want the last part of the classname.
        $array = explode('\\', $classname);

        $classname = array_pop($array);
        return get_string($classname, 'mod_booking');
    }
}