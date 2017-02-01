<?php
require_once("$CFG->libdir/formslib.php");


class importexcel_form extends moodleform {

    // Add elements to form
    public function definition() {
        global $CFG;

        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('filepicker', 'excelfile', get_string('excelfile', 'booking'), null,
                array('maxbytes' => $CFG->maxbytes, 'accepted_types' => '*'));
        $mform->addRule('excelfile', null, 'required', null, 'client');

        $this->add_action_buttons(TRUE, get_string('importexceltitle', 'booking'));
    }

    // Custom validation should be added here
    function validation($data, $files) {
        return array();
    }
}
