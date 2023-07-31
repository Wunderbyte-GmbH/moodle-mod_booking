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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_actions;

use mod_booking\booking_option_settings;
use MoodleQuickForm;
use stdClass;

/**
 * Base class for a single booking action.
 *
 * All booking actions must extend this interface.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface booking_action {

    /**
     * Adds the form elements for this action to the provided mform.
     * @param MoodleQuickForm $mform the mform where the action should be added
     * @param formdata
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, array &$formdata);

    /**
     * Gets the human-readable name of a action (localized).
     * @param boolean $localized
     * @return string the name of the action
     */
    public function get_name_of_action($localized = true):string;

    /**
     * Gets the JSON for the actions to be stored in DB.
     * @param stdClass &$data form data reference
     */
    public function save_action(stdClass &$data);

    /**
     * Sets the action defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_actions
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Load json data form DB into the object.
     * @param stdClass $record a action record from DB
     */
    public function set_actiondata(stdClass $record);

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking action
     */
    public function set_actiondata_from_json(string $json);

    /**
     * Return interface for this action type as an array of data & template.
     * @param booking_option_settings $settings
     * @return array
     */
    public function return_interface(booking_option_settings $settings):array;

    /**
     * The price might be altered, eg. when more than one item is selected.
     *
     * @param object $user
     * @param object $user
     * @return array
     */
    public function return_price($user):array;

    /**
     * Function to return all relevant information of this action as array.
     * This function can be used to differentiate for different items a single ...
     * ... action option can provide. One example would be a timeslot action...
     * ... where itemids would be slotids.
     * But normally the itemid here is the same as the subboooking it.
     *
     * @param integer $itemid
     *
     * @return array
     */
    public function return_action_information(int $itemid = 0, $user = null):array;

    /**
     * When a action is booked, we might need some supplementary values saved.
     * Evey action type can decide what to store in the answer json.
     *
     * @param integer $itemid
     * @param object $user
     * @return string
     */
    public function return_answer_json(int $itemid, $user = null):string;
}
