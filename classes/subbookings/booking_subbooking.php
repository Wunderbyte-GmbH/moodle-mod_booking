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

namespace mod_booking\subbookings;

use mod_booking\booking_option_settings;
use MoodleQuickForm;
use stdClass;

/**
 * Base class for a single booking subbooking.
 *
 * All booking subbookings must extend this interface.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface booking_subbooking {

    /**
     * Adds the form elements for this subbooking to the provided mform.
     * @param MoodleQuickForm $mform the mform where the subbooking should be added
     * @param formdata
     * @return void
     */
    public function add_subbooking_to_mform(MoodleQuickForm &$mform, array &$formdata);

    /**
     * Gets the human-readable name of a subbooking (localized).
     * @param boolean $localized
     * @return string the name of the subbooking
     */
    public function get_name_of_subbooking($localized = true);

    /**
     * Gets the JSON for the subbookings to be stored in DB.
     * @param stdClass &$data form data reference
     * @return string the json for the subbooking
     */
    public function save_subbooking(stdClass &$data);

    /**
     * Sets the subbooking defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_subbookings
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Load json data form DB into the object.
     * @param stdClass $record a subbooking record from DB
     */
    public function set_subbookingdata(stdClass $record);

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking subbooking
     */
    public function set_subbookingdata_from_json(string $json);
}
