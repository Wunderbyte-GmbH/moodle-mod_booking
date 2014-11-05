<?php

require_once("$CFG->libdir/formslib.php");

class importoptions_form extends moodleform {

    //Add elements to form
    public function definition() {
        global $CFG;

        $mform = $this->_form; // Don't forget the underscore! 

        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', 'booking'), null, array('maxbytes' => $CFG->maxbytes, 'accepted_types' => '*'));
        $mform->addRule('csvfile', null, 'required', null, 'client');

        $mform->addElement('text', 'dateparseformat', get_string('dateparseformat', 'booking')); // Add elements to your form
        $mform->setType('dateparseformat', PARAM_NOTAGS);                   //Set type of element
        $mform->setDefault('dateparseformat', get_string('defaultdateformat', 'booking'));
        $mform->addRule('dateparseformat', null, 'required', null, 'client');


        $this->add_action_buttons(TRUE, get_string('importcsvtitle', 'booking'));
    }

    //Custom validation should be added here
    function validation($data, $files) {
        return array();
    }

}
