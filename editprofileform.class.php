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
// $Id: editadvanced_form.php,v 1.14.2.18 2010/01/14 20:46:32 mudrd8mz Exp $
require_once($CFG->dirroot . '/lib/formslib.php');
require_once('lib.php');


class mod_booking_userprofile_form extends moodleform {

    // Define the form
    function definition() {
        global $USER, $CFG, $COURSE, $DB;

        $mform = & $this->_form;

        // Add some extra hidden fields
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'courseid', $COURSE->id);
        $mform->setType('courseid', PARAM_INT);

        // Next the customisable profile fields
        booking_profile_definition($mform);

        $this->add_action_buttons(false, get_string('updatemyprofile'));
    }

    function definition_after_data() {
        global $USER, $CFG, $DB;

        $mform = & $this->_form;
        if ($userid = $mform->getElementValue('id')) {
            $user = $DB->get_record('user', array('id' => $userid));
        } else {
            $user = false;
        }

        // Next the customisable profile fields
        profile_definition_after_data($mform, $userid);
    }

    function validation($usernew, $files) {
        global $CFG, $DB;

        $user = $DB->get_record('user', array('id' => $usernew['id']));
        $err = array();

        if (!empty($usernew)) {
            $data = new stdClass();

            foreach ($usernew as $akey => $aval) {
                $data->{$akey} = $aval;
            }
        }
        // Next the customisable profile fields
        $err += profile_validation($data, $files);

        if (count($err) == 0) {
            return true;
        } else {
            return $err;
        }
    }

    function get_um() {
        return $this->_upload_manager;
    }
}

?>
