<?php
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once ($CFG->dirroot.'/course/moodleform_mod.php');

class mod_booking_mod_form extends moodleform_mod {

	function definition() {
		global $CFG, $DB;

		$mform    =& $this->_form;

		//-------------------------------------------------------------------------------
		$mform->addElement('header', 'general', get_string('general', 'form'));

		$mform->addElement('text', 'name', get_string('bookingname', 'booking'), array('size'=>'64'));
		if (!empty($CFG->formatstringstriptags)) {
			$mform->setType('name', PARAM_TEXT);
		} else {
			$mform->setType('name', PARAM_CLEANHTML);
		}
		$mform->addRule('name', null, 'required', null, 'client');

        $this->add_intro_editor(true, get_string('bookingtext', 'booking'));

		//-------------------------------------------------------------------------------
		$menuoptions=array();
		$menuoptions[0] = get_string('disable');
		$menuoptions[1] = get_string('enable');
		
		//default options for booking options
		$mform->addElement('header', '', get_string('defaultbookingoption','booking'));
		
		$mform->addElement('select', 'limitanswers', get_string('limitanswers', 'booking'), $menuoptions);
		
		$mform->addElement('text', 'maxanswers', get_string('maxparticipantsnumber','booking'),0);
		$mform->disabledIf('maxanswers', 'limitanswers', 0);
		$mform->setType('maxanswers', PARAM_INT);
		
		$mform->addElement('text', 'maxoverbooking', get_string('maxoverbooking','booking'),0);
		$mform->disabledIf('maxoverbooking', 'limitanswers', 0);
		$mform->setType('maxoverbooking', PARAM_INT);
		
		//-------------------------------------------------------------------------------
		$mform->addElement('header', 'timerestricthdr', get_string('timerestrict', 'booking'));
		$mform->addElement('checkbox', 'timerestrict', get_string('timerestrict', 'booking'));

		$mform->addElement('date_time_selector', 'timeopen', get_string("bookingopen", "booking"));
		$mform->disabledIf('timeopen', 'timerestrict');

		$mform->addElement('date_time_selector', 'timeclose', get_string("bookingclose", "booking"));
		$mform->disabledIf('timeclose', 'timerestrict');
		
		//-------------------------------------------------------------------------------
		// CONFIRMATION MESSAGE
        $mform->addElement('header', 'confirmation', get_string('confirmationmessagesettings', 'booking'));
		
        $mform->addElement('selectyesno', 'sendmail', get_string("sendconfirmmail", "booking"));
		
        $mform->addElement('selectyesno', 'copymail', get_string("sendconfirmmailtobookingmanger", "booking"));
        
        
		$mform->addElement('text', 'bookingmanager', get_string('usernameofbookingmanager', 'booking'));
        $mform->setType('bookingmanager', PARAM_TEXT);
		$mform->setDefault('bookingmanager', 'admin');
		$mform->disabledIf('bookingmanager', 'copymail', 0);
		

		//-------------------------------------------------------------------------------
		$mform->addElement('header', 'miscellaneoussettingshdr', get_string('miscellaneoussettings', 'form'));

		$mform->addElement('editor', 'bookingpolicy', get_string("bookingpolicy", "booking"), null, null);
        $mform->setType('bookingpolicy', PARAM_CLEANHTML);

        $mform->addElement('selectyesno', 'allowupdate', get_string("allowdelete", "booking"));
		
		//-------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();
		//-------------------------------------------------------------------------------
		$this->add_action_buttons();
	}

	function data_preprocessing(&$default_values){
		if (empty($default_values['timeopen'])) {
			$default_values['timerestrict'] = 0;
		} else {
			$default_values['timerestrict'] = 1;
		}
        if (!isset($default_values['bookingpolicyformat'])) {
            $default_values['bookingpolicyformat'] = FORMAT_HTML;
        }
        if (!isset($default_values['bookingpolicy'])) {
            $default_values['bookingpolicy'] = '';
        }
        $default_values['bookingpolicy'] = array('text'=>$default_values['bookingpolicy'],'format'=>$default_values['bookingpolicyformat']);
	}

    function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->bookingpolicyformat = $data->bookingpolicy['format'];
            $data->bookingpolicy = $data->bookingpolicy['text'];
        }
        
        return $data;
    }
}
?>
