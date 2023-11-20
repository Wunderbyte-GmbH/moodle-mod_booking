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
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');


class mod_booking_sendmessage_form extends moodleform {

    public function definition() {

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'subject', get_string('messagesubject', 'booking'),
                ['size' => '64']);
        $mform->addRule('subject', null, 'required', null, 'client');
        $mform->setType('subject', PARAM_TEXT);

        $mform->addElement('textarea', 'message', get_string('messagetext', 'booking'),
                'wrap="virtual" rows="20" cols="50"');
        $mform->addRule('message', null, 'required', null, 'client');
        $mform->setType('message', PARAM_TEXT);

        $mform->addElement('hidden', 'optionid');
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'uids');
        $mform->setType('uids', PARAM_RAW);

        $this->add_action_buttons(true, get_string('sendmessage', 'mod_booking'));
    }
}
