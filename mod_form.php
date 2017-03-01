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
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page
}

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');


class mod_booking_mod_form extends moodleform_mod {

    public $options = array();

    /**
     * Return an array of categories catid as key and categoryname as value
     *
     * @param number $cat_id
     * @param string $dashes
     * @param unknown $options
     * @return array of course category names indexed by category id
     */
    public function show_sub_categories($catid, $dashes = '', $options) {
        global $DB;
        $dashes .= '&nbsp;&nbsp;';
        $categories = $DB->get_records('booking_category', array('cid' => $catid));
        if (count((array) $categories) > 0) {
            foreach ($categories as $category) {
                $options[$category->id] = $dashes . $category->name;
                $options = $this->show_sub_categories($category->id, $dashes, $options);
            }
        }

        return $options;
    }

    public function add_completion_rules() {
        $mform = & $this->_form;

        $group = array();
        $group[] = & $mform->createElement('checkbox', 'enablecompletion', ' ',
                get_string('enablecompletion', 'booking'));
        $mform->addGroup($group, 'enablecompletiongroup',
                get_string('enablecompletiongroup', 'booking'), array(' '), false);

        return array('enablecompletiongroup');
    }

    public function completion_rule_enabled($data) {
        return !empty($data['enablecompletion']);
    }

    public function definition() {
        global $CFG, $DB, $COURSE, $USER;

        $context = context_system::instance();

        $mform = &$this->_form;

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('bookingname', 'booking'),
                array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('text', 'eventtype', get_string('eventtype', 'booking'),
                array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('eventtype', PARAM_TEXT);
        } else {
            $mform->setType('eventtype', PARAM_CLEANHTML);
        }
        $mform->addRule('eventtype', null, 'required', null, 'client');

        $versionmajor = booking_get_moodle_version_major();
        if ($versionmajor < '2015051100') {
            // This is valid before v2.9
            $this->add_intro_editor(false, get_string('bookingtext', 'booking'));
        } else {
            // This is valid after v2.9
            $this->standard_intro_elements(get_string('bookingtext', 'booking'));
        }

        $mform->addElement('text', 'duration', get_string('bookingduration', 'booking'),
                array('size' => '64'));
        $mform->setType('duration', PARAM_TEXT);

        $mform->addElement('text', 'points', get_string('bookingpoints', 'booking'), 0);
        $mform->setType('points', PARAM_FLOAT);

        $mform->addElement('text', 'organizatorname',
                get_string('bookingorganizatorname', 'booking'), array('size' => '64'));
        $mform->setType('organizatorname', PARAM_TEXT);

        $mform->addElement('text', 'pollurl', get_string('bookingpollurl', 'booking'),
                array('size' => '64'));
        $mform->setType('pollurl', PARAM_TEXT);
        $mform->addHelpButton('pollurl', 'pollurl', 'mod_booking');

        $mform->addElement('text', 'pollurlteachers',
                get_string('bookingpollurlteachers', 'booking'), array('size' => '64'));
        $mform->setType('pollurlteachers', PARAM_TEXT);
        $mform->addHelpButton('pollurlteachers', 'pollurlteachers', 'mod_booking');

        $mform->addElement('filemanager', 'myfilemanager',
                get_string('bookingattachment', 'booking'), null,
                array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 50,
                    'accepted_types' => array('*')));

        // -------------------------------------------------------------------------------
        $menuoptions = array();
        $menuoptions[0] = get_string('disable');
        $menuoptions[1] = get_string('enable');

        // default options for booking options
        $mform->addElement('header', 'limitanswer', get_string('defaultbookingoption', 'booking'));

        $mform->addElement('select', 'limitanswers', get_string('limitanswers', 'booking'),
                $menuoptions);

        $mform->addElement('text', 'maxanswers', get_string('maxparticipantsnumber', 'booking'), 0);
        $mform->disabledIf('maxanswers', 'limitanswers', 0);
        $mform->setType('maxanswers', PARAM_INT);

        $mform->addElement('text', 'maxoverbooking', get_string('maxoverbooking', 'booking'), 0);
        $mform->disabledIf('maxoverbooking', 'limitanswers', 0);
        $mform->setType('maxoverbooking', PARAM_INT);

        $whichviewopts = array('mybooking' => get_string('showmybookings', 'mod_booking'),
            'myoptions' => get_string('myoptions', 'mod_booking'),
            'showall' => get_string('showallbookings', 'mod_booking'),
            'showactive' => get_string('showactive', 'mod_booking'),
            'myinstitution' => get_string('showonlymyinstitutions', 'mod_booking'));
        $mform->addElement('select', 'whichview', get_string('whichview', 'mod_booking'),
                $whichviewopts);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'timerestricthdr', get_string('timerestrict', 'booking'));
        $mform->addElement('checkbox', 'timerestrict', get_string('timerestrict', 'booking'));

        $mform->addElement('date_time_selector', 'timeopen', get_string("bookingopen", "booking"));
        $mform->disabledIf('timeopen', 'timerestrict');

        $mform->addElement('date_time_selector', 'timeclose', get_string("bookingclose", "booking"));
        $mform->disabledIf('timeclose', 'timerestrict');

        // -------------------------------------------------------------------------------
        // CONFIRMATION MESSAGE
        $mform->addElement('header', 'confirmation',
                get_string('confirmationmessagesettings', 'booking'));

        $mform->addElement('selectyesno', 'sendmail', get_string("sendconfirmmail", "booking"));

        $mform->addElement('selectyesno', 'copymail',
                get_string("sendconfirmmailtobookingmanger", "booking"));

        $mform->addElement('selectyesno', 'sendmailtobooker',
                get_string('sendmailtobooker', 'booking'));
        $mform->addHelpButton('sendmailtobooker', 'sendmailtobooker', 'booking');

        $mform->addElement('text', 'daystonotify', get_string('daystonotify', 'booking'));
        $mform->setType('daystonotify', PARAM_INT);
        $mform->setDefault('daystonotify', 0);
        $mform->addHelpButton('daystonotify', 'daystonotify', 'booking');

        // booking manager
        $mform->addElement('text', 'bookingmanager',
                get_string('usernameofbookingmanager', 'booking'));
        $mform->addHelpButton('bookingmanager', 'usernameofbookingmanager', 'booking');
        $mform->setType('bookingmanager', PARAM_TEXT);
        $mform->setDefault('bookingmanager', $USER->username);
        $mform->addRule('bookingmanager', null, 'required', null, 'client');

        // Add the fields to allow editing of the default text:
        $context = context_system::instance();
        $editoroptions = array('subdirs' => false, 'maxfiles' => 0, 'maxbytes' => 0,
            'trusttext' => false, 'context' => $context);
        $fieldmapping = (object) array('status' => '{status}', 'participant' => '{participant}',
            'title' => '{title}', 'duration' => '{duration}', 'starttime' => '{starttime}',
            'endtime' => '{endtime}', 'startdate' => '{startdate}', 'enddate' => '{enddate}',
            'courselink' => '{courselink}', 'bookinglink' => '{bookinglink}',
            'location' => '{location}', 'institution' => '{institution}', 'address' => '{address}',
            'eventtype' => '{evventtype}');

        $mform->addElement('editor', 'bookedtext', get_string('bookedtext', 'booking'), null,
                $editoroptions);
        $default = array('text' => get_string('confirmationmessage', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('bookedtext', $default);
        $mform->addHelpButton('bookedtext', 'bookedtext', 'mod_booking');

        $mform->addElement('editor', 'waitingtext', get_string('waitingtext', 'booking'), null,
                $editoroptions);
        $default = array(
            'text' => get_string('confirmationmessagewaitinglist', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('waitingtext', $default);
        $mform->addHelpButton('waitingtext', 'waitingtext', 'mod_booking');

        $mform->addElement('editor', 'notifyemail', get_string('notifyemail', 'booking'), null,
                $editoroptions);
        $default = array(
            'text' => get_string('notifyemaildefaultmessage', 'booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('notifyemail', $default);
        $mform->addHelpButton('notifyemail', 'notifyemail', 'booking');

        $mform->addElement('editor', 'statuschangetext', get_string('statuschangetext', 'booking'),
                null, $editoroptions);
        $default = array(
            'text' => get_string('statuschangebookedmessage', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('statuschangetext', $default);
        $mform->addHelpButton('statuschangetext', 'statuschangetext', 'mod_booking');

        $mform->addElement('editor', 'userleave', get_string('userleave', 'booking'), null,
                $editoroptions);
        $default = array(
            'text' => get_string('userleavebookedmessage', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('userleave', $default);
        $mform->addHelpButton('userleave', 'userleave', 'mod_booking');

        $mform->addElement('editor', 'deletedtext', get_string('deletedtext', 'booking'), null,
                $editoroptions);
        $default = array(
            'text' => get_string('deletedbookingusermessage', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('deletedtext', $default);
        $mform->addHelpButton('deletedtext', 'deletedtext', 'mod_booking');

        $mform->addElement('editor', 'pollurltext', get_string('pollurltext', 'booking'), null,
                $editoroptions);
        $default = array('text' => get_string('pollurltextmessage', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('pollurltext', $default);
        $mform->addHelpButton('pollurltext', 'pollurltext', 'mod_booking');

        $mform->addElement('editor', 'pollurlteacherstext',
                get_string('pollurlteacherstext', 'booking'), null, $editoroptions);
        $default = array(
            'text' => get_string('pollurlteacherstextmessage', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('pollurlteacherstext', $default);
        $mform->addHelpButton('pollurlteacherstext', 'pollurlteacherstext', 'mod_booking');

        $mform->addElement('editor', 'notificationtext', get_string('notificationtext', 'booking'),
                null, $editoroptions);
        $default = array(
            'text' => get_string('notificationtextmessage', 'mod_booking', $fieldmapping),
            'format' => FORMAT_HTML);
        $default['text'] = str_replace("\n", '<br/>', $default['text']);
        $mform->setDefault('notificationtext', $default);
        $mform->addHelpButton('notificationtext', 'notificationtext', 'mod_booking');

        // Custom labels
        $mform->addElement('header', 'customlabels', get_string('customlabels', 'mod_booking'));

        $mform->addElement('text', 'btncacname', get_string('btncacname', 'booking'),
                array('size' => '64'));
        $mform->setType('btncacname', PARAM_TEXT);

        $mform->addElement('text', 'lblteachname', get_string('lblteachname', 'booking'),
                array('size' => '64'));
        $mform->setType('lblteachname', PARAM_TEXT);

        $mform->addElement('text', 'lblsputtname', get_string('lblsputtname', 'booking'),
                array('size' => '64'));
        $mform->setType('lblsputtname', PARAM_TEXT);

        $mform->addElement('text', 'btnbooknowname', get_string('btnbooknowname', 'booking'),
                array('size' => '64'));
        $mform->setType('btnbooknowname', PARAM_TEXT);

        $mform->addElement('text', 'btncancelname', get_string('btncancelname', 'booking'),
                array('size' => '64'));
        $mform->setType('btncancelname', PARAM_TEXT);

        $mform->addElement('text', 'lblbooking', get_string('lblbooking', 'booking'),
                array('size' => '64'));
        $mform->setType('lblbooking', PARAM_TEXT);

        $mform->addElement('text', 'lbllocation', get_string('lbllocation', 'booking'),
                array('size' => '64'));
        $mform->setType('lbllocation', PARAM_TEXT);

        $mform->addElement('text', 'lblinstitution', get_string('lblinstitution', 'booking'),
                array('size' => '64'));
        $mform->setType('lblinstitution', PARAM_TEXT);

        $mform->addElement('text', 'lblname', get_string('lblname', 'booking'),
                array('size' => '64'));
        $mform->setType('lblname', PARAM_TEXT);

        $mform->addElement('text', 'lblsurname', get_string('lblsurname', 'booking'),
                array('size' => '64'));
        $mform->setType('lblsurname', PARAM_TEXT);

        $mform->addElement('text', 'booktootherbooking',
                get_string('lblbooktootherbooking', 'booking'), array('size' => '64'));
        $mform->setType('booktootherbooking', PARAM_TEXT);

        $mform->addElement('text', 'lblacceptingfrom', get_string('lblacceptingfrom', 'booking'),
                array('size' => '64'));
        $mform->setType('lblacceptingfrom', PARAM_TEXT);

        $mform->addElement('text', 'lblnumofusers', get_string('lblnumofusers', 'booking'),
                array('size' => '64'));
        $mform->setType('lblnumofusers', PARAM_TEXT);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'miscellaneoussettingshdr',
                get_string('miscellaneoussettings', 'form'));

        $mform->addElement('editor', 'bookingpolicy', get_string("bookingpolicy", "booking"), null,
                null);
        $mform->setType('bookingpolicy', PARAM_CLEANHTML);

        $mform->addElement('selectyesno', 'cancancelbook', get_string("cancancelbook", "booking"));

        $mform->addElement('selectyesno', 'allowupdate', get_string("allowdelete", "booking"));

        $mform->addElement('selectyesno', 'autoenrol', get_string('autoenrol', 'booking'));
        $mform->addHelpButton('autoenrol', 'autoenrol', 'booking');

        $mform->addElement('selectyesno', 'addtogroup', get_string('addtogroup', 'booking'));
        $mform->addHelpButton('addtogroup', 'addtogroup', 'booking');

        $opts = array(0 => get_string('unlimited', 'mod_booking'));
        $extraopts = array_combine(range(1, 100), range(1, 100));
        $opts = $opts + $extraopts;
        $mform->addElement('select', 'maxperuser', get_string('maxperuser', 'mod_booking'), $opts);
        $mform->setDefault('maxperuser', 0);
        $mform->addHelpButton('maxperuser', 'maxperuser', 'mod_booking');

        $mform->addElement('selectyesno', 'showinapi', get_string("showinapi", "booking"));

        $mform->addElement('selectyesno', 'numgenerator', get_string("numgenerator", "booking"));

        $mform->addElement('text', 'paginationnum', get_string('paginationnum', 'booking'), 0);
        $mform->setDefault('paginationnum', 25);
        $mform->setType('paginationnum', PARAM_INT);

        $mform->addElement('text', 'banusernames', get_string('banusernames', 'booking'), 0);
        $mform->setType('banusernames', PARAM_TEXT);
        $mform->addHelpButton('banusernames', 'banusernames', 'mod_booking');

        $mform->addElement('selectyesno', 'showhelpfullnavigationlinks',
                get_string('showhelpfullnavigationlinks', 'booking'), 0);
        $mform->setDefault('showhelpfullnavigationlinks', 1);
        $mform->setType('showhelpfullnavigationlinks', PARAM_INT);
        // ----TAGS---------------------------------------------------

        if ($CFG->branch < 31) {
            // This is valid before v3.1
            $mform->addElement('header', 'tagsheader', get_string('tags'));
            $taglink = new moodle_url('/tag/search.php', array());
            $mform->addElement('static', 'searchtag', '',
                    '<a target="_blank" href="' . $taglink . '">' .
                             get_string('searchtag', 'booking') . '</a>');
            $mform->addElement('tags', 'tags', get_string('tags'));
        }

        $options = array();

        $options[0] = "&nbsp;";
        $categories = $DB->get_records('booking_category',
                array('course' => $COURSE->id, 'cid' => 0));

        foreach ($categories as $category) {
            $options[$category->id] = $category->name;
            $subcategories = $DB->get_records('booking_category',
                    array('course' => $COURSE->id, 'cid' => $category->id));
            $options = $this->show_sub_categories($category->id, '', $options);
        }

        $mform->addElement('header', 'categoryheader', get_string('category', 'booking'));

        $url = $CFG->wwwroot . '/mod/booking/categories.php';
        if (isset($COURSE->id)) {
            $url .= '?courseid=' . $COURSE->id;
        }

        $select = $mform->addElement('select', 'categoryid', get_string('category', 'booking'),
                $options, array('size' => 15));
        $select->setMultiple(true);

        $mform->addElement('html',
                '<a target="_blank" href="' . $url . '">' . get_string('addcategory', 'booking') .
                         '</a>');

        // $mform->addElement('selectwithlink', 'categoryid', get_string('category', 'booking'), $options, null,
        // array('link' => $url, 'label' => get_string('addcategory', 'booking')));

        $mform->addElement('header', 'categoryadditionalfields',
                get_string('additionalfields', 'booking'));
        $additionalfields = array();
        $tmpaddfields = $DB->get_records('user_info_field', array());
        foreach ($tmpaddfields as $field) {
            $additionalfields[$field->shortname] = $field->name;
        }
        $select = $mform->addElement('select', 'additionalfields',
                get_string('additionalfields', 'booking'), $additionalfields);
        $select->setMultiple(true);

        $mform->addElement('header', 'conectedbookingheader',
                get_string('conectedbooking', 'booking'));

        $bookings = $DB->get_records('booking', array('course' => $COURSE->id));

        $opts = array(0 => get_string('notconectedbooking', 'mod_booking'));

        foreach ($bookings as $key => $value) {
            $opts[$value->id] = $value->name;
        }

        $mform->addElement('select', 'conectedbooking',
                get_string('conectedbooking', 'mod_booking'), $opts);
        $mform->setDefault('conectedbooking', 0);
        $mform->addHelpButton('conectedbooking', 'conectedbooking', 'mod_booking');
        // -------------------------------------------------------------------------------
        $this->standard_grading_coursemodule_elements();
        // -------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();
        // -------------------------------------------------------------------------------
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$defaultvalues) {
        global $CFG;
        parent::data_preprocessing($defaultvalues);
        $options = array('subdirs' => false, 'maxfiles' => 50, 'accepted_types' => array('*'),
            'maxbytes' => 0);

        if ($this->current->instance) {
            $draftitemid = file_get_submitted_draft_itemid('myfilemanager');
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_booking',
                    'myfilemanager', $this->current->id, $options);
            $defaultvalues['myfilemanager'] = $draftitemid;

            if ($CFG->branch >= 31) {
                core_tag_tag::get_item_tags_array('mod_booking', 'booking', $this->current->id);
            } else {
                $defaultvalues['tags'] = tag_get_tags_array('booking', $this->current->id);
            }
        } else {
            $draftitemid = file_get_submitted_draft_itemid('myfilemanager');
            file_prepare_draft_area($draftitemid, null, 'mod_booking', 'myfilemanager', 0, $options);
            $defaultvalues['myfilemanager'] = $draftitemid;
        }

        if (empty($defaultvalues['timeopen'])) {
            $defaultvalues['timerestrict'] = 0;
        } else {
            $defaultvalues['timerestrict'] = 1;
        }
        if (!isset($defaultvalues['bookingpolicyformat'])) {
            $defaultvalues['bookingpolicyformat'] = FORMAT_HTML;
        }
        if (!isset($defaultvalues['bookingpolicy'])) {
            $defaultvalues['bookingpolicy'] = '';
        }

        if (!isset($defaultvalues['showinapi'])) {
            $defaultvalues['showinapi'] = 1;
        }

        $defaultvalues['bookingpolicy'] = array('text' => $defaultvalues['bookingpolicy'],
            'format' => $defaultvalues['bookingpolicyformat']);

        if (isset($defaultvalues['bookedtext'])) {
            $defaultvalues['bookedtext'] = array('text' => $defaultvalues['bookedtext'],
                'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['waitingtext'])) {
            $defaultvalues['waitingtext'] = array('text' => $defaultvalues['waitingtext'],
                'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['notifyemail'])) {
            $defaultvalues['notifyemail'] = array('text' => $defaultvalues['notifyemail'],
                'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['statuschangetext'])) {
            $defaultvalues['statuschangetext'] = array('text' => $defaultvalues['statuschangetext'],
                'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['deletedtext'])) {
            $defaultvalues['deletedtext'] = array('text' => $defaultvalues['deletedtext'],
                'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['pollurltext'])) {
            $defaultvalues['pollurltext'] = array('text' => $defaultvalues['pollurltext'],
                'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['pollurlteacherstext'])) {
            $defaultvalues['pollurlteacherstext'] = array(
                'text' => $defaultvalues['pollurlteacherstext'], 'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['notificationtext'])) {
            $defaultvalues['notificationtext'] = array('text' => $defaultvalues['notificationtext'],
                'format' => FORMAT_HTML);
        }
        if (isset($defaultvalues['userleave'])) {
            $defaultvalues['userleave'] = array('text' => $defaultvalues['userleave'],
                'format' => FORMAT_HTML);
        }
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if ($DB->count_records('user', array('username' => $data['bookingmanager'])) != 1) {
            $errors['bookingmanager'] = get_string('bookingmanagererror', 'booking');
        }

        if (strlen($data['pollurl']) > 0) {
            if (!filter_var($data['pollurl'], FILTER_VALIDATE_URL)) {
                $errors['pollurl'] = get_string('entervalidurl', 'booking');
            }
        }

        if ($data['paginationnum'] < 1) {
                $errors['paginationnum'] = get_string('errorpagination', 'booking');
        }

        if (strlen($data['pollurlteachers']) > 0) {
            if (!filter_var($data['pollurlteachers'], FILTER_VALIDATE_URL)) {
                $errors['pollurlteachers'] = get_string('entervalidurl', 'booking');
            }
        }

        return $errors;
    }

    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->bookingpolicyformat = $data->bookingpolicy['format'];
            $data->bookingpolicy = $data->bookingpolicy['text'];
        }

        return $data;
    }
}
