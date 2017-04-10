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
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page
}

require_once("$CFG->libdir/formslib.php");


class otherbookingaddrule_form extends moodleform {

    /**
     *
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $CFG, $DB;

        $bookingoptions = $DB->get_records_sql(
                "SELECT id, text
                    FROM {booking_options}
                    WHERE bookingid = (SELECT b.conectedbooking
                                        FROM {booking_options} bo
                                        LEFT JOIN {booking} b ON bo.bookingid = b.id
                                        WHERE bo.id = ?)
                    ORDER BY text ASC",
                array($this->_customdata['optionid']));

        $bookingoptionsarray = array();

        foreach ($bookingoptions as $value) {
            $bookingoptionsarray[$value->id] = $value->text;
        }

        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('select', 'otheroptionid',
                get_string('selectoptioninotherbooking', 'booking'), $bookingoptionsarray);
        $mform->setType('otheroptionid', PARAM_INT); // Set type of element
        $mform->addRule('otheroptionid', null, 'required', null, 'client');

        $mform->addElement('text', 'userslimit', get_string('otherbookinglimit', 'booking'), null,
                null); // Add elements to your form
        $mform->setType('userslimit', PARAM_INT);
        $mform->addRule('userslimit', null, 'numeric', null, 'client');
        $mform->addHelpButton('userslimit', 'otherbookinglimit', 'mod_booking');

        $mform->addElement('hidden', 'bookingotherid');
        $mform->setType('bookingotherid', PARAM_INT);

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

        return $data;
    }
}
