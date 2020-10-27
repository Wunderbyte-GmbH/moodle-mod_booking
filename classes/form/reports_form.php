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

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');
}

require_once("$CFG->libdir/formslib.php");

class reports_form extends moodleform {

    public function definition() {
        global $CFG;

        $reporttype = [
            1 => get_string('teachersreport', 'booking'),
            2 => get_string('presencereport', 'booking')
        ];

        $mform = $this->_form;

        $mform->addElement('select', 'reporttype', get_string('reporttype', 'booking'), $reporttype);
        $mform->addElement('date_time_selector', 'from', get_string('from'));
        $mform->disabledIf('from', 'reporttype', 'neq', 1);
        $mform->addElement('date_time_selector', 'to', get_string('to'));
        $mform->disabledIf('to', 'reporttype', 'neq', 1);

        $this->add_action_buttons(true, get_string('showreport', 'booking'));
    }

    public function validation($data, $files) {
        return array();
    }

}