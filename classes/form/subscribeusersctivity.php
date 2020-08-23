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

class subscribeusersctivity extends \moodleform {
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form; // Don't forget the underscore!

        $bookingoptions = $DB->get_records_list("booking_options", "bookingid", array($this->_customdata['bookingid']), '', 'id,text,coursestarttime,location', '', '');

        $values = array();

        foreach ($bookingoptions as $key => $value) {
            $string = array();
            $string[] = $value->text;
            if ($value->coursestarttime != 0) {
                $string[] = userdate($value->coursestarttime);
            }
            if ($value->location != '') {
                $string[] = $value->location;
            }
            $values[$value->id] = implode($string, ', ');
        }

        unset($values[$this->_customdata['optionid']]);

        $mform->addElement('select', 'bookingoption', get_string('bookingoptionsmenu', 'booking'), $values); // Add elements to your form

        $buttonarray = array();
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('transefusers', 'booking'));
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
    }

    // Custom validation should be added here
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        return $errors;
    }
}