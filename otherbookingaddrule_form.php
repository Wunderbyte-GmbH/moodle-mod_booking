<?php
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page
}

require_once("$CFG->libdir/formslib.php");


class otherbookingaddrule_form extends moodleform {

    // Add elements to form
    public function definition() {
        global $CFG, $DB;

        $bookingoptions = $DB->get_records_sql(
                "SELECT id, text
                    FROM {booking_options}
                    WHERE bookingid = (SELECT b.conectedbooking
                                        FROM {booking_options} AS bo
                                        LEFT JOIN {booking} AS b ON bo.bookingid = b.id
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

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(TRUE, get_string('savenewtagtemplate', 'booking'));
    }

    // Custom validation should be added here
    function validation($data, $files) {
        return array();
    }

    public function get_data() {
        $data = parent::get_data();

        return $data;
    }
}
