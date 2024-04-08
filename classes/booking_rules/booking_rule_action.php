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
 * Base class for a single booking option availability action.
 *
 * All bo action types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules;

use mod_booking\booking_option_settings;
use MoodleQuickForm;
use stdClass;

/**
 * Base class for a single booking rule action.
 *
 * All booking rule actions must extend this interface.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface booking_rule_action {

    /**
     * Adds the form elements for this rule action to the provided mform.
     * @param MoodleQuickForm $mform the mform where the rule action should be added
     * @param array $repeateloptions options for repeated elements
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, array &$repeateloptions);

    /**
     * Gets the human-readable name of a rule action (localized).
     * @param bool $localized
     * @return string the name of the rule action
     */
    public function get_name_of_action($localized = true);

    /**
     * Is the booking rule action compatible with the current form data?
     * @param array $ajaxformdata the ajax form data entered by the user
     * @return bool true if compatible, else false
     */
    public function is_compatible_with_ajaxformdata(array $ajaxformdata = []);

    /**
     * Gets the JSON for the rule action to be stored in DB.
     * @param stdClass $data form data reference
     * @return string the json for the rule action
     */
    public function save_action(stdClass &$data);

    /**
     * Sets the rule action defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Load json data form DB into the object.
     * @param stdClass $record a rule action record from DB
     */
    public function set_actiondata(stdClass $record);

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule action
     */
    public function set_actiondata_from_json(string $json);

    /**
     * Execute the rule action.
     * @param stdClass $record
     */
    public function execute(stdClass $record);

}
