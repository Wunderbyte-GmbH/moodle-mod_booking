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

class templates_form extends \moodleform {
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('filepicker', 'template', get_string('teplatefile', 'booking'), null,
                   array('maxbytes' => 1024, 'accepted_types' => 'xml'));

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        return array();
    }
}