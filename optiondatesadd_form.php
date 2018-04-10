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

require_once("$CFG->libdir/formslib.php");

/**
 * Add option date form
 * @author David Bogner
 *
 */
class optiondatesadd_form extends moodleform {

    /**
     *
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;

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

        $mform->addElement('hidden', 'optiondateid');
        $mform->setType('optiondateid', PARAM_INT);

        $mform->addElement('hidden', 'bookingid');
        $mform->setType('bookingid', PARAM_INT);

        if ($this->_customdata['optiondateid'] == '') {
            $mform->addElement('submit', 'submitbutton', get_string('add'));
        } else {
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