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

use moodleform;
use mod_booking\booking;
use mod_booking\booking_option;

defined('MOODLE_INTERNAL') || die();

class editdescription_form extends moodleform {

    public function definition() {
        global $CFG, $COURSE, $DB, $PAGE;

        $mform = & $this->_form;
        $mform->addElement('header', '', get_string('addeditbooking', 'booking'));
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('editor', 'description', get_string('description'));
        $mform->setType('description', PARAM_CLEANHTML);

        // Buttons.
        $this->add_action_buttons();
    }

    protected function data_preprocessing(&$defaultvalues) {

        // Custom lang strings.
        if (!isset($defaultvalues['descriptionformat'])) {
            $defaultvalues['descriptionformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['description'])) {
            $defaultvalues['description'] = '';
        }
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        return $errors;
    }

    public function set_data($defaultvalues) {
        global $DB;

        $defaultvalues->description = array('text' => (isset($defaultvalues->description) ? $defaultvalues->description : ''),
            'format' => FORMAT_HTML);

        parent::set_data($defaultvalues);
    }

    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->descriptionformat = $data->description['format'];
            $data->description = $data->description['text'];
        }

        return $data;
    }

}
