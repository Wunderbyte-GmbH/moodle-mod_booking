<?php
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // / It must be included from a Moodle page
}

require_once ("$CFG->libdir/formslib.php");


class optiondatesadd_form extends moodleform {
    
    // Add elements to form
    public function definition() {
        global $CFG;
        
        $mform = $this->_form; // Don't forget the underscore!
        
        $mform->addElement('date_time_selector', 'coursestarttime', 
                get_string("coursestarttime", "booking"));
        $mform->setType('coursestarttime', PARAM_INT);
        
        $mform->addElement('date_time_selector', 'courseendtime', 
                get_string("courseendtime", "booking"));
        $mform->setType('courseendtime', PARAM_INT);
        
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_RAW);
        
        $mform->addElement('hidden', 'bookingid');
        $mform->setType('bookingid', PARAM_RAW);
        
        $mform->addElement('hidden', 'optionid');
        $mform->setType('optionid', PARAM_RAW);
        
        $this->add_action_buttons(TRUE, get_string('savenewoptiondates', 'booking'));
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