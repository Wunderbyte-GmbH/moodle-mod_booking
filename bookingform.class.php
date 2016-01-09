<?php

require_once $CFG->libdir . '/formslib.php';

class mod_booking_bookingform_form extends moodleform {

    function definition() {
        global $CFG, $DB, $COURSE;
        $mform = & $this->_form;

        // visible elements
        $mform->addElement('header', 'general', get_string('addeditbooking', 'booking'));

        $mform->addElement('text', 'text', get_string('booking', 'booking'), array('size' => '64'));
        $mform->addRule('text', get_string('required'), 'required', null, 'client');
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('text', PARAM_TEXT);
        } else {
            $mform->setType('text', PARAM_CLEANHTML);
        }

        $mform->addElement('editor', 'description', get_string('description'));
        $mform->setType('description', PARAM_CLEANHTML);

        $mform->addElement('text', 'location', get_string('location', 'booking'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('location', PARAM_TEXT);
        } else {
            $mform->setType('location', PARAM_CLEANHTML);
        }

        $mform->addElement('text', 'institution', get_string('institution', 'booking'), array('size' => '64', 'id' => 'institutionid'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('institution', PARAM_TEXT);
        } else {
            $mform->setType('institution', PARAM_CLEANHTML);
        }

        $url = $CFG->wwwroot . '/mod/booking/institutions.php';
        if (isset($COURSE->id)) {
            $url .= '?courseid=' . $COURSE->id;
        }
        
        $mform->addElement('html', '<a target="_blank" href="' . $url . '">' . get_string('editinstitutions', 'booking') . '</a>');
        
        $institutions = $DB->get_records('booking_institutions', array('course' => $COURSE->id));
        
        $tmpSearchInstitutions = array();
        
        foreach ($institutions as $institution) {
            $tmpSearchInstitutions[] = "'" . $institution->name . "'";
        }
        
        $tmpSearchInstitutions = implode(',', $tmpSearchInstitutions);
        
        
        $mform->addElement('static', null, '', "<script type=\"text/javascript\">
            //<![CDATA[
            YUI().use('autocomplete', 'autocomplete-filters', 'autocomplete-highlighters', function (Y) {
  Y.one('#institutionid').plug(Y.Plugin.AutoComplete, {
    resultFilters    : 'phraseMatch',
    resultHighlighter: 'phraseMatch',
    source           : [{$tmpSearchInstitutions}]
  });
});
            //]]>
            </script>");

        $mform->addElement('text', 'address', get_string('address', 'booking'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('address', PARAM_TEXT);
        } else {
            $mform->setType('address', PARAM_CLEANHTML);
        }

        // --- Limits for the answers ------------------------
        
        $mform->addElement('header', 'limitanswer', get_string('limitanswer', 'booking')); 
        
        $mform->addElement('checkbox', 'limitanswers', get_string('limitanswers', 'booking'));
        $mform->addHelpButton('limitanswers', 'limitanswers', 'mod_booking');

        $mform->addElement('text', 'maxanswers', get_string('maxparticipantsnumber', 'booking'));
        $mform->setType('maxanswers', PARAM_INT);
        $mform->disabledIf('maxanswers', 'limitanswers', 'notchecked');

        $mform->addElement('text', 'maxoverbooking', get_string('maxoverbooking', 'booking'));
        $mform->setType('maxoverbooking', PARAM_INT);
        $mform->disabledIf('maxoverbooking', 'limitanswers', 'notchecked');

        $mform->addElement('text', 'howmanyusers', get_string('howmanyusers', 'booking'), 0);
        $mform->setType('howmanyusers', PARAM_INT);

        // --- booking time ------

        $mform->addElement('header', 'answerperiod', get_string('timerestrict', 'booking')); 
        
        $mform->addElement('checkbox', 'restrictanswerperiodstart', get_string('timerestrictstart', 'booking'));
        
        $mform->addElement('date_time_selector', 'bookingopeningtime', get_string("bookingopen", "booking"));
        $mform->disabledIf('bookingopeningtime', 'restrictanswerperiodstart', 'notchecked');
        
         $mform->addElement('checkbox', 'restrictanswerperiodend', get_string('timerestrictend', 'booking'));

        $mform->addElement('date_time_selector', 'bookingclosingtime', get_string("bookingclose", "booking"));
        $mform->disabledIf('bookingclosingtime', 'restrictanswerperiodend', 'notchecked');
        
        $timeoptions = array(0 => get_string('showdateandtime', 'booking'),
                             1 => get_string('showonlydate', 'booking'));
        $mform->addElement('select', 'showdatetime', get_string('showdatetime', 'booking'), $timeoptions);
        $mform->setDefault('showdatetime', 0);
        $mform->addHelpButton('showdatetime', 'showdatetime', 'booking');

        $mform->addElement('selectyesno', 'disablebookingusers', get_string("disablebookingusers", "booking"));
        
        // --- duration of booking course -----
        
        $mform->addElement('header', 'answerperiod', get_string('coursestart', 'booking'));
        
        $mform->addElement('checkbox', 'startendtimeknown', get_string('startendtimeknown', 'booking'));

        $mform->addElement('checkbox', 'addtocalendar', get_string('addtocalendar', 'booking'));
        $mform->disabledIf('addtocalendar', 'startendtimeknown', 'notchecked');

        $mform->addElement('date_time_selector', 'coursestarttime', get_string("coursestarttime", "booking"));
        $mform->setType('coursestarttime', PARAM_INT);
        $mform->disabledIf('coursestarttime', 'startendtimeknown', 'notchecked');

        $mform->addElement('date_time_selector', 'courseendtime', get_string("courseendtime", "booking"));
        $mform->setType('courseendtime', PARAM_INT);
        $mform->disabledIf('courseendtime', 'startendtimeknown', 'notchecked');

        $mform->addElement('text', 'daystonotify', get_string('daystonotify', 'booking'));
        $mform->setType('daystonotify', PARAM_INT);
        $mform->disabledIf('daystonotify', 'startendtimeknown', 'notchecked');

        // MV START
        $notificationoptions = array(0 => get_string('notificationoptionadd', 'booking'),
                                     1 => get_string('notificationoptionextra', 'booking'));
        $mform->addElement('select', 'notificationoption', get_string('notificationoption', 'booking'), $notificationoptions);
        $mform->setDefault('notificationoption', 0);
        $mform->addHelpButton('notificationoption', 'notificationoption', 'booking');
        // MV END
        
        $mform->addElement('editor', 'notificationtext', get_string('notificationtext', 'booking'));
        $mform->setType('notificationtext', PARAM_CLEANHTML);   

        // --- connections ----------------

        $mform->addElement('header', 'connections', get_string('connections', 'booking'));

        $coursearray = array();
        $coursearray[0] = get_string('donotselectcourse', 'booking');
        $allcourses = $DB->get_records_select('course', 'id > 0', array(), 'id', 'id, shortname');
        foreach ($allcourses as $id => $courseobject) {
            $coursearray[$id] = $courseobject->shortname;
        }
        $mform->addElement('select', 'courseid', get_string("choosecourse", "booking"), $coursearray);
        
        $booking = $DB->get_record('booking', array('id' => $this->_customdata['bookingid']));
        $opts = array(0 => get_string('notconectedbooking', 'mod_booking'));

        $bookingoptions = $DB->get_records('booking_options', array('bookingid' => $booking->conectedbooking));

        foreach ($bookingoptions as $key => $value) {
            $opts[$value->id] = $value->text;
        }

        $mform->addElement('select', 'conectedoption', get_string('connectedoption', 'mod_booking'), $opts);
        $mform->setDefault('conectedoption', 0);
        $mform->addHelpButton('conectedoption', 'connectedoption', 'mod_booking');

        // --- URLs to polls ------------------

        $mform->addElement('text', 'pollurl', get_string('bookingpollurl', 'booking'), array('size' => '64'));
        $mform->setType('pollurl', PARAM_TEXT);
        $mform->addHelpButton('pollurl', 'pollurl', 'mod_booking');

        $mform->addElement('text', 'pollurlteachers', get_string('bookingpollurlteachers', 'booking'), array('size' => '64'));
        $mform->setType('pollurlteachers', PARAM_TEXT);
        $mform->addHelpButton('pollurlteachers', 'pollurlteachers', 'mod_booking');

        // --- Completion options ------------------------------------------------------------
        $mform->addElement('header', 'completion', get_string('completion', 'booking'));        

        $mform->addElement('text', 'removeafterminutes', get_string('removeafterminutes', 'booking'), 0);
        $mform->setType('removeafterminutes', PARAM_INT);   
        
        $mform->addElement('editor', 'completiontext', get_string('completiontext', 'booking'));
        $mform->setType('completiontext', PARAM_CLEANHTML);
        $mform->addHelpButton('completiontext', 'completiontext', 'mod_booking');
       
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
		$buttonarray = array();
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton', get_string('savechangesanddisplay'));
        $buttonarray[] = &$mform->createElement("submit", 'submittandaddnew', get_string('submitandaddnew', 'booking'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
        $mform->closeHeaderBefore('buttonar');
        //$this->add_action_buttons();
    }

    function data_preprocessing(&$default_values) {
        if (!isset($default_values['descriptionformat'])) {
            $default_values['descriptionformat'] = FORMAT_HTML;
        }

        if (!isset($default_values['description'])) {
            $default_values['description'] = '';
        }

        if (!isset($default_values['notificationtextformat'])) {
            $default_values['notificationtextformat'] = FORMAT_HTML;
        }

        if (!isset($default_values['notificationtext'])) {
            $default_values['notificationtext'] = '';
        }
         if (!isset($default_values['notificationtextformat'])) {
            $default_values['notificationtext'] = FORMAT_HTML;
    }

        if (!isset($default_values['notificationtext'])) {
            $default_values['notificationtext'] = '';
        }      
    }

    public function validation($data, $files) {

        $errors = parent::validation($data, $files);

        if ((array_key_exists('restrictanswerperiodstart', $data) && $data['restrictanswerperiodstart'] == 1) && (array_key_exists('restrictanswerperiodend', $data) && $data['restrictanswerperiodend'] == 1)) {
            if ($data['bookingopeningtime'] > $data['bookingclosingtime']) {
                $errors['bookingclosingtime'] = get_string('cutoffdatevalidation', 'booking');
            }
        }
        
        if (strlen($data['pollurl']) > 0) {
            if (!filter_var($data['pollurl'], FILTER_VALIDATE_URL)) {
                $errors['pollurl'] = get_string('entervalidurl', 'booking');
            }
        }

        if (strlen($data['pollurlteachers']) > 0) {
            if (!filter_var($data['pollurlteachers'], FILTER_VALIDATE_URL)) {
                $errors['pollurlteachers'] = get_string('entervalidurl', 'booking');
            }
        }

        return $errors;
    }

    function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->descriptionformat = $data->description['format'];
            $data->description = $data->description['text'];

            $data->notificationtextformat = $data->notificationtext['format'];
            $data->notificationtext = $data->notificationtext['text'];
            
            $data->completiontextformat = $data->completiontext['format'];
            $data->completiontext = $data->completiontext['text'];
        }
        return $data;
    }

}

?>