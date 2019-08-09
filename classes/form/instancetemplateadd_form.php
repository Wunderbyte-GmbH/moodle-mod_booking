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

defined('MOODLE_INTERNAL') || die();

class instancetemplateadd_form extends moodleform {

    public function definition() {
        global $CFG;
        $mform = & $this->_form;
        $mform->addElement('header', '', get_string('instancetemplate', 'booking'));
        $mform->addElement('text', 'name', get_string('name'), array('size' => '128'));
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }

        $this->add_action_buttons();
    }
}