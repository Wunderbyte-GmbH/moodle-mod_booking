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
namespace mod_booking\form;

use moodleform;
use mod_booking\booking;
use mod_booking\booking_option;

defined('MOODLE_INTERNAL') || die();

class option_form extends moodleform {

    public function definition() {
        global $CFG, $COURSE;
        $mform = & $this->_form;
        $mform->addElement('header', '', get_string('addeditbooking', 'booking'));
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'text', get_string('bookingoptionname', 'booking'), array('size' => '64'));
        $mform->addRule('text', get_string('required'), 'required', null, 'client');
        $mform->addRule('text', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('text', PARAM_TEXT);
        } else {
            $mform->setType('text', PARAM_CLEANHTML);
        }
        if (isset($this->_customdata['cmid'])) {
            $cmid = $this->_customdata['cmid'];
            $booking = new booking($cmid);
        }
        // Add custom fields here.
        $customfields = booking_option::get_customfield_settings();
        if (!empty($customfields)) {
            foreach ($customfields as $customfieldname => $customfieldarray) {
                // TODO: Only textfield yet defined, extend when there are more types.
                switch ($customfieldarray['type']) {
                    case 'textfield':
                        $mform->addElement('text', $customfieldname, $customfieldarray['value'],
                        array('size' => '64'));
                        $mform->setType($customfieldname, PARAM_NOTAGS);
                        break;
                    case 'select':
                        $soptions = explode("\n", $customfieldarray['options']);
                        $soptionselements = array();
                        $soptionselements[] = '';
                        foreach ($soptions as $key => $value) {
                            $val = trim($value);
                            $soptionselements["{$val}"] = $value;
                        }
                        $mform->addElement('select', $customfieldname, $customfieldarray['value'], $soptionselements);
                        $mform->setType("{$customfieldname}", PARAM_TEXT);
                        break;
                    case 'multiselect':
                        $soptions = explode("\n", $customfieldarray['options']);
                        $soptionselements = array();
                        foreach ($soptions as $key => $value) {
                            $val = trim($value);
                            $soptionselements["{$val}"] = $value;
                        }
                        $ms = $mform->addElement('select', $customfieldname, $customfieldarray['value'], $soptionselements);
                        $ms->setMultiple(true);
                        $mform->setType("{$customfieldname}", PARAM_TEXT);
                        break;
                }
            }
        }

        $mform->addElement('text', 'location', get_string('location', 'booking'),
                array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('location', PARAM_TEXT);
        } else {
            $mform->setType('location', PARAM_CLEANHTML);
        }

        $mform->addElement('text', 'institution', (empty($booking->settings->lblinstitution) ? get_string('institution', 'booking') : $booking->settings->lblinstitution),
            array('size' => '64'));
        $mform->setType('institution', PARAM_TEXT);

        $url = $CFG->wwwroot . '/mod/booking/institutions.php';
        if (isset($COURSE->id)) {
            $url .= '?courseid=' . $COURSE->id;
        }

        $mform->addElement('html',
                '<a target="_blank" href="' . $url . '">' . get_string('editinstitutions', 'booking') .
                         '</a>');

        $mform->addElement('text', 'address', get_string('address', 'booking'),
                array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('address', PARAM_TEXT);
        } else {
            $mform->setType('address', PARAM_CLEANHTML);
        }

        $mform->addElement('checkbox', 'limitanswers', get_string('limitanswers', 'booking'));
        $mform->addHelpButton('limitanswers', 'limitanswers', 'mod_booking');

        $mform->addElement('text', 'maxanswers', get_string('maxparticipantsnumber', 'booking'));
        $mform->setType('maxanswers', PARAM_INT);
        $mform->disabledIf('maxanswers', 'limitanswers', 'notchecked');

        $mform->addElement('text', 'maxoverbooking', get_string('maxoverbooking', 'booking'));
        $mform->setType('maxoverbooking', PARAM_INT);
        $mform->disabledIf('maxoverbooking', 'limitanswers', 'notchecked');

        $mform->addElement('checkbox', 'restrictanswerperiod',
                get_string('timecloseoption', 'booking'));

        $mform->addElement('date_time_selector', 'bookingclosingtime',
                get_string("bookingclose", "booking"));
        $mform->disabledIf('bookingclosingtime', 'restrictanswerperiod', 'notchecked');

        $coursearray = array();
        $coursearray[0] = get_string('donotselectcourse', 'booking');
        $totalcount = 1;
        // TODO: Using  moodle/course:viewhiddenactivities is not 100% accurate for finding teacher/non-editing teacher at least.
        $allcourses = get_courses_search(array(), 'c.shortname ASC', 0, 9999999,
            $totalcount, array('enrol/manual:enrol'));

        // Old code: $allcourses = $DB->get_records_select('course', 'id > 0', array(), 'id', 'id, shortname');
        foreach ($allcourses as $id => $courseobject) {
            $coursearray[$id] = $courseobject->shortname;
        }
        $options = array(
            'noselectionstring' => get_string('donotselectcourse', 'booking'),
        );
        $mform->addElement('autocomplete', 'courseid', get_string("choosecourse", "booking"), $coursearray, $options);

        $mform->addElement('duration', 'duration', get_string('bookingduration', 'booking'));
        $mform->setType('duration', PARAM_INT);
        $mform->setDefault('duration', 0);

        $mform->addElement('checkbox', 'startendtimeknown',
                get_string('startendtimeknown', 'booking'));

        $mform->addElement('checkbox', 'addtocalendar', get_string('addtocalendar', 'booking'));
        $mform->disabledIf('addtocalendar', 'startendtimeknown', 'notchecked');

        $mform->addElement('date_time_selector', 'coursestarttime',
                get_string("coursestarttime", "booking"));
        $mform->setType('coursestarttime', PARAM_INT);
        $mform->disabledIf('coursestarttime', 'startendtimeknown', 'notchecked');

        $mform->addElement('advcheckbox', 'enrolmentstatus', get_string('enrolmentstatus', 'mod_booking'),
            '', array('group' => 1), array(2, 0));
        $mform->setType('enrolmentstatus', PARAM_INT);
        $mform->disabledIf('enrolmentstatus', 'startendtimeknown', 'notchecked');

        $mform->addElement('date_time_selector', 'courseendtime',
            get_string("courseendtime", "booking"));
        $mform->setType('courseendtime', PARAM_INT);
        $mform->disabledIf('courseendtime', 'startendtimeknown', 'notchecked');

        $mform->addElement('editor', 'description', get_string('description'));
        $mform->setType('description', PARAM_CLEANHTML);

        $mform->addElement('text', 'pollurl', get_string('bookingpollurl', 'booking'),
                array('size' => '64'));
        $mform->setType('pollurl', PARAM_TEXT);
        $mform->addHelpButton('pollurl', 'pollurl', 'mod_booking');

        $mform->addElement('text', 'pollurlteachers',
                get_string('bookingpollurlteachers', 'booking'), array('size' => '64'));
        $mform->setType('pollurlteachers', PARAM_TEXT);
        $mform->addHelpButton('pollurlteachers', 'pollurlteachers', 'mod_booking');

        $mform->addElement('text', 'howmanyusers', get_string('howmanyusers', 'booking'), 0);
        $mform->addRule('howmanyusers', get_string('err_numeric', 'form'), 'numeric', null, 'client');
        $mform->setType('howmanyusers', PARAM_INT);

        $mform->addElement('text', 'removeafterminutes', get_string('removeafterminutes', 'booking'),
                0);
        $mform->addRule('removeafterminutes', get_string('err_numeric', 'form'), 'numeric', null, 'client');
        $mform->setType('removeafterminutes', PARAM_INT);

        $mform->addElement('filemanager', 'myfilemanageroption',
                get_string('bookingattachment', 'booking'), null,
                array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 50,
                                'accepted_types' => array('*')));

        // Advanced options.
        $mform->addElement('header', 'advancedoptions', get_string('advancedoptions', 'booking'));

        $mform->addElement('editor', 'notificationtext', get_string('notificationtext', 'booking'));
        $mform->setType('notificationtext', PARAM_CLEANHTML);

        $mform->addElement('selectyesno', 'disablebookingusers',
                get_string("disablebookingusers", "booking"));

        $mform->addElement('text', 'shorturl', get_string('shorturl', 'booking'),
                array('size' => '1333'));
        $mform->setType('shorturl', PARAM_TEXT);
        $mform->disabledIf('shorturl', 'optionid', 'eq', -1);

        $mform->addElement('checkbox', 'generatenewurl', get_string('generatenewurl', 'booking'));
        $mform->disabledIf('generatenewurl', 'optionid', 'eq', -1);

        // Booking option text.
        $mform->addElement('header', 'bookingoptiontextheader',
                get_string('bookingoptiontext', 'booking'));

        $mform->addElement('editor', 'beforebookedtext', get_string("beforebookedtext", "booking"),
                null, null);
        $mform->setType('beforebookedtext', PARAM_CLEANHTML);
        $mform->addHelpButton('beforebookedtext', 'beforebookedtext', 'mod_booking');

        $mform->addElement('editor', 'beforecompletedtext',
                get_string("beforecompletedtext", "booking"), null, null);
        $mform->setType('beforecompletedtext', PARAM_CLEANHTML);
        $mform->addHelpButton('beforecompletedtext', 'beforecompletedtext', 'mod_booking');

        $mform->addElement('editor', 'aftercompletedtext',
                get_string("aftercompletedtext", "booking"), null, null);
        $mform->setType('aftercompletedtext', PARAM_CLEANHTML);
        $mform->addHelpButton('aftercompletedtext', 'aftercompletedtext', 'mod_booking');

        // Templates - only visible when adding new.
        if (has_capability('mod/booking:manageoptiontemplates', $this->_customdata['context']) && $this->_customdata['bookingid'] != 0) {
            $mform->addElement('header', 'templateheader',
                get_string('addastemplate', 'booking'));
            $addastemplate = array(
                0 => get_string('notemplate', 'booking'),
                1 => get_string('asglobaltemplate', 'booking')
            );
            $mform->addElement('select', 'addastemplate', get_string('addastemplate', 'booking'),
                $addastemplate);
            $mform->setType('addastemplate', PARAM_INT);
            $mform->setDefault('addastemplate', 0);
        }

        // Hidden elements.
        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'bookingid', $this->_customdata['bookingid']);
        $mform->setType('bookingid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $this->_customdata['optionid']);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'bookingname');
        $mform->setType('bookingname', PARAM_TEXT);

        // Buttons.
        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton',
                get_string('savechangesanddisplay'));
        $buttonarray[] = &$mform->createElement("submit", 'submittandaddnew',
                get_string('submitandaddnew', 'booking'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
        $mform->closeHeaderBefore('buttonar');
    }

    protected function data_preprocessing(&$defaultvalues) {

        // Custom lang strings.
        if (!isset($defaultvalues['descriptionformat'])) {
            $defaultvalues['descriptionformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['description'])) {
            $defaultvalues['description'] = '';
        }

        if (!isset($defaultvalues['notificationtextformat'])) {
            $defaultvalues['notificationtextformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['notificationtext'])) {
            $defaultvalues['notificationtext'] = '';
        }

        if (!isset($defaultvalues['beforebookedtext'])) {
            $defaultvalues['beforebookedtext'] = '';
        }

        if (!isset($defaultvalues['beforecompletedtext'])) {
            $defaultvalues['beforecompletedtext'] = '';
        }

        if (!isset($defaultvalues['aftercompletedtext'])) {
            $defaultvalues['aftercompletedtext'] = '';
        }
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

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

        $groupname = $data['bookingname'] . ' - ' . $data['text'];
        $groupid = groups_get_group_by_name($data['courseid'], $groupname);
        if ($groupid && $data['optionid'] == 0) {
            $errors['text'] = get_string('groupexists', 'booking');
        }

        if ($data['optionid'] == -1) {
            // To prevent duplicate option names when creating a new booking option.
            if ($DB->record_exists('booking_options', array('text' => $data['text'], 'bookingid' => $data['bookingid']))) {
                $errors['text'] = get_string('duplicatename', 'mod_booking');
            }
        } else {
            // To prevent duplicate option names when updating existing booking options.
            $sql = "SELECT 'id'
                    FROM {booking_options} bo
                    WHERE bo.text = ? AND bo.bookingid = ? AND bo.id <> ?";
            $params = array($data['text'], $data['bookingid'], $data['optionid']);
            if ($DB->record_exists_sql($sql, $params)) {
                $errors['text'] = get_string('duplicatename', 'mod_booking');
            }
        }

        return $errors;
    }

    public function set_data($defaultvalues) {
        global $DB;
        $customfields = booking_option::get_customfield_settings();
        if (!empty($customfields)) {
            foreach ($customfields as $customfieldname => $customfieldarray) {
                if ($customfieldarray['type'] == 'multiselect') {
                    $defaultvalues->$customfieldname = explode("\n", (isset($defaultvalues->$customfieldname) ? $defaultvalues->$customfieldname : ''));
                }
            }
        }

        $defaultvalues->description = array('text' => $defaultvalues->description,
            'format' => FORMAT_HTML);
        $defaultvalues->notificationtext = array('text' => $defaultvalues->notificationtext,
            'format' => FORMAT_HTML);
        $defaultvalues->beforebookedtext = array('text' => $defaultvalues->beforebookedtext,
            'format' => FORMAT_HTML);
        $defaultvalues->beforecompletedtext = array('text' => $defaultvalues->beforecompletedtext,
            'format' => FORMAT_HTML);
        $defaultvalues->aftercompletedtext = array('text' => $defaultvalues->aftercompletedtext,
            'format' => FORMAT_HTML);

        if ($defaultvalues->bookingclosingtime) {
            $defaultvalues->restrictanswerperiod = "checked";
        }
        if ($defaultvalues->coursestarttime) {
            $defaultvalues->startendtimeknown = "checked";
        }

        $draftitemid = file_get_submitted_draft_itemid('myfilemanageroption');
        file_prepare_draft_area($draftitemid, $this->_customdata['context']->id, 'mod_booking', 'myfilemanageroption',
            $this->_customdata['optionid'], array('subdirs' => false, 'maxfiles' => 50, 'accepted_types' => array('*'), 'maxbytes' => 0));
        $defaultvalues->myfilemanageroption = $draftitemid;

        if ($defaultvalues->optionid > 0) {
            // Defaults for customfields.
            $cfdefaults = $DB->get_records('booking_customfields', array('optionid' => $defaultvalues->optionid));
            if (!empty($cfdefaults)) {
                foreach ($cfdefaults as $defaultval) {
                    $cfgvalue = $defaultval->cfgname;
                    $defaultvalues->$cfgvalue = $defaultval->value;
                }
            }
        }

        parent::set_data($defaultvalues);
    }

    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->descriptionformat = $data->description['format'];
            $data->description = $data->description['text'];

            $data->notificationtextformat = $data->notificationtext['format'];
            $data->notificationtext = $data->notificationtext['text'];

            $data->beforebookedtext = $data->beforebookedtext['text'];
            $data->beforecompletedtext = $data->beforecompletedtext['text'];
            $data->aftercompletedtext = $data->aftercompletedtext['text'];
        }

        return $data;
    }

}
