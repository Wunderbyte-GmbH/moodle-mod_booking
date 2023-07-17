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
use mod_booking\bo_actions\booking_action;

defined('MOODLE_INTERNAL') || die();

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

        $mform->addElement('select', 'boactionselectuserprofile', get_string('userprofilefield', 'mod_booking'),
            $userprofilefieldsarray);

        $operatorarray = [
            'set' => get_string('set', 'mod_booking'),
            'add' => get_string('add'),
            'substract' => get_string('substract', 'mod_booking'),
        ];

        $mform->addElement('select', 'boactionuserprofileoperator', get_string('operator', 'mod_booking'),
            $operatorarray);

        $mform->addElement('text', '', get_string('boactionuserprofilevalue', 'mod_booking'), 'z');

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