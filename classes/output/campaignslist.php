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
 * This file contains the definition for the renderable classes for a list of booking campaigns.
 *
 * @package   mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\output;

use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * Renderable classes for a list of booking campaigns.
 *
 * @package   mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class campaignslist implements renderable, templatable {

    /** @var array $campaigns */
    public $campaigns = [];

    /**
     * Constructor takes the campaigns to render and saves them as array.
     *
     * @param array $campaigns
     */
    public function __construct(array $campaigns) {

        foreach ($campaigns as $campaign) {
            switch ($campaign->type) {
                case MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD:
                    $campaign->bookingcampaigntype = 'campaign_customfield';
                    $campaignobj = json_decode($campaign->json);
                    $a = new stdClass;
                    $a->fieldname = $campaignobj->fieldname;
                    $a->fieldvalue = $campaignobj->fieldvalue;
                    $campaign->description = get_string('campaign_customfield_descriptiontext', 'mod_booking', $a);
                    $campaign->localizedtype = get_string('campaign_customfield', 'mod_booking');
                    $campaign->localizedstart = $this->render_localized_timestamp($campaign->starttime, current_language());
                    $campaign->localizedend = $this->render_localized_timestamp($campaign->endtime, current_language());
                    break;
                case MOD_BOOKING_CAMPAIGN_TYPE_BLOCKBOOKING:
                    $campaign->bookingcampaigntype = 'campaign_blockbooking';
                    $campaignobj = json_decode($campaign->json);
                    $a = new stdClass;
                    $a->fieldname = $campaignobj->fieldname;
                    $a->fieldvalue = $campaignobj->fieldvalue;
                    $campaign->description = get_string('campaign_blockbooking_descriptiontext', 'mod_booking', $a);
                    $campaign->localizedtype = get_string('campaign_blockbooking', 'mod_booking');
                    $campaign->localizedstart = $this->render_localized_timestamp($campaign->starttime, current_language());
                    $campaign->localizedend = $this->render_localized_timestamp($campaign->endtime, current_language());
                    break;
            }

            $this->campaigns[] = (array)$campaign;
        }
    }

    /**
     * Little helper function to render localized time string.
     * @param int $timestamp
     * @param string $lang the language to render, e.g. "de" or "en"
     * @return string the localized time string
     */
    private function render_localized_timestamp(int $timestamp, string $lang = 'en'):string {
        switch ($lang) {
            case 'de':
                return date('d. M Y, H:i', $timestamp);
                break;
            default:
                return date('M d Y, H:i', $timestamp);
                break;
        }
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return array
     *
     */
    public function export_for_template(renderer_base $output) {
        return [
                'campaigns' => $this->campaigns,
        ];
    }
}
