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
 * Booking campaign interface.
 * All booking campaign types must extend this interface.
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\booking_campaigns;

use MoodleQuickForm;
use stdClass;

/**
 * Booking campaign interface.
 * All booking campaign types must extend this interface.
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface booking_campaign {

    /**
     * Adds the form elements for this campaign to the provided mform.
     * @param MoodleQuickForm $mform the mform where the campaign should be added
     * @return void
     */
    public function add_campaign_to_mform(MoodleQuickForm &$mform);

    /**
     * Gets the human-readable name of a campaign type (localized).
     * @param bool $localized
     * @return string the name of the campaign
     */
    public function get_name_of_campaign_type(bool $localized = true):string;

    /**
     * Save the campaign.
     * @param stdClass &$data form data reference
     */
    public function save_campaign(stdClass &$data);

    /**
     * Sets the campaign defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_campaigns
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Load json data form DB into the object.
     * @param stdClass $record a campaign record from DB
     */
    public function set_campaigndata(stdClass $record);

    /**
     * Function to check if a campaign is currently active
     * for a specific booking option.
     * @param int $optionid
     * @return bool true if the campaign is currently active
     */
    public function campaign_is_active(int $optionid):bool;

    /**
     * Function to apply the campaign price factor.
     * @param float $price the original price
     * @return float the new price
     */
    public function get_campaign_price(float $price):float;

    /**
     * Function to apply the booking limit factor.
     * @param int $limit the original booking limit
     * @return int the new booking limit
     */
    public function get_campaign_limit(int $limit):int;
}
