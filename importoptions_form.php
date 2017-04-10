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

    /**
     *
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', 'booking'), null,
                array('maxbytes' => $CFG->maxbytes, 'accepted_types' => '*'));
        $mform->addRule('csvfile', null, 'required', null, 'client');

        $mform->addElement('text', 'dateparseformat', get_string('dateparseformat', 'booking')); // Add elements to your form
        $mform->setType('dateparseformat', PARAM_NOTAGS); // Set type of element
        $mform->setDefault('dateparseformat', get_string('defaultdateformat', 'booking'));
        $mform->addRule('dateparseformat', null, 'required', null, 'client');
        $mform->addHelpButton('dateparseformat', 'dateparseformat', 'mod_booking');

        $this->add_action_buttons(true, get_string('importcsvtitle', 'booking'));
    }

    /**
     *
     * {@inheritDoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {
        return array();
    }
}
