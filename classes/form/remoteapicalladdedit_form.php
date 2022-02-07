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

require_once($CFG->libdir . '/formslib.php');

class remoteapicalladdedit_form extends \moodleform {
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form; // Don't forget the underscore!

        $courses = $DB->get_records_menu('course', $conditions = null, $sort = '', $fields = 'id, fullname');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_RAW);

        $mform->addElement('select', 'course', get_string('course'), $courses);
        $mform->addRule('course', get_string('fieldmandatory', 'booking'), 'required', null, 'client');
        $mform->addElement('text', 'url', get_string('url'));
        $mform->setType('url', PARAM_NOTAGS);
        $mform->addRule('url', get_string('fieldmandatory', 'booking'), 'required', null, 'client');
        $mform->addHelpButton('url', 'remoteapiurl', 'booking');

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        return array();
    }
}