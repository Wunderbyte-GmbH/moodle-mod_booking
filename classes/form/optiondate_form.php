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
require_once("$CFG->libdir/formslib.php");

use context;
use context_module;
use core_form\dynamic_form;
use moodle_url;
use mod_booking\optiondates_handler;
use mod_booking\semester;
use stdClass;

/**
 * Add price categories form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondate_form extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;
        $optiondateshandler = new optiondates_handler();
        $optiondateshandler->add_optiondates_for_semesters_to_mform($mform);
        $mform->addElement('hidden', 'cmid', $this->_ajaxformdata['id']);
        $mform->setType('cmid', PARAM_INT);
        $this->add_action_buttons(false, 'Add');
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
        if (empty($data->reoccurringdatestring) || !isset($data->chooseperiod)) {
            return false;
        }

        $optiondateshandler = new optiondates_handler();
        $semester = new semester($data->chooseperiod);
        $dayinfo = $optiondateshandler->translate_string_to_day($data->reoccurringdatestring);
        $dates = $optiondateshandler->get_optiondate_series($semester->start, $semester->end, $dayinfo);
        $dates['cmid'] = $this->_ajaxformdata['cmid'];
        $dates['semesterid'] = $this->_ajaxformdata['chooseperiod'];
        $dates['dayofweektime'] = $this->_ajaxformdata['reoccurringdatestring'];
        return $dates;
    }


    /**
     * Load in existing data as form defaults
     *
     * Can be overridden to retrieve existing values from db by entity id and also
     * to preprocess editor and filemanager elements
     *
     * Example:
     *     $this->set_data(get_entity($this->_ajaxformdata['id']));
     */
    public function set_data_for_dynamic_submission(): void {
        $data = new stdClass();

        // TODO: get current optionid.
        // TODO: get options of current optionid.
        // TODO: load existing optiondates.

        // TODO: load existing option dates.

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
        $id = $this->_ajaxformdata['id'];
        if (!$id) {
            $id = $this->optional_param('cmid', '', PARAM_RAW);
        }
        return context_module::instance($id);
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
        $id = $this->_ajaxformdata['id'];
        if (!$id) {
            $id = $this->optional_param('cmid', '', PARAM_RAW);
        }
        return new moodle_url('/mod/booking/editoptions', array('id' => $id));
    }

    /**
     * Validate dates.
     *
     * {@inheritdoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {

        global $DB;

        $errors = array();

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
