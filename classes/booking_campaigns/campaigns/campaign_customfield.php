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
use mod_booking\booking_campaigns\campaigns_info;
use mod_booking\booking_option_settings;
use mod_booking\customfield\booking_handler;
use mod_booking\option\time_handler;
use mod_booking\singleton_service;
use mod_booking\task\purge_campaign_caches;
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
    public $type = MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD;

    /** @var string $bookingcampaigntype */
    public $bookingcampaigntype = 'campaign_customfield';

    /** @var string $bookingcampaigntypestringid */
    public $bookingcampaigntypestringid = 'campaigncustomfield';

    /** @var int $starttime */
    public $starttime = 0;

    /** @var int $endtime */
    public $endtime = 0;

    /** @var float $pricefactor */
    public $pricefactor = 1.0;

    /** @var float $limitfactor */
    public $limitfactor = 1.0;

    /** @var int $extendlimitforoverbooked */
    public $extendlimitforoverbooked = 0;

    // From JSON.
    /** @var string $bofieldname */
    public $bofieldname = '';

    /** @var string $campaignfieldnameoperator */
    public $campaignfieldnameoperator = '';

    /** @var string $fieldvalue */
    public $fieldvalue = '';

    /** @var string $cpfield */
    public $cpfield = '';

    /** @var string $cpoperator */
    public $cpoperator = '';

    /** @var array $cpvalue */
    public $cpvalue = [];

    /** @var bool $userspecificprice */
    public $userspecificprice = false;

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
        $this->extendlimitforoverbooked = $record->extendlimitforoverbooked ?? 0;

        // Set additional data stored in JSON.
        $jsonobj = json_decode($record->json);
        $this->bofieldname = $jsonobj->bofieldname ?? '';
        $this->campaignfieldnameoperator = $jsonobj->campaignfieldnameoperator ?? '';
        $this->fieldvalue = $jsonobj->fieldvalue ?? '';

        if (!empty($jsonobj->cpfield)) {
            // Cpfield should be type string.
            if (is_array($jsonobj->cpfield)) {
                $this->cpfield = $jsonobj->cpfield[0];
            } else {
                $this->cpfield = $jsonobj->cpfield;
            }
            $this->userspecificprice = true;

            $this->cpoperator = $jsonobj->cpoperator ?? '';
            // Cpvalue should be type array.
            if (!is_array($jsonobj->cpvalue)) {
                $this->cpvalue = [$jsonobj->cpvalue];
            } else {
                $this->cpvalue = $jsonobj->cpvalue ?? [];
            }
        }
    }

    /**
     * Add the campaign to the mform.
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata reference to form data
     * @return void
     */
    public function add_campaign_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {

        global $DB;

        campaigns_info::add_customfields_to_form($mform, $ajaxformdata);

        $mform->addElement(
            'date_time_selector',
            'starttime',
            get_string('campaignstart', 'mod_booking'),
            time_handler::set_timeintervall(),
        );
        $mform->setType('starttime', PARAM_INT);
        $mform->addHelpButton('starttime', 'campaignstart', 'mod_booking');
        $mform->setDefault('starttime', time_handler::prettytime(time()));

        $mform->addElement(
            'date_time_selector',
            'endtime',
            get_string('campaignend'),
            'mod_booking',
            time_handler::set_timeintervall(),
        );
        $mform->setType('endtime', PARAM_INT);
        $mform->addHelpButton('endtime', 'campaignend', 'mod_booking');
        $mform->setDefault('endtime', time_handler::prettytime(time()));
        // Price factor (multiplier).
        $mform->addElement('float', 'pricefactor', get_string('pricefactor', 'mod_booking'), null);
        $mform->setDefault('pricefactor', 1);
        $mform->addHelpButton('pricefactor', 'pricefactor', 'mod_booking');

        // Limit factor (multiplier).
        $mform->addElement('float', 'limitfactor', get_string('limitfactor', 'mod_booking'), null);
        $mform->setDefault('limitfactor', 1);
        $mform->addHelpButton('limitfactor', 'limitfactor', 'mod_booking');

        $mform->addElement('advcheckbox', 'extendlimitforoverbooked', get_string('extendlimitforoverbooked', 'mod_booking'), null);
        $mform->addHelpButton('extendlimitforoverbooked', 'extendlimitforoverbooked', 'mod_booking');
    }

    /**
     * Get the name of the campaign type.
     * @param bool $localized
     * @return string
     */
    public function get_name_of_campaign_type(bool $localized = true): string {
        return $localized ? get_string($this->bookingcampaigntypestringid, 'mod_booking') : $this->bookingcampaigntype;
    }

    /**
     * Save the campaign.
     * @param stdClass $data form data reference
     */
    public function save_campaign(stdClass &$data) {
        global $DB;

        $record = new stdClass();
        $record->type = MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD;

        if (!isset($data->json)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->json);
        }

        $jsonobject->bofieldname = $data->bofieldname;
        $jsonobject->campaignfieldnameoperator = $data->campaignfieldnameoperator;
        $jsonobject->fieldvalue = $data->fieldvalue;

        if (!empty($data->cpfield)) {
            $jsonobject->cpfield = $data->cpfield;
            $jsonobject->cpoperator = $data->cpoperator ?? '';
            $jsonobject->cpvalue = $data->cpvalue ?? [];
        }

        $record->json = json_encode($jsonobject);

        $record->name = $data->name;
        $record->starttime = $data->starttime;
        $record->endtime = $data->endtime;
        $record->pricefactor = $data->pricefactor;
        $record->limitfactor = $data->limitfactor;
        $record->extendlimitforoverbooked = $data->extendlimitforoverbooked;

        // We need to create two adhoc tasks to purge caches - one at start time and one at end time.
        $purgetaskstart = new purge_campaign_caches();
        $purgetaskstart->set_next_run_time($data->starttime);
        \core\task\manager::queue_adhoc_task($purgetaskstart);

        $purgetaskend = new purge_campaign_caches();
        $purgetaskend->set_next_run_time($data->endtime);
        \core\task\manager::queue_adhoc_task($purgetaskend);

        // If we can update, we add the id here.
        if (isset($data->id)) {
            $record->id = $data->id;
            $DB->update_record('booking_campaigns', $record);
        } else {
            $this->id = $DB->insert_record('booking_campaigns', $record);
        }
    }

    /**
     * Sets the campaign defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_campaigns
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->type = $record->type;
        $data->name = $record->name;
        $data->starttime = $record->starttime;
        $data->endtime = $record->endtime;
        $data->pricefactor = $record->pricefactor;
        $data->limitfactor = $record->limitfactor;
        $data->extendlimitforoverbooked = $record->extendlimitforoverbooked;

        if ($jsonobject = json_decode($record->json)) {
            switch ($record->type) {
                case MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD:
                    $data->bofieldname = $jsonobject->bofieldname;
                    $data->campaignfieldnameoperator = $jsonobject->campaignfieldnameoperator;
                    $data->fieldvalue = $jsonobject->fieldvalue;

                    $data->cpfield = $jsonobject->cpfield ?? 0;
                    $data->cpoperator = $jsonobject->cpoperator ?? '';
                    $data->cpvalue = $jsonobject->cpvalue ?? [];
                    break;
            }
        }
    }

    /**
     * Function to check if a campaign is currently active
     * for a specific booking option.
     * @param int $optionid
     * @param booking_option_settings $settings
     * @return bool true if the campaign is currently active
     */
    public function campaign_is_active(int $optionid, booking_option_settings $settings): bool {
        $this->fieldvalue = is_array($this->fieldvalue) ? reset($this->fieldvalue) : $this->fieldvalue;
        return campaigns_info::check_if_campaign_is_active(
            $this->starttime,
            $this->endtime,
            $settings->customfields[$this->bofieldname] ?? '',
            empty($this->bofieldname) ? "" : $this->fieldvalue,
            $this->campaignfieldnameoperator
        );
    }

    /**
     * Function to apply the campaign price factor.
     * @param float $price the original price
     * @param int $userid for userspecific campaigns.
     * @return float the new price
     */
    public function get_campaign_price(float $price, int $userid = 0): float {

        if (!$this->userspecificprice || empty($userid)) {
            $campaignprice = $price * $this->pricefactor;
        } else {
            $campaignprice = $price;
            $fieldapplies = campaigns_info::check_if_profilefield_applies(
                $this->cpvalue,
                $this->cpfield,
                $this->cpoperator,
                $userid
            );
            if ($fieldapplies) {
                $campaignprice = $price * $this->pricefactor;
            }
        }

        $discountprecision = 2;

        // If local_shopping_cart is present, we can actually turn rounding on and off.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $discountprecision = get_config('local_shopping_cart', 'rounddiscounts') ? 0 : 2;
        }

        return round($campaignprice, $discountprecision);
    }

    /**
     * Function to apply the campaign's booking limit factor.
     * If there are more booked users as the set limit (overbooked), we use that nr as base.
     * @param int $limit the original booking limit
     * @param booking_option_settings $settings
     * @return int the new booking limit
     */
    private function get_campaign_limit(int $limit, booking_option_settings $settings): int {

        if (empty($settings->maxanswers)) {
            return 0;
        }

        // Retrieve all the bookings.
        $ba = singleton_service::get_instance_of_booking_answers($settings);

        // Filter for the booking answers created before campaign started.
        $nrofbookedusers = 0;
        foreach ($ba->usersonlist as $answer) {
            if ($answer->timecreated < $this->starttime) {
                $nrofbookedusers++;
            }
        }

        $campaignlimit = $limit * $this->limitfactor;

        if (!empty($this->extendlimitforoverbooked)) {
            // If we are overbooked, we need to adjust the max value.
            if ($nrofbookedusers > $limit) {
                $campaignlimit = $campaignlimit - $limit + $nrofbookedusers;
            }
        }

        // We always round up.
        return (int)ceil($campaignlimit);
    }

    /**
     * Function to apply the logic of the particular campaign.
     * @param booking_option_settings $settings the booking option settings class
     * @param stdClass $dbrecord The record with the new data.
     */
    public function apply_logic(booking_option_settings &$settings, stdClass &$dbrecord) {
        $dbrecord->maxanswers = $this->get_campaign_limit($settings->maxanswers, $settings);
        $settings->maxanswers = $dbrecord->maxanswers;
    }

    /**
     * Check if particular campaign is blocking right now.
     * @param booking_option_settings $settings the booking option settings class
     * @param int $userid id of the user
     * @return array
     */
    public function is_blocking(booking_option_settings $settings, int $userid): array {

        return [
            'status' => false,
            'message' => '',
        ];
    }

    /**
     * Return name of campaign.
     *
     * @return string
     *
     */
    public function get_name_of_campaign(): string {
        return $this->name ?? '';
    }

    /**
     * Return id of campaign.
     *
     * @return int
     *
     */
    public function get_id_of_campaign(): int {
        return $this->id ?? 0;
    }
}
