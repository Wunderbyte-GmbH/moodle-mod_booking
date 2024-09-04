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
use context_module;
use core_form\dynamic_form;
use mod_booking\subbookings\subbookings_info;
use moodle_url;

/**
 * Dynamic form to delete a subbooking
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @package mod_booking
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbookingsdeleteform extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form;
        $ajaxformdata = $this->_ajaxformdata;

        // If we open an existing rule, we need to save the id right away.
        if (!empty($ajaxformdata['id'])) {
            $mform->addElement('hidden', 'id', $ajaxformdata['id']);
        }

        $mform->addElement('hidden', 'cmid', $ajaxformdata['cmid']);
        $mform->addElement('hidden', 'name', $ajaxformdata['name']);
        $mform->addElement('hidden', 'optionid', $ajaxformdata['optionid']);

        $mform->addElement('html', '<div><p>'
            . get_string('deletebookingruleconfirmtext', 'mod_booking')
            . '</p><p class="text-danger font-weight-bold">'
            . $ajaxformdata['name']
            . '</p></div>');
    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();

        // Delete the rule by its ID.
        subbookings_info::delete_subbooking((int)$data->id, $data->cmid, $data->optionid);

        return $data;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $data = (object) $this->_ajaxformdata;
        $this->set_data($data);

    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {
        $errors = [];
        // Not needed.
        return $errors;
    }


    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/editoptions.php');
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = $this->_ajaxformdata['cmid'];
        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        $cmid = $this->_ajaxformdata['cmid'];
        require_capability('mod/booking:updatebooking', context_module::instance($cmid));
    }
}
