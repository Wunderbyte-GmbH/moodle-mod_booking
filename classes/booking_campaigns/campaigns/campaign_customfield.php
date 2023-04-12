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

namespace mod_booking\booking_campaigns\campaigns;

use mod_booking\booking_campaigns\booking_campaign;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Campaign for booking options having a certain booking option customfield.
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class campaign_customfield implements booking_campaign {

    /** @var int $id */
    public $id = 0;

    /** @var string $name can be any name set by the user */
    public $name = '';

    /** @var int $type */
    public $type = CAMPAIGN_TYPE_CUSTOMFIELD;

    /** @var string $classname */
    public $classname = 'campaign_customfield';

    /** @var int $starttime */
    public $starttime = 0;

    /** @var int $endtime */
    public $endtime = 0;

    /** @var float $pricefactor */
    public $pricefactor = 1.0;

    /** @var float $limitfactor */
    public $limitfactor = 1.0;

    // From JSON.
    /** @var string $fieldname */
    public $fieldname = '';

    /** @var string $fieldvalue */
    public $fieldvalue = '';

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a campaign record from DB
     */
    public function set_campaigndata(stdClass $record) {
        $this->id = $record->id ?? 0;
        $this->name = $record->name ?? '';
        $this->starttime = $record->starttime ?? 0;
        $this->endtime = $record->endtime ?? 0;
        $this->pricefactor = $record->pricefactor ?? 1.0;
        $this->limitfactor = $record->limitfactor ?? 1.0;

        // Set additional data stored in JSON.
        $jsonobj = json_decode($record->json);
        $this->fieldname = $jsonobj->fieldname;
        $this->fieldvalue = $jsonobj->fieldvalue;
    }

    /**
     * Add the campaign to the mform.
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_campaign_to_mform(MoodleQuickForm &$mform) {

        $mform->addElement('static', 'campaign_react_on_event_desc', '',
            get_string('campaign_react_on_event_desc', 'mod_booking'));

        // TODO...
    }

    /**
     * Get the name of the campaign type.
     * @param bool $localized
     * @return string
     */
    public function get_name_of_campaign_type(bool $localized = true): string {
        return $localized ? get_string($this->classname, 'mod_booking') : $this->classname;
    }

    /**
     * Save the campaign.
     * @param stdClass &$data form data reference
     */
    public function save_campaign(stdClass &$data) {
        global $DB;

        $record = new stdClass();

        if (!isset($data->json)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->json);
        }

        $jsonobject->fieldname = $data->fieldname;
        $jsonobject->fieldvalue = $data->fieldvalue;
        $record->json = json_encode($jsonobject);

        $record->name = $data->campaign_name;
        $record->starttime = $data->starttime;
        $record->endtime = $data->endtime;
        $record->pricefactor = $data->pricefactor;
        $record->limitfactor = $data->limitfactor;

        // If we can update, we add the id here.
        if ($data->id) {
            $record->id = $data->id;
            $DB->update_record('booking_campaigns', $record);
        } else {
            $this->id = $DB->insert_record('booking_campaigns', $record);
        }
    }

    /**
     * Sets the campaign defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_campaigns
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        // TODO.
        /* $data->bookingcampaigntype = $this->campaignname;

        $jsonobject = json_decode($record->campaignjson);
        $campaigndata = $jsonobject->campaigndata;

        $data->campaign_name = $jsonobject->name;
        $data->campaign_react_on_event_event = $campaigndata->boevent;*/
    }

    /**
     * Function to check if a campaign is currently active
     * for a specific booking option.
     * @param int $optionid
     * @return bool true if the campaign is currently active
     */
    public function check_if_campaign_is_active(int $optionid):bool {
        // TODO: implement!
        return false;
    }
}
