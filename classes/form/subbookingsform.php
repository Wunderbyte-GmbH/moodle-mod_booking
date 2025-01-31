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
 * Dynamic subbookings form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @package mod_booking
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbookingsform extends dynamic_form {
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

            $this->prepare_ajaxformdata($ajaxformdata);
        }

        // We always need to get the optionid, but it might be only availalbe after loading from Db.
        $mform->addElement('hidden', 'optionid', $ajaxformdata['optionid']);
        $mform->addElement('hidden', 'cmid', $ajaxformdata['cmid']);

        $mform->addElement('text', 'subbooking_name', get_string('subbookingname', 'mod_booking'));
        $mform->setType('subbooking_name', PARAM_TEXT);

        subbookings_info::add_subbooking($mform, $ajaxformdata);
    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();

        subbookings_info::save_subbooking($data);

        return $data;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        if (!empty($this->_ajaxformdata['id'])) {
            $data = (object)$this->_ajaxformdata;
            $data = subbookings_info::set_data_for_form($data);
        } else {
            $data = (object)$this->_ajaxformdata;
        }

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

        /* subbookings_info::val  */

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

    /**
     * Prepare the ajax form data with all the information...
     * ... we need no have to load the form with the right handlers.
     *
     * @param array $ajaxformdata
     * @return void
     */
    private function prepare_ajaxformdata(array &$ajaxformdata) {

        global $DB;

        // If we have an ID, we retrieve the right rule from DB.
        if (!$record = $DB->get_record('booking_subbooking_options', ['id' => $ajaxformdata['id']])) {
            return;
        }

        $ajaxformdata['optionid'] = $record->optionid;

        if (empty($ajaxformdata['subbooking_type'])) {
            $ajaxformdata['subbooking_type'] = $record->type;
        }
    }
}
