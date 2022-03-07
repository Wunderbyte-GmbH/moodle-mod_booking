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

use moodle_url;
use stdClass;

/**
 * Add price categories form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondate_form extends \core_form\dynamic_form {

    // TODO...

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('checkbox', 'includeholidays', 'includeholidays');

        $mform->addElement('select', 'semester', 'semester', array('WS22', 'WS23', 'SS22'));
        $mform->addElement('text', 'reocuringdatestring', get_string('reocuringdatestring', 'booking'));
        $mform->setType('reocuringdatestring', PARAM_TEXT);
        $mform->addElement('html', '<div class="datelist">');
        $mform->addElement('html', '</div>');
        $this->add_action_buttons(false, 'load_dates');
        $this->add_action_buttons(false, 'bla');
    }

    /**
     * Todo.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/user:manageownfiles', $this->get_context_for_dynamic_submission());
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
        echo json_encode($data);
        $semester = $this->get_semester($data->semester);
        $day = 'Monday';
        //$day = $this->translate_string_to_day($data->reocuringdatestring);
        //$dates = get_date_for_specific_day_between_dates($semester->startdate, $semester->enddate, $day);
        $dates = $this->get_date_for_specific_day_between_dates($semester->startdate, $semester->enddate, 'Monday');

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
        $data = new \stdClass();
        $this->set_data($data);
    }

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        global $USER;
        return \context_user::instance($USER->id);
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * This is used in the form elements sensitive to the page url, such as Atto autosave in 'editor'
     *
     * If the form has arguments (such as 'id' of the element being edited), the URL should
     * also have respective argument.
     *
     * @return \moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/user/files.php');
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


    // Helper functions.

    public function get_date_for_specific_day_between_dates($startdate, $enddate, $daystring) {
        for ($i = strtotime($daystring, $startdate); $i <= $enddate; $i = strtotime('+1 week', $i)) {
            $date = new stdClass();
            $date->date = date('Y-m-d', $i);
            $date->starttime = '10:00';
            $date->endtime = '11:00';
            $date->string = $date->date . " " .$date->starttime. "-" .$date->endtime;
            $datearray['dates'][] = $date;
        }
        return $datearray;
    }

    public function translate_string_to_day($string) {
        if ($string == 'Monday') {
            return $string;
        }
        $lowerstring = strtolower($string);
        if (str_starts_with($lowerstring, 'mo')) {
            $day = 'Monday';
            return 'Monday';
        }
        if ($string == 'di' || $string == 'dienstag' || $string == 'tuesday' || $string == 'tu') {
            $day = 'Tuesday';
        }
        if ($string == 'mi' || $string == 'mittwoch' || $string == 'wednesday') {
            $day = 'Wednesday';
        }
        if ($string == 'do' || $string == 'donnerstag' || $string == 'thursday') {
            $day = 'Thursday';
        }
        if ($string == 'fr' || $string == 'freitag' || $string == 'friday') {
            $day = 'Friday';
        }
        if ($string == 'sa' || $string == 'saturday' || $string == 'samstag') {
            $day = 'Saturday';
        }
        if ($string == 'so' || $string == 'sonntag' || $string == 'sunday') {
            $day = 'Sunday';
        }
        return $day;
    }

    public function get_semester($semesterid) {
        $semester = new stdClass();
        $semester->startdate = 1646598962;
        $semester->enddate = 1654505170;
        return $semester;
    }
}
