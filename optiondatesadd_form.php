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

global $CFG;
require_once("$CFG->libdir/formslib.php");

/**
 * Add option date form
 * @author David Bogner
 *
 */
class optiondatesadd_form extends moodleform {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('hidden', 'optiondateid');
        $mform->setType('optiondateid', PARAM_INT);

        $mform->addElement('hidden', 'bookingid');
        $mform->setType('bookingid', PARAM_INT);

        $mform->addElement('date_time_selector', 'coursestarttime', get_string('from'));
        $mform->setType('coursestarttime', PARAM_INT);

        for ($i = 0; $i <= 23; $i++) {
            $hours[$i] = sprintf("%02d", $i);
        }
        for ($i = 0; $i < 60; $i += 5) {
            $minutes[$i] = sprintf("%02d", $i);
        }

        $courseendtime = array();
        $courseendtime[] = & $mform->createElement('select', 'endhour', get_string('hour', 'form'),
                $hours);
        $courseendtime[] = & $mform->createElement('select', 'endminute',
                get_string('minute', 'form'), $minutes);
        $mform->setType('endhour', PARAM_INT);
        $mform->setType('endminute', PARAM_INT);
        $mform->addGroup($courseendtime, 'endtime', get_string('to'), ' ', false);

        // Only allow creation of custom fields, when creating a new optiondate.
        if (empty($this->_customdata['optiondateid'])) {
            // Add checkbox to add first customfield
            $mform->addElement('checkbox', 'addcustomfield1', get_string('addcustomfield', 'booking'));

            // Between one to three custom fields are supported.
            $i = 1;
            $max = 3;
            while ($i <= $max) {
                $mform->addElement('text', 'customfieldname' . $i, get_string('customfieldname', 'booking'));
                $mform->setType('customfieldname' . $i, PARAM_TEXT);
                $mform->hideIf('customfieldname' . $i, 'addcustomfield' . $i, 'notchecked');

                $mform->addElement('text', 'customfieldvalue' . $i, get_string('customfieldvalue', 'booking'));
                $mform->setType('customfieldvalue' . $i, PARAM_TEXT);
                $mform->hideIf('customfieldvalue' . $i, 'addcustomfield' . $i, 'notchecked');

                // Show checkbox to add a custom field.
                if ($i < $max) {
                    $mform->addElement('checkbox', 'addcustomfield' . ($i + 1), get_string('addcustomfield', 'booking'));
                    $mform->hideIf('addcustomfield' . ($i + 1), 'addcustomfield' . $i, 'notchecked');
                }
                ++$i;
            }

            $mform->addElement('submit', 'submitbutton', get_string('add'));
        } else {
            // When editing an existing option date session only allow editing of already existing custom fields is allowed.
            $customfields = $DB->get_records("booking_customfields", array('optiondateid' => $this->_customdata['optiondateid']));
            $j = 1;
            foreach ($customfields as $customfield) {
                $mform->addElement('static', 'customfieldname' . $j, $customfield->cfgname);
                $mform->addElement('text', 'customfieldvalue' . $j, get_string('customfieldvalue', 'booking'), $customfield->value);
                $mform->setType('customfieldvalue' . $j, PARAM_TEXT);

                $j++;
            }
            $mform->addElement('submit', 'submitbutton', get_string('savechanges'));
        }
    }

    /**
     * Validate start and end time
     *
     * {@inheritdoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {
        $errors = array();
        $starttime = $data['coursestarttime'];
        $date = date("Y-m-d", $data['coursestarttime']);
        $endtime = strtotime($date . " {$data['endhour']}:{$data['endminute']}");
        if ($endtime < $starttime) {
            $errors['endtime'] = "Course end time must be after course start time";
        }
        return $errors;
    }

    /**
     *
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}