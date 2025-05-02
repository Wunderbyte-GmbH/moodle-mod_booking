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

namespace mod_booking\booking_rules;

use mod_booking\booking_option_settings;
use MoodleQuickForm;
use stdClass;

/**
 * Base class for a single booking rule condition.
 *
 * All booking rule conditions must extend this interface.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface booking_rule_condition {
    /**
     * Function to tell if a condition can be combined with a certain booking rule type.
     * @param string $bookingruletype e.g. "rule_daysbefore" or "rule_react_on_event"
     * @return bool true if it can be combined
     */
    public function can_be_combined_with_bookingruletype(string $bookingruletype): bool;

    /**
     * Adds the form elements for this rule condition to the provided mform.
     * @param MoodleQuickForm $mform the mform where the rule condition should be added
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null);

    /**
     * Gets the human-readable name of a rule condition (localized).
     * @param bool $localized
     * @return string the name of the rule condition
     */
    public function get_name_of_condition($localized = true);

    /**
     * Gets the JSON for the rule condition to be stored in DB.
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data): void;

    /**
     * Sets the rule condition defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Load json data form DB into the object.
     * @param stdClass $record a rule condition record from DB
     */
    public function set_conditiondata(stdClass $record);

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule condition
     */
    public function set_conditiondata_from_json(string $json);

    /**
     * Execute the rule condition.
     * @param stdClass $sql
     * @param array $params
     */
    public function execute(stdClass &$sql, array &$params): void;
}
