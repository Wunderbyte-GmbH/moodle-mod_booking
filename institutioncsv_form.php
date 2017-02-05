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
require_once("$CFG->libdir/formslib.php");


class importoptions_form extends moodleform {

    // Add elements to form
    public function definition() {
        global $CFG;

        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', 'booking'), null,
                array('maxbytes' => $CFG->maxbytes, 'accepted_types' => '*'));
        $mform->addHelpButton('csvfile', 'csvfile', 'mod_booking');
        $mform->addRule('csvfile', null, 'required', null, 'client');

        $this->add_action_buttons(TRUE, get_string('importcsvtitle', 'booking'));
    }

    // Custom validation should be added here
    function validation($data, $files) {
        return array();
    }
}
