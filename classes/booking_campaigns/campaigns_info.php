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
 * Base class for booking campaigns information.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\booking_campaigns;

use cache_helper;
use core_component;
use mod_booking\output\campaignslist;
use mod_booking\booking_campaigns\booking_campaign;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for booking campaigns information.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class campaigns_info {

    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @param array $ajaxformdata
     * @return void
     */
    public static function add_campaigns_to_mform(MoodleQuickForm &$mform,
        array &$ajaxformdata = null) {

        // First, get all the type of campaigns there are.
        $campaigns = self::get_campaigns();

        $campaignsforselect = [];
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $campaignsforselect['0'] = get_string('choose...', 'mod_booking'); */
        foreach ($campaigns as $campaign) {
            $fullclassname = get_class($campaign); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $campaignsforselect[$shortclassname] = $campaign->get_name_of_campaign_type();
        }

        $mform->registerNoSubmitButton('btn_bookingcampaigntype');
        $buttonargs = ['class' => 'd-none'];
        $categoryselect = [
            $mform->createElement('select', 'bookingcampaigntype',
            get_string('campaigntype', 'mod_booking'), $campaignsforselect),
            $mform->createElement('submit', 'btn_bookingcampaigntype',
                get_string('bookingcampaign', 'mod_booking'), $buttonargs),
        ];
        $mform->addGroup($categoryselect, 'bookingcampaigntype',
            get_string('campaigntype', 'mod_booking'), [' '], false);
        $mform->setType('btn_bookingcampaigntype', PARAM_NOTAGS);

        if (isset($ajaxformdata['bookingcampaigntype'])) {
            $campaign = self::get_campaign_by_name($ajaxformdata['bookingcampaigntype']);
        } else {
            list($campaign) = $campaigns;
        }

        // If campaign is empty, we use the default campaign.
        if (empty($campaign)) {
            $campaign = self::get_campaign_by_type(MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD);
        }

        $campaign->add_campaign_to_mform($mform, $ajaxformdata);
    }

    /**
     * Get all booking campaigns.
     * @return array an array of booking campaigns (instances of class booking_campaign).
     */
    public static function get_campaigns() {

        $campaigns = core_component::get_component_classes_in_namespace(
            "mod_booking",
            'booking_campaigns\campaigns'
        );

        return array_map(fn($a) => new $a, array_keys($campaigns));
    }

    /**
     * Get booking campaign by campaign type.
     * @param int $campaigntype the campaign type param
     * @return mixed an instance of the campaign class or null
     */
    public static function get_campaign_by_type(int $campaigntype) {

        $campaignname = '';
        switch($campaigntype) {
            case MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD:
                $campaignname = 'campaign_customfield';
                break;
            case MOD_BOOKING_CAMPAIGN_TYPE_BLOCKBOOKING:
                $campaignname = 'campaign_blockbooking';
                break;
        }

        $filename = 'mod_booking\\booking_campaigns\\campaigns\\' . $campaignname;

        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Get booking campaign by name.
     * @param string $campaignname the campaign class name
     * @return mixed an instance of the campaign class or null
     */
    public static function get_campaign_by_name(string $campaignname) {

        $filename = 'mod_booking\\booking_campaigns\\campaigns\\' . $campaignname;

        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Prepare data to set data to form.
     *
     * @param object $data
     * @return object
     */
    public static function set_data_for_form(object &$data) {

        global $DB;

        if (empty($data->id)) {
            // Nothing to set, we return empty object.
            return new stdClass();
        }

        // If we have an ID, we retrieve the right campaign from DB.
        $record = $DB->get_record('booking_campaigns', ['id' => $data->id]);
        $campaign = self::get_campaign_by_type($record->type);
        $campaign->set_defaults($data, $record);

        return (object)$data;

    }

    /**
     * Save the booking campaign.
     * @param stdClass &$data reference to the form data
     * @return void
     */
    public static function save_booking_campaign(stdClass &$data) {

        $campaign = self::get_campaign_by_name($data->bookingcampaigntype);
        $campaign->save_campaign($data);

        // Every time when we save a campaign, we have to purge data right away.
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackoptionsettings');
        cache_helper::purge_by_event('setbackprices');

        return;
    }

    /**
     * Delete a booking campaign by its ID.
     * @param int $campaignid the ID of the campaign
     */
    public static function delete_campaign(int $campaignid) {
        global $DB;
        $DB->delete_records('booking_campaigns', ['id' => (int)$campaignid]);
    }

    /**
     * Returns the rendered html for a list of campaigns.
     * @return string the rendered campaigns
     */
    public static function return_rendered_list_of_saved_campaigns() {
        global $PAGE;
        $campaigns = self::get_list_of_saved_campaigns();
        $output = $PAGE->get_renderer('mod_booking');
        return $output->render_campaignslist(new campaignslist($campaigns));
    }

    /**
     * Returns a list of campaigns in DB.
     * @return array
     */
    private static function get_list_of_saved_campaigns():array {
        global $DB;

        return singleton_service::get_all_campaigns();
    }

    /**
     * Get all campaigns from DB - but already instantiated.
     * @return array
     */
    public static function get_all_campaigns():array {
        $campaigns = [];
        $records = self::get_list_of_saved_campaigns();
        foreach ($records as $record) {
            /** @var booking_campaign $campaign */
            $campaign = self::get_campaign_by_type($record->type);
            $campaign->set_campaigndata($record);
            $campaigns[] = $campaign;
        }
        return $campaigns;
    }
}
