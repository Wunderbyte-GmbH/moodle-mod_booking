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
 * Option form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use dml_exception;
use coding_exception;
use core_form\dynamic_form;
use context;
use context_module;
use context_system;

defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/formslib.php");

use mod_booking\booking_option;
use mod_booking\option\fields_info;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use required_capability_exception;
use stdClass;

/**
 * Class to handle option form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_form extends dynamic_form {
    /**
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        $cmid = $formdata['cmid'] ?? 0;
        $optionid = $formdata['id'] ?? $formdata['optionid'] ?? 0;

        if (!empty($cmid)) {
            // We need context on this.
            $context = context_module::instance($cmid);
        } else if (empty($cmid) && !empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $formdata['cmid'] = $settings->cmid;
            $context = context_module::instance($settings->cmid);
        } else {
            $context = context_system::instance();
        }

        $formdata['context'] = $context;

        $mform = &$this->_form;

        $mform->addElement('hidden', 'scrollpos');
        $mform->setType('scrollpos', PARAM_INT);

        // Add all available fields in the right order.
        $classes = fields_info::instance_form_definition($mform, $formdata);

        if (!empty($classes)) {
            $this->add_action_buttons(true, get_string('save'));
        } else {
            $mform->addElement('html', '<div class="alert alert-warning">' .
                get_string('error:formcapabilitymissing', 'mod_booking') .
                '</div>');
        }
    }

    /**
     * Data preprocessing.
     *
     * @param array $defaultvalues
     *
     * @return void
     *
     */
    protected function data_preprocessing(&$defaultvalues) {

        // Custom lang strings.
        if (!isset($defaultvalues['descriptionformat'])) {
            $defaultvalues['descriptionformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['description'])) {
            $defaultvalues['description'] = '';
        }

        if (!isset($defaultvalues['notificationtextformat'])) {
            $defaultvalues['notificationtextformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['notificationtext'])) {
            $defaultvalues['notificationtext'] = '';
        }

        if (!isset($defaultvalues['beforebookedtext'])) {
            $defaultvalues['beforebookedtext'] = '';
        }

        if (!isset($defaultvalues['beforecompletedtext'])) {
            $defaultvalues['beforecompletedtext'] = '';
        }

        if (!isset($defaultvalues['aftercompletedtext'])) {
            $defaultvalues['aftercompletedtext'] = '';
        }
    }

    /**
     * Validation function.
     * @param array $data
     * @param array $files
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        fields_info::validation($data, $files, $errors);

        return $errors;
    }

    /**
     * Definition after data.
     * @return void
     * @throws coding_exception
     */
    public function definition_after_data() {

        $mform = $this->_form;
        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        fields_info::definition_after_data($mform, $formdata);
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        $cmid = $this->_ajaxformdata['cmid'] ?? 0;

        if (empty($cmid)) {
            return context_system::instance();
        }

        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {

        $context = $this->get_context_for_dynamic_submission();

        if (
            !has_capability('mod/booking:addeditownoption', $context)
            && !has_capability('mod/booking:updatebooking', $context)
        ) {
                throw new required_capability_exception($context, '', 'cant access edit form', '');
        }
    }


    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $data = (object)$this->_ajaxformdata ?? $this->_customdata;

        $data->id = $this->_ajaxformdata['optionid'];

        fields_info::set_data($data);

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission() {

        // Get data from form.
        $data = $this->get_data();

        // Pass data to update.
        $context = $this->get_context_for_dynamic_submission();

        $result = booking_option::update($data, $context);

        return $data;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {

        // Strangely, we get the ajax formdata here without a key.
        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        $optionid = $this->_ajaxformdata['id'] ?? 0;
        return new moodle_url('/mod/booking/editoption.php', ['id' => $cmid, 'optionid' => $optionid]);
    }
}
