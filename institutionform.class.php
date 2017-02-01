<?php
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page
}

require_once $CFG->libdir . '/formslib.php';


class mod_booking_institution_form extends moodleform {

    function definition() {
        global $CFG, $DB, $COURSE;

        $context = context_system::instance();

        $mform = $this->_form;

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('institutionname', 'booking'),
                array('size' => '64'));
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_RAW);

        $mform->addElement('hidden', 'course');
        $mform->setType('course', PARAM_RAW);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_RAW);

        $this->add_action_buttons();
    }
}

?>