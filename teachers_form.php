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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

class mod_booking_teachers_form extends moodleform {

    public function definition() {
        global $DB, $CFG;

        $mform = & $this->_form;

        $cm = $this->_customdata['cm'];

        if ($this->_customdata['teachers']) {

            foreach ($this->_customdata['teachers'] as $user) {
                if (empty($user->imagealt)) {
                    $user->imagealt = '';
                }

                $userdata = $DB->get_record('booking_teachers',
                        array('optionid' => $this->_customdata['option']->id, 'userid' => $user->id));

                $checkmark = "&nbsp;";
                if ($userdata->completed == '1') {
                    $checkmark = "&#x2713;";
                }
                $mform->addElement('advcheckbox', "user[{$user->id}]",
                        $checkmark . " <a href=\"$CFG->wwwroot/user/view.php?id=$user->id\">" .
                                 fullname($user) . "</a>", '',
                                array('group' => $this->_customdata['option']->id + 1));
            }

            $this->add_checkbox_controller($this->_customdata['option']->id + 1);
        } else {
            $mform->addElement('html', '<p>' . get_string('nousers', 'booking') . '</p>');
        }

        $buttonarray = array();
        if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
            $bookingoption = new \mod_booking\booking_option($cm->id, $this->_customdata['option']->id, array(), 0, 0, false);

            $course = $DB->get_record('course', array('id' => $bookingoption->booking->settings->course));
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC && $bookingoption->booking->settings->enablecompletion > 0) {
                $buttonarray[] = &$mform->createElement('static', 'onlylabel', '',
                    '<span class="bookinglabelname">' . get_string('withselected', 'booking') . '</span>');
                $buttonarray[] = &$mform->createElement("submit", 'activitycompletion',
                    get_string('confirmoptioncompletion', 'booking'));
            }
            $buttonarray[] = &$mform->createElement("submit", 'turneditingon',
                get_string('turneditingon'));
        }

        $buttonarray[] = &$mform->createElement('cancel');

        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);

        // Hidden elements.
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $this->_customdata['optionid']);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'edit', $this->_customdata['edit']);
        $mform->setType('edit', PARAM_INT);
    }
}
