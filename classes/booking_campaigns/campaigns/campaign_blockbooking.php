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

use context_system;
use mod_booking\booking_answers;
use mod_booking\booking_campaigns\booking_campaign;
use mod_booking\booking_campaigns\campaigns_info;
use mod_booking\booking_context_helper;
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
 * Campaign for blocking booking options having a certain booking option customfield.
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class campaign_blockbooking implements booking_campaign {
    /** @var int $id */
    public $id = 0;

    /** @var string $name can be any name set by the user */
    public $name = '';

    /** @var int $type */
    public $type = MOD_BOOKING_CAMPAIGN_TYPE_BLOCKBOOKING;

    /** @var string $bookingcampaigntype */
    public $bookingcampaigntype = 'campaign_blockbooking';

    /** @var string $bookingcampaigntypestringid */
    public $bookingcampaigntypestringid = 'campaignblockbooking';

    /** @var int $starttime */
    public $starttime = 0;

    /** @var int $endtime */
    public $endtime = 0;

    /** @var float $percentageavailableplaces */
    public $percentageavailableplaces = 50.0;

    // From JSON.
    /** @var string $blockoperator */
    public $blockoperator = '';

    /** @var string $bofieldname */
    public $bofieldname = '';

    /** @var string $campaignfieldnameoperator */
    public $campaignfieldnameoperator = '';

    /** @var string $fieldvalue */
    public $fieldvalue = '';

    /** @var string $cpfield */
    public $cpfield = '';

    /** @var array $cpvalue */
    public $cpvalue = [];

    /** @var string $cpoperator */
    public $cpoperator = '';

    /** @var string $blockinglabel */
    public $blockinglabel = '';

    /** @var string $hascapability */
    public $hascapability = '';

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

        // Set additional data stored in JSON.
        $jsonobj = json_decode($record->json);
        $this->bofieldname = $jsonobj->bofieldname ?? "";
        $this->campaignfieldnameoperator = $jsonobj->campaignfieldnameoperator ?? "";
        $this->fieldvalue = $jsonobj->fieldvalue ?? "";

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
        $this->blockoperator = $jsonobj->blockoperator;
        $this->blockinglabel = $jsonobj->blockinglabel;
        $this->hascapability = $jsonobj->hascapability;
        $this->percentageavailableplaces = $jsonobj->percentageavailableplaces ?? 50.0;
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
            time_handler::set_timeintervall()
        );
        $mform->setType('starttime', PARAM_INT);
        $mform->setDefault("starttime", time_handler::prettytime(time()));
        $mform->addHelpButton('starttime', 'campaignstart', 'mod_booking');

        $mform->addElement(
            'date_time_selector',
            'endtime',
            get_string('campaignend', 'mod_booking'),
            time_handler::set_timeintervall(),
        );
        $mform->setType('endtime', PARAM_INT);
        $mform->setDefault("endtime", time_handler::prettytime(time()));
        $mform->addHelpButton('endtime', 'campaignend', 'mod_booking');

        // Price factor (multiplier).
        $operators = [
            'blockbelow' => get_string('blockbelow', 'mod_booking'),
            'blockabove' => get_string('blockabove', 'mod_booking'),
            'blockalways' => get_string('blockalways', 'mod_booking'),
        ];

        $mform->addElement('select', 'blockoperator', get_string('blockoperator', 'mod_booking'), $operators);
        $mform->addHelpButton('blockoperator', 'blockoperator', 'mod_booking');

        // Limit factor (multiplier).
        $mform->addElement('float', 'percentageavailableplaces', get_string('percentageavailableplaces', 'mod_booking'), null);
        $mform->setDefault('percentageavailableplaces', 50.0);
        $mform->addHelpButton('percentageavailableplaces', 'percentageavailableplaces', 'mod_booking');
        $mform->hideIf('percentageavailableplaces', 'blockoperator', 'eq', 'blockalways');

        $mform->addElement(
            'textarea',
            'blockinglabel',
            get_string('blockinglabel', 'mod_booking'),
            'rows="2" cols="50"'
        );
        $mform->setType('blockinglabel', PARAM_TEXT);
        $mform->addHelpButton('blockinglabel', 'blockinglabel', 'mod_booking');
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
        $record->type = MOD_BOOKING_CAMPAIGN_TYPE_BLOCKBOOKING;

        if (!isset($data->json)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->json);
        }

        $jsonobject->bofieldname = $data->bofieldname;
        $jsonobject->campaignfieldnameoperator = $data->campaignfieldnameoperator;
        $jsonobject->fieldvalue = $data->fieldvalue;
        $jsonobject->cpfield = $data->cpfield ?? '';
        $jsonobject->cpoperator = $data->cpoperator ?? '';
        $jsonobject->cpvalue = $data->cpvalue ?? '';
        $jsonobject->blockoperator = $data->blockoperator;
        $jsonobject->blockinglabel = $data->blockinglabel;
        $jsonobject->hascapability = $data->hascapability ?? '';
        $jsonobject->percentageavailableplaces = $data->percentageavailableplaces;
        $record->json = json_encode($jsonobject);

        $record->name = $data->name;
        $record->starttime = $data->starttime;
        $record->endtime = $data->endtime;

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

        if ($jsonobject = json_decode($record->json)) {
            switch ($record->type) {
                case MOD_BOOKING_CAMPAIGN_TYPE_BLOCKBOOKING:
                    $data->bofieldname = $jsonobject->bofieldname;
                    $data->campaignfieldnameoperator = $jsonobject->campaignfieldnameoperator;
                    $data->fieldvalue = $jsonobject->fieldvalue;
                    $data->cpfield = $jsonobject->cpfield;
                    $data->cpoperator = $jsonobject->cpoperator;
                    $data->cpvalue = $jsonobject->cpvalue;
                    $data->blockoperator = $jsonobject->blockoperator;
                    $data->blockinglabel = $jsonobject->blockinglabel;
                    $data->hascapability = $jsonobject->hascapability;
                    $data->percentageavailableplaces = $jsonobject->percentageavailableplaces;
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
        return $price;
    }

    /**
     * Does not apply for this campaign type.
     * @param int $limit the original booking limit
     * @return int the new booking limit
     */
    private function get_campaign_limit(int $limit): int {
        return (int)$limit;
    }

    /**
     * Function to apply the logic of the particular campaign.
     * @param booking_option_settings $settings the booking option settings class
     * @param stdClass $dbrecord The record with the new data.
     */
    public function apply_logic(booking_option_settings &$settings, stdClass &$dbrecord) {

        // In this campaign, we add the class to the booking option settings.
        // This is because we have to run the is_blocking function and need to cache the instantiated campaign class.
        $settings->campaigns[] = $this;
        $dbrecord->campaigns[] = $this;
    }

    /**
     * Check if particular campaign is blocking right now.
     * @param booking_option_settings $settings the booking option settings class
     * @param int $userid the booking option settings class
     * @return array
     */
    public function is_blocking(booking_option_settings $settings, int $userid): array {
        global $PAGE;
        booking_context_helper::fix_booking_page_context($PAGE, $settings->cmid);

        $ba = singleton_service::get_instance_of_booking_answers($settings);

        $blocking = false;

        switch ($this->blockoperator) {
            case 'blockbelow':
                $blocking = ($settings->maxanswers * $this->percentageavailableplaces * 0.01)
                    > booking_answers::count_places($ba->usersonlist);
                break;
            case 'blockabove':
                $blocking = ($settings->maxanswers * $this->percentageavailableplaces * 0.01)
                    < booking_answers::count_places($ba->usersonlist);
                break;
            case 'blockalways':
                $blocking = true;
                break;
        }
        if (!$blocking) {
            return [
                'status' => false,
                'label' => '',
            ];
        }

        if (
            !empty($userid)
            && isset($this->cpfield)
            && !empty($bofieldname = $this->cpfield)
        ) {
            // If there is a value, it has to match in order to block.
            $blocking = campaigns_info::check_if_profilefield_applies($this->cpvalue, $this->cpfield, $this->cpoperator, $userid);
        }
        if ($blocking) {
            return [
                'status' => true,
                'label' => format_string($this->blockinglabel),
            ];
        }
        return [
            'status' => false,
            'label' => '',
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
