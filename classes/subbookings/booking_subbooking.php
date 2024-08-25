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
     * @param array $formdata
     * @return void
     */
    public function add_subbooking_to_mform(MoodleQuickForm &$mform, array &$formdata);

    /**
     * Gets the human-readable name of a subbooking (localized).
     * @param bool $localized
     * @return string the name of the subbooking
     */
    public function get_name_of_subbooking($localized = true): string;

    /**
     * Gets the JSON for the subbookings to be stored in DB.
     * @param stdClass $data form data reference
     */
    public function save_subbooking(stdClass &$data);

    /**
     * Sets the subbooking defaults when loading the form.
     * @param stdClass $data reference to the default values
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

    /**
     * Return interface for this subbooking type as an array of data & template.
     * @param booking_option_settings $settings
     * @param int $userid
     * @return array
     */
    public function return_interface(booking_option_settings $settings, int $userid): array;

    /**
     * The price might be altered, eg. when more than one item is selected.
     *
     * @param object $user
     * @return array
     */
    public function return_price($user): array;

    /**
     * Function to return all relevant information of this subbooking as array.
     * This function can be used to differentiate for different items a single ...
     * ... subbooking option can provide. One example would be a timeslot subbooking...
     * ... where itemids would be slotids.
     * But normally the itemid here is the same as the subboooking it.
     *
     * @param int $itemid
     * @param int $userid
     *
     * @return array
     */
    public function return_subbooking_information(int $itemid = 0, int $userid = 0): array;
    /**
     * When a subbooking is booked, we might need some supplementary values saved.
     * Evey subbooking type can decide what to store in the answer json.
     *
     * @param int $itemid
     * @param ?object $user
     * @return string
     */
    public function return_answer_json(int $itemid, ?object $user = null): string;

    /**
     * Is blocking. This depends on the settings and user.
     *
     * @param int $itemid
     * @param int $userid
     * @return bool
     */
    public function is_blocking(booking_option_settings $settings, int $userid = 0): bool;
}
