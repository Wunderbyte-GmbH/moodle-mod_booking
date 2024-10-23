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

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use context;
use context_system;
use core_form\dynamic_form;
use mod_booking\booking_campaigns\campaigns_info;
use moodle_url;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Dynamic campaigns form.
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @package     mod_booking
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class campaignsform extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form;

        $customdata = $this->_customdata;
        $ajaxformdata = $this->_ajaxformdata;

        // If we open an existing campaign, we need to save the id right away.
        if (!empty($ajaxformdata['id'])) {
            $mform->addElement('hidden', 'id', $ajaxformdata['id']);
        }

        campaigns_info::add_campaigns_to_mform($mform, $ajaxformdata);

        // As this form is called normally from a modal, we don't need the action buttons.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $this->add_action_buttons(); // Use $this, not $mform. */
    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();
        campaigns_info::save_booking_campaign($data);
        return $data;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        if (!empty($this->_ajaxformdata['id'])) {
            $data = (object)$this->_ajaxformdata;
            $data = campaigns_info::set_data_for_form($data);
        } else {
            $data = (object)$this->_ajaxformdata;
        }

        $this->set_data($data);

    }

    /**
     * Campaings validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {
        $errors = [];

        switch ($data['bookingcampaigntype']) {
            case 'campaign_customfield':
                if ($data['pricefactor'] < 0 || $data['pricefactor'] > 1) {
                    $errors['pricefactor'] = get_string('error:pricefactornotbetween0and1', 'mod_booking');
                }
                if ($data['limitfactor'] < 0 || $data['limitfactor'] > 2) {
                    $errors['limitfactor'] = get_string('error:limitfactornotbetween1and2', 'mod_booking');
                }
                break;
            case 'campaign_blockbooking':
                if ($data['percentageavailableplaces'] < 0 || $data['percentageavailableplaces'] > 100) {
                    $errors['percentageavailableplaces'] = get_string('error:percentageavailableplaces', 'mod_booking');
                }
                if (empty(trim(strip_tags($data['blockinglabel'])))) {
                    $errors['blockinglabel'] = get_string('error:missingblockinglabel', 'mod_booking');
                }
                break;
        }

        if (empty($data['name'])) {
            $errors['name'] = get_string('error:entervalue', 'mod_booking');
        }

        if ($data['starttime'] >= $data['endtime']) {
            $errors['starttime'] = get_string('error:campaignstart', 'mod_booking');
            $errors['endtime'] = get_string('error:campaignend', 'mod_booking');
        }

        return $errors;
    }


    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/edit_campaigns.php');
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        // Perhaps we will need a specific campaigns capability.
        require_capability('moodle/site:config', context_system::instance());
    }
}
