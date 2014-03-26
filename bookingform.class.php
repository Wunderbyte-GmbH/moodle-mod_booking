<?php
require_once $CFG->libdir.'/formslib.php';

class mod_booking_bookingform_form extends moodleform {
	function definition() {
		global $CFG, $DB;
		$mform =& $this->_form;
		
		// visible elements
		$mform->addElement('header', '', get_string('addeditbooking','booking'));

		$mform->addElement('text', 'text', get_string('booking','booking'), array('size'=>'64'));
		$mform->addRule('text', get_string('required'), 'required', null,'client');
		if (!empty($CFG->formatstringstriptags)) {
			$mform->setType('text', PARAM_TEXT);
		} else {
			$mform->setType('text', PARAM_CLEANHTML);
		}

		$mform->addElement('checkbox', 'limitanswers', get_string('limitanswers','booking'));

		$mform->addElement('text', 'maxanswers', get_string('maxparticipantsnumber','booking'));
		$mform->setType('maxanswers', PARAM_INT);
		$mform->disabledIf('maxanswers', 'limitanswers', 'notchecked');

		$mform->addElement('text', 'maxoverbooking', get_string('maxoverbooking','booking'));
		$mform->setType('maxoverbooking', PARAM_INT);
		$mform->disabledIf('maxoverbooking', 'limitanswers', 'notchecked');

		$mform->addElement('checkbox', 'restrictanswerperiod', get_string('timerestrict', 'booking'));

		$mform->addElement('date_time_selector', 'bookingclosingtime', get_string("bookingclose", "booking"));
		$mform->disabledIf('bookingclosingtime', 'restrictanswerperiod', 'notchecked');

		$coursearray = array();
		$coursearray[0] = get_string('donotselectcourse', 'booking');
		$allcourses = $DB->get_records_select('course', 'id > 0', array(),'id', 'id, shortname');
		foreach ($allcourses as $id => $courseobject) {
			$coursearray[$id] = $courseobject->shortname;
		}
		$mform->addElement('select', 'courseid', get_string("choosecourse", "booking"), $coursearray);

		$mform->addElement('checkbox', 'startendtimeknown', get_string('startendtimeknown','booking'));
		
		$mform->addElement('checkbox', 'addtocalendar', get_string('addtocalendar', 'booking'));
		$mform->disabledIf('addtocalendar', 'startendtimeknown', 'notchecked');

		$mform->addElement('date_time_selector', 'coursestarttime', get_string("coursestarttime", "booking"));
		$mform->setType('coursestarttime', PARAM_INT);
		$mform->disabledIf('coursestarttime', 'startendtimeknown', 'notchecked');

		$mform->addElement('date_time_selector', 'courseendtime', get_string("courseendtime", "booking"));
		$mform->setType('courseendtime', PARAM_INT);
		$mform->disabledIf('courseendtime', 'startendtimeknown', 'notchecked');

		$mform->addElement('text', 'daystonotify', get_string('daystonotify','booking'));
		$mform->setType('daystonotify', PARAM_INT);
		$mform->disabledIf('daystonotify', 'startendtimeknown', 'notchecked');

		$mform->addElement('editor', 'description', get_string('description'));
		$mform->setType('description', PARAM_CLEANHTML);

		$mform->addElement('text', 'pollurl', get_string('bookingpollurl', 'booking'), array('size'=>'64'));
		$mform->setType('pollurl', PARAM_TEXT);

		//hidden elements
		$mform->addElement('hidden', 'id');
		$mform->setType('id', PARAM_INT);
		
		$mform->addElement('hidden', 'bookingid');
		$mform->setType('bookingid', PARAM_INT);

		$mform->addElement('hidden', 'optionid');
		$mform->setType('optionid', PARAM_INT);
		//-------------------------------------------------------------------------------
		// buttons
		//
		$buttonarray=array();
		$buttonarray[] = &$mform->createElement('submit', 'submitbutton', get_string('savechangesanddisplay'));
		$buttonarray[] = &$mform->createElement("submit",'submittandaddnew', get_string('submitandaddnew','booking'));
		$buttonarray[] = &$mform->createElement('cancel');
		$mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
		$mform->closeHeaderBefore('buttonar');
		//$this->add_action_buttons();
	}
	
	function data_preprocessing(&$default_values){
		if (!isset($default_values['descriptionformat'])) {
			$default_values['descriptionformat'] = FORMAT_HTML;
		}
		if (!isset($default_values['description'])) {
			$default_values['description'] = '';
		}
	}

	public function validation($data, $files) {

		$errors = parent::validation($data, $files);

		if (strlen($data['pollurl']) > 0) {
			if(!filter_var($data['pollurl'], FILTER_VALIDATE_URL)) {
				$errors['pollurl'] = get_string('entervalidurl', 'booking');
			}
		}

		return $errors;
	}   
	
	function get_data() {
		$data = parent::get_data();
		if ($data) {
			$data->descriptionformat = $data->description['format'];
			$data->description = $data->description['text'];
		}        
		return $data;
	}
}
?>