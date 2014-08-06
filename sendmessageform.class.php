<?php

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once $CFG->libdir.'/formslib.php';

class mod_booking_sendmessage_form extends moodleform {

	function definition() {
		global $CFG, $DB, $COURSE;

		$context = context_system::instance();
		$mform    = $this->_form;

		$mform->addElement('header', 'general', get_string('general', 'form'));

		$mform->addElement('text', 'subject', get_string('messagesubject', 'booking'), array('size'=>'64'));
		$mform->addRule('subject', null, 'required', null, 'client');
		$mform->setType('subject', PARAM_TEXT);

		$mform->addElement('textarea', 'message', get_string('messagetext', 'booking'), 'wrap="virtual" rows="20" cols="50"');
		$mform->addRule('message', null, 'required', null, 'client');
		$mform->setType('message', PARAM_TEXT);

		$mform->addElement('hidden', 'optionid');
		$mform->setType('optionid', PARAM_RAW);

		$mform->addElement('hidden', 'id');
		$mform->setType('id', PARAM_RAW);
		
		$mform->addElement('hidden', 'uids');
		$mform->setType('uids', PARAM_RAW);

		$this->add_action_buttons();
	}
}

?>