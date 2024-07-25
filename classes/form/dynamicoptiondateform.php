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
 * Dynamic option date form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use context;
use context_module;
use core_form\dynamic_form;
use moodle_url;
use mod_booking\option\dates_handler;
use stdClass;

/**
 * Dynamic optiondate form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamicoptiondateform extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;

        $cmid = $this->_ajaxformdata['cmid'];
        $bookingid = $this->_ajaxformdata['bookingid'];
        $optionid = $this->_ajaxformdata['optionid'];

        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'bookingid', $bookingid);
        $mform->setType('bookingid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        $loadexistingdates = false;

        // Only when first loading the form, we'll load the existing dates.
        if (!isset($this->_ajaxformdata['reoccurringdatestring'])) {
            $loadexistingdates = true;
        }

        $optiondateshandler = new dates_handler($optionid, $bookingid);
        $optiondateshandler->add_optiondates_for_semesters_to_mform($mform, $loadexistingdates);

        // Add submit button to create optiondate series. (Use $this, not $mform for add_action_buttons).
        $this->add_action_buttons(false, get_string('addoptiondateseries', 'mod_booking'));
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:addeditownoption', $this->get_context_for_dynamic_submission());
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * This method can return scalar values or arrays that can be json-encoded, they will be passed to the caller JS.
     *
     * Submission data can be accessed as: $this->get_data()
     *
     * @return mixed
     */
    public function process_dynamic_submission() {

        $data = $this->get_data();
        if (empty($data->reoccurringdatestring) || empty($data->chooseperiod)) {
            return false;
        }

        // If the string contains the keyword 'block' we don't do anything.
        if (strpos(strtolower($data->reoccurringdatestring), 'block') !== false) {
            return false;
        }

        // Only submit if we have a correct string.
        if (!dates_handler::reoccurring_datestring_is_correct($data->reoccurringdatestring)) {
            return false;
        }

        $optiondateshandler = new dates_handler();
        $dates = $optiondateshandler->get_optiondate_series($data->chooseperiod, $data->reoccurringdatestring);

        $dates['cmid'] = $this->_ajaxformdata['cmid'];
        $dates['optionid'] = $this->_ajaxformdata['optionid'];

        return $dates;
    }


    /**
     * Load in existing data as form defaults
     *
     * Can be overridden to retrieve existing values from db by entity id and also
     * to preprocess editor and filemanager elements
     *
     * Example:
     *     $this->set_data(get_entity($this->_ajaxformdata['cmid']));
     */
    public function set_data_for_dynamic_submission(): void {
        $data = new stdClass();
        $this->set_data($data);
    }

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = $this->_ajaxformdata['cmid'];
        if (!$cmid) {
            $cmid = $this->optional_param('cmid', '', PARAM_RAW);
        }
        return context_module::instance($cmid);
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * This is used in the form elements sensitive to the page url, such as Atto autosave in 'editor'
     *
     * If the form has arguments (such as 'id' of the element being edited), the URL should
     * also have respective argument.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        $cmid = $this->_ajaxformdata['cmid'];
        if (!$cmid) {
            $cmid = $this->optional_param('cmid', '', PARAM_RAW);
        }
        return new moodle_url('/mod/booking/editoptions.php', ['id' => $cmid]);
    }

    /**
     * Dates validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {

        $errors = [];

        // Check if the string is valid.
        if (!dates_handler::reoccurring_datestring_is_correct($data['reoccurringdatestring'])) {
            $errors['reoccurringdatestring'] = get_string('reoccurringdatestringerror', 'mod_booking');
        }

        return $errors;
    }

    /**
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}
