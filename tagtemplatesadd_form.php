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
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}

require_once("$CFG->libdir/formslib.php");


class tagtemplatesadd_form extends moodleform {

    /**
     *
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form; // Don't forget the underscore.

        $mform->addElement('text', 'tag', get_string('tagtag', 'booking'));
        $mform->setType('tag', PARAM_NOTAGS);
        $mform->addRule('tag', null, 'required', null, 'client');

        $mform->addElement('editor', 'text', get_string('tagtext', 'booking'), null, null);
        $mform->setType('text', PARAM_CLEANHTML);
        $mform->addRule('text', null, 'required', null, 'client');

        $mform->addElement('hidden', 'tagid');
        $mform->setType('tagid', PARAM_RAW);

        $this->add_action_buttons(true, get_string('savenewtagtemplate', 'booking'));
    }

    /**
     *
     * {@inheritDoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {
        return array();
    }

    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->text = $data->text['text'];
        }

        return $data;
    }
}