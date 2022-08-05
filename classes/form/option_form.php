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

use mod_booking\booking_elective;
use mod_booking\utils\wb_payment;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\customfield\booking_handler;
use mod_booking\price;
use mod_booking\singleton_service;
use local_entities\entitiesrelation_handler;
use moodleform;

class option_form extends moodleform {

    public function definition() {
        global $CFG, $COURSE, $DB, $PAGE;

        /* At first get the option form configuration from DB.
        Unfortunately, we need this, because hideIf does not work with
        editors, headers and html elements. */
        $optionformconfig = [];
        if ($optionformconfigrecords = $DB->get_records('booking_optionformconfig')) {
            foreach ($optionformconfigrecords as $optionformconfigrecord) {
                $optionformconfig[$optionformconfigrecord->elementname] = $optionformconfigrecord->active;
            }
        }
        // Also get the form mode, which can be 'simple' or 'expert'.
        $formmode = get_user_preferences('optionform_mode');
        if (empty($formmode)) {
            // Default: Simple mode.
            $formmode = 'simple';
        }

        $mform = & $this->_form;

        $cmid = 0;
        $optionid = 0;
        if (isset($this->_customdata['cmid'])) {
            $cmid = $this->_customdata['cmid'];
            $booking = new booking($cmid);
        }
        if (isset($this->_customdata['optionid'])) {
            $optionid = $this->_customdata['optionid'];
        }

        // Get booking option settings from cache or DB via singleton service.
        if ($optionid > 0) {
            $bookingoptionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        } else {
            $bookingoptionsettings = null;
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

        $mform->addElement('hidden', 'returnurl');
        $mform->setType('returnurl', PARAM_LOCALURL);

        $mform->addElement('hidden', 'scrollpos');
        $mform->setType('scrollpos', PARAM_INT);

        // Header "General".
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Option templates.
        $optiontemplates = array('' => '');
        $alloptiontemplates = $DB->get_records('booking_options', array('bookingid' => 0), '', $fields = 'id, text', 0, 0);

        // If there is no license key and there is more than one template, we only use the first one.
        if (count($alloptiontemplates) > 1 && !wb_payment::is_currently_valid_licensekey()) {
            $alloptiontemplates = [reset($alloptiontemplates)];
            $mform->addElement('static', 'nolicense', get_string('licensekeycfg', 'mod_booking'),
                get_string('licensekeycfgdesc', 'mod_booking'));
        }

        foreach ($alloptiontemplates as $key => $value) {
            $optiontemplates[$value->id] = $value->text;
        }

        $mform->addElement('select', 'optiontemplateid', get_string('populatefromtemplate', 'mod_booking'),
            $optiontemplates);

        // Booking option identifier.
        $mform->addElement('text', 'identifier', get_string('optionidentifier', 'mod_booking'), array('size' => '10'));
        $mform->addRule('identifier', get_string('required'), 'required', null, 'client');
        $mform->addRule('identifier', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('identifier', PARAM_TEXT);
        $mform->addHelpButton('identifier', 'optionidentifier', 'mod_booking');
        // By default, a random identifier will be generated.
        $randomidentifier = substr(str_shuffle(md5(microtime())), 0, 8);
        $mform->setDefault('identifier', $randomidentifier);

        // Prefix to be shown before the title.
        $mform->addElement('text', 'titleprefix', get_string('titleprefix', 'mod_booking'), array('size' => '10'));
        $mform->addRule('titleprefix', get_string('maximumchars', '', 10), 'maxlength', 10, 'client');
        $mform->setType('titleprefix', PARAM_TEXT);
        $mform->addHelpButton('titleprefix', 'titleprefix', 'mod_booking');

        // Booking option name.
        $mform->addElement('text', 'text', get_string('bookingoptionname', 'mod_booking'), array('size' => '64'));
        $mform->addRule('text', get_string('required'), 'required', null, 'client');
        $mform->addRule('text', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('text', PARAM_TEXT);
        } else {
            $mform->setType('text', PARAM_CLEANHTML);
        }
        // Add standard name here.
        $eventtype = $booking->settings->eventtype;
        if ($eventtype && strlen($eventtype) > 0) {
            $eventtype = "- $eventtype ";
        } else {
            $eventtype = '';
        }
        $boptionname = "$COURSE->fullname $eventtype";
        $mform->setDefault('text', $boptionname);

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

        // Visibility.
        $visibilityoptions = [
            0 => get_string('optionvisible', 'mod_booking'),
            1 => get_string('optioninvisible', 'mod_booking')
        ];
        $mform->addElement('select', 'invisible', get_string('optionvisibility', 'mod_booking'), $visibilityoptions);
        $mform->setType('invisible', PARAM_INT);
        $mform->setDefault('invisible', 0);
        $mform->addHelpButton('invisible', 'optionvisibility', 'mod_booking');

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['description']) || $optionformconfig['description'] == 1) {
            $mform->addElement('editor', 'description', get_string('description'));
            $mform->setType('description', PARAM_CLEANHTML);
        }

        // Internal annotation.
        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['annotation']) || $optionformconfig['annotation'] == 1) {
            $mform->addElement('editor', 'annotation', get_string('optionannotation', 'mod_booking'));
            $mform->setType('annotation', PARAM_CLEANHTML);
            $mform->addHelpButton('annotation', 'optionannotation', 'mod_booking');
        }

        // Location.
        $sql = 'SELECT DISTINCT location FROM {booking_options} ORDER BY location';
        $locationarray = $DB->get_fieldset_sql($sql);

        $locationstrings = array();
        foreach ($locationarray as $item) {
            $locationstrings[$item] = $item;
        }

        $options = array(
                'noselectionstring' => get_string('donotselectlocation', 'mod_booking'),
                'tags' => true
        );
        $mform->addElement('autocomplete', 'location', get_string('addnewlocation', 'mod_booking'), $locationstrings, $options);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('location', PARAM_TEXT);
        } else {
            $mform->setType('location', PARAM_CLEANHTML);
        }
        $mform->addHelpButton('location', 'location', 'mod_booking');

        // Institution.
        $sql = 'SELECT DISTINCT institution FROM {booking_options} ORDER BY institution';
        $institutionarray = $DB->get_fieldset_sql($sql);

        $institutionstrings = array();
        foreach ($institutionarray as $item) {
            $institutionstrings[$item] = $item;
        }

        $options = array(
                'noselectionstring' => get_string('donotselectinstitution', 'mod_booking'),
                'tags' => true
        );
        $mform->addElement('autocomplete', 'institution',
            get_string('addnewinstitution', 'mod_booking'), $institutionstrings, $options);
        $mform->addHelpButton('institution', 'institution', 'mod_booking');

        $url = $CFG->wwwroot . '/mod/booking/institutions.php';
        if (isset($COURSE->id)) {
            $url .= '?courseid=' . $COURSE->id;
        }

        // Only show, if it is not turned off in the option form config.
        // In expert mode we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['institution']) || $optionformconfig['institution'] == 1) {
            $mform->addElement('html',
                '<a target="_blank" href="' . $url . '">' . get_string('editinstitutions', 'mod_booking') .
                         '</a>');
        }

        $mform->addElement('text', 'address', get_string('address', 'mod_booking'),
                array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('address', PARAM_TEXT);
        } else {
            $mform->setType('address', PARAM_CLEANHTML);
        }

        // Upload an image for the booking option.
        $mform->addElement('filemanager', 'bookingoptionimage',
                get_string('bookingoptionimage', 'mod_booking'), null,
                array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1,
                                'accepted_types' => array('image')));

        $mform->addElement('checkbox', 'limitanswers', get_string('limitanswers', 'mod_booking'));
        $mform->addHelpButton('limitanswers', 'limitanswers', 'mod_booking');

        $mform->addElement('text', 'maxanswers', get_string('maxparticipantsnumber', 'mod_booking'));
        $mform->setType('maxanswers', PARAM_INT);
        $mform->disabledIf('maxanswers', 'limitanswers', 'notchecked');

        $mform->addElement('text', 'maxoverbooking', get_string('maxoverbooking', 'mod_booking'));
        $mform->setType('maxoverbooking', PARAM_INT);
        $mform->disabledIf('maxoverbooking', 'limitanswers', 'notchecked');

        $mform->addElement('checkbox', 'restrictanswerperiod',
                get_string('timecloseoption', 'mod_booking'));

        $mform->addElement('date_time_selector', 'bookingclosingtime',
                get_string("bookingclose", "booking"));
        $mform->setType('bookingclosingtime', PARAM_INT);
        $mform->disabledIf('bookingclosingtime', 'restrictanswerperiod', 'notchecked');

        $coursearray = array();
        $coursearray[0] = get_string('donotselectcourse', 'mod_booking');
        $totalcount = 1;
        // TODO: Using  moodle/course:viewhiddenactivities is not 100% accurate for finding teacher/non-editing teacher at least.
        $allcourses = get_courses_search(array(), 'c.shortname ASC', 0, 9999999,
            $totalcount, array('enrol/manual:enrol'));

        $coursearray[-1] = get_string('newcourse', 'booking');
        foreach ($allcourses as $id => $courseobject) {
            $coursearray[$id] = $courseobject->shortname;
        }
        $options = array(
            'noselectionstring' => get_string('donotselectcourse', 'mod_booking'),
        );
        $mform->addElement('autocomplete', 'courseid', get_string("choosecourse", "booking"), $coursearray, $options);

        $mform->addElement('duration', 'duration', get_string('bookingduration', 'mod_booking'));
        $mform->setType('duration', PARAM_INT);
        $mform->setDefault('duration', 0);

        $mform->addElement('checkbox', 'startendtimeknown',
                get_string('startendtimeknown', 'mod_booking'));

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

        // Add to course calendar dropdown.
        $caleventtypes = [
            0 => get_string('caldonotadd', 'mod_booking'),
            1 => get_string('caladdascourseevent', 'mod_booking')
        ];
        $mform->addElement('select', 'addtocalendar', get_string('addtocalendar', 'mod_booking'), $caleventtypes);
        if (!get_config('booking', 'addtocalendar')) {
            $addtocalendar = 0;
        } else {
            $addtocalendar = 1;
        }
        $mform->setDefault('addtocalendar', $addtocalendar);
        if (get_config('booking', 'addtocalendar_locked')) {
            // If the setting is locked in settings.php it will be frozen.
            $mform->freeze('addtocalendar');
        } else {
            // Otherwise, we have the usual behavior depending on the startendtimeknown checkbox.
            $mform->disabledIf('addtocalendar', 'startendtimeknown', 'notchecked');
        }

        $mform->addElement('text', 'pollurl', get_string('bookingpollurl', 'mod_booking'), array('size' => '64'));
        $mform->setType('pollurl', PARAM_TEXT);
        $mform->addHelpButton('pollurl', 'pollurl', 'mod_booking');

        $mform->addElement('text', 'pollurlteachers',
                get_string('bookingpollurlteachers', 'mod_booking'), array('size' => '64'));
        $mform->setType('pollurlteachers', PARAM_TEXT);
        $mform->addHelpButton('pollurlteachers', 'pollurlteachers', 'mod_booking');

        $mform->addElement('text', 'howmanyusers', get_string('howmanyusers', 'mod_booking'), 0);
        $mform->addRule('howmanyusers', get_string('err_numeric', 'form'), 'numeric', null, 'client');
        $mform->setType('howmanyusers', PARAM_INT);

        $mform->addElement('text', 'removeafterminutes', get_string('removeafterminutes', 'mod_booking'),
                0);
        $mform->addRule('removeafterminutes', get_string('err_numeric', 'form'), 'numeric', null, 'client');
        $mform->setType('removeafterminutes', PARAM_INT);

        $mform->addElement('filemanager', 'myfilemanageroption',
                get_string('bookingattachment', 'mod_booking'), null,
                array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 50,
                                'accepted_types' => array('*')));


        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['datesheader']) || $optionformconfig['datesheader'] == 1) {
            // Datesection for Dynamic Load.
            $mform->addElement('header', 'datesheader', get_string('dates', 'mod_booking'));
            $mform->addElement('html', '<div id="optiondates-form"></div>');

            $semesterid = null;
            $dayofweektime = '';
            if ($bookingoptionsettings) {
                $semesterid = $bookingoptionsettings->semesterid;
                $dayofweektime = $bookingoptionsettings->dayofweektime;
            }
            // Save semesterid and dayofweektime string in hidden inputs, so we can access them via $_POST.
            $mform->addElement('html',
                '<input type="text" data-fieldtype="text" class="d-none felement" id="semesterid" name="semesterid" value="' .
                $semesterid . '"></input>');
            $mform->addElement('html',
                '<input type="text" data-fieldtype="text" class="d-none felement" id="dayofweektime" name="dayofweektime" value="' .
                $dayofweektime . '"></input>');
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['advancedoptions']) || $optionformconfig['advancedoptions'] == 1) {
            // Advanced options.
            $mform->addElement('header', 'advancedoptions', get_string('advancedoptions', 'mod_booking'));
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['notificationtext']) || $optionformconfig['notificationtext'] == 1) {
            $mform->addElement('editor', 'notificationtext', get_string('notificationtext', 'mod_booking'));
            $mform->setType('notificationtext', PARAM_CLEANHTML);
        }

        $mform->addElement('selectyesno', 'disablebookingusers', get_string('disablebookingusers', 'mod_booking'));
        $mform->setType('disablebookingusers', PARAM_INT);

        $mform->addElement('text', 'shorturl', get_string('shorturl', 'mod_booking'),
                array('size' => '1333'));
        $mform->setType('shorturl', PARAM_TEXT);
        $mform->disabledIf('shorturl', 'optionid', 'eq', -1);

        $mform->addElement('checkbox', 'generatenewurl', get_string('generatenewurl', 'mod_booking'));
        $mform->disabledIf('generatenewurl', 'optionid', 'eq', -1);

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['bookingoptiontextheader']) || $optionformconfig['bookingoptiontextheader'] == 1) {
            // Booking option text.
            $mform->addElement('header', 'bookingoptiontextheader',
                    get_string('bookingoptiontext', 'mod_booking'));
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['beforebookedtext']) || $optionformconfig['beforebookedtext'] == 1) {
            $mform->addElement('editor', 'beforebookedtext', get_string("beforebookedtext", "booking"),
                    null, null);
            $mform->setType('beforebookedtext', PARAM_CLEANHTML);
            $mform->addHelpButton('beforebookedtext', 'beforebookedtext', 'mod_booking');
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['beforecompletedtext']) || $optionformconfig['beforecompletedtext'] == 1) {
            $mform->addElement('editor', 'beforecompletedtext',
                    get_string("beforecompletedtext", "booking"), null, null);
            $mform->setType('beforecompletedtext', PARAM_CLEANHTML);
            $mform->addHelpButton('beforecompletedtext', 'beforecompletedtext', 'mod_booking');
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($formmode == 'expert' ||
            !isset($optionformconfig['aftercompletedtext']) || $optionformconfig['aftercompletedtext'] == 1) {
            $mform->addElement('editor', 'aftercompletedtext',
                    get_string("aftercompletedtext", "booking"), null, null);
            $mform->setType('aftercompletedtext', PARAM_CLEANHTML);
            $mform->addHelpButton('aftercompletedtext', 'aftercompletedtext', 'mod_booking');
        }

        // Add price.
        $price = new price($this->_customdata['optionid']);
        $price->add_price_to_mform($mform);

        // Add Electives.
        if ($booking->settings->iselective == 1) {
            booking_elective::add_elective_to_option_form($mform, $cmid, $optionid);
        }

        // Add entities.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('bookingoption');
            $erhandler->instance_form_definition($mform, $optionid);
        }

        // Add custom fields.
        $handler = booking_handler::create();

        $handler->instance_form_definition($mform, $optionid);

        // Templates and recurring 'events' - only visible when adding new.
        if ($this->_customdata['optionid'] == -1) {

            // Workaround: Only show, if it is not turned off in the option form config.
            // We currently need this, because hideIf does not work with headers.
            // In expert mode, we do not hide anything.
            if ($formmode == 'expert' ||
                !isset($optionformconfig['recurringheader']) || $optionformconfig['recurringheader'] == 1) {
                $mform->addElement('header', 'recurringheader',
                            get_string('recurringheader', 'mod_booking'));
            }

            $mform->addElement('checkbox', 'repeatthisbooking',
                        get_string('repeatthisbooking', 'mod_booking'));
            $mform->disabledIf('repeatthisbooking', 'startendtimeknown', 'notchecked');
            $mform->addElement('text', 'howmanytimestorepeat',
                        get_string('howmanytimestorepeat', 'mod_booking'));
            $mform->setType('howmanytimestorepeat', PARAM_INT);
            $mform->setDefault('howmanytimestorepeat', 1);
            $mform->disabledIf('howmanytimestorepeat', 'startendtimeknown', 'notchecked');
            $mform->disabledIf('howmanytimestorepeat', 'repeatthisbooking', 'notchecked');
            $howoften = [
                86400 => get_string('day'),
                604800 => get_string('week'),
                2592000 => get_string('month')
            ];
            $mform->addElement('select', 'howoftentorepeat', get_string('howoftentorepeat', 'mod_booking'),
                        $howoften);
            $mform->setType('howoftentorepeat', PARAM_INT);
            $mform->setDefault('howoftentorepeat', 86400);
            $mform->disabledIf('howoftentorepeat', 'startendtimeknown', 'notchecked');
            $mform->disabledIf('howoftentorepeat', 'repeatthisbooking', 'notchecked');
        }

        // Templates - only visible when adding new.
        if (has_capability('mod/booking:manageoptiontemplates', $this->_customdata['context'])
            && $this->_customdata['optionid'] < 1) {

            // Workaround: Only show, if it is not turned off in the option form config.
            // We currently need this, because hideIf does not work with headers.
            // In expert mode, we do not hide anything.
            if ($formmode == 'expert' ||
                !isset($optionformconfig['templateheader']) || $optionformconfig['templateheader'] == 1) {
                $mform->addElement('header', 'templateheader',
                    get_string('addastemplate', 'mod_booking'));
            }

            $numberoftemplates = $DB->count_records('booking_options', array('bookingid' => 0));

            if ($numberoftemplates < 1 || wb_payment::is_currently_valid_licensekey()) {
                $addastemplate = array(
                        0 => get_string('notemplate', 'mod_booking'),
                        1 => get_string('asglobaltemplate', 'mod_booking')
                );
                $mform->addElement('select', 'addastemplate', get_string('addastemplate', 'mod_booking'),
                        $addastemplate);
                $mform->setType('addastemplate', PARAM_INT);
                $mform->setDefault('addastemplate', 0);
            } else {
                $mform->addElement('static', 'nolicense', get_string('licensekeycfg', 'mod_booking'),
                    get_string('licensekeycfgdesc', 'mod_booking'));
            }
        }

        // Hide all elements which have been removed in the option form config.
        // Only do this, if the form mode is set to 'simple'. In expert mode we do not hide anything.
        if ($formmode == 'simple' && $cfgelements = $DB->get_records('booking_optionformconfig')) {
            foreach ($cfgelements as $cfgelement) {
                if ($cfgelement->active == 0) {
                    $mform->addElement('hidden', 'cfg_' . $cfgelement->elementname, (int) $cfgelement->active);
                    $mform->setType('cfg_' . $cfgelement->elementname, PARAM_INT);
                    $mform->hideIf($cfgelement->elementname, 'cfg_' . $cfgelement->elementname, 'eq', 0);
                }
            }
        }

        // Buttons.
        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton',
                get_string('submitandgoback', 'mod_booking'));
        $buttonarray[] = &$mform->createElement("submit", 'submittandaddnew',
                get_string('submitandaddnew', 'mod_booking'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
        $mform->closeHeaderBefore('buttonar');

        $PAGE->requires->js_call_amd('mod_booking/optionstemplateselect', 'init');
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

        if (isset($data['pollurl']) && strlen($data['pollurl']) > 0) {
            if (!filter_var($data['pollurl'], FILTER_VALIDATE_URL)) {
                $errors['pollurl'] = get_string('entervalidurl', 'mod_booking');
            }
        }

        if (isset($data['pollurlteachers']) && strlen($data['pollurlteachers']) > 0) {
            if (!filter_var($data['pollurlteachers'], FILTER_VALIDATE_URL)) {
                $errors['pollurlteachers'] = get_string('entervalidurl', 'mod_booking');
            }
        }

        $groupname = $data['bookingname'] . ' - ' . $data['text'];

      /*  if (isset($data['courseid'])) {
            $groupid = groups_get_group_by_name($data['courseid'], $groupname);
            if ($groupid && $data['optionid'] == 0) {
                $errors['text'] = get_string('groupexists', 'mod_booking');
            }
        }*/

        if (isset($data['identifier'])) {
            $sql = "SELECT id FROM {booking_options} WHERE id <> :optionid AND identifier = :identifier";
            $params = ['optionid' => $data['optionid'], 'identifier' => $data['identifier']];
            if ($DB->get_records_sql($sql, $params)) {
                $errors['identifier'] = get_string('error:identifierexists', 'mod_booking');
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
                    $defaultvalues->$customfieldname = explode("\n", (isset($defaultvalues->$customfieldname) ?
                        $defaultvalues->$customfieldname : ''));
                }
            }
        }

        $defaultvalues->description = array('text' => (isset($defaultvalues->description) ?
            $defaultvalues->description : ''), 'format' => FORMAT_HTML);

        $defaultvalues->notificationtext = array('text' => (isset($defaultvalues->notificationtext) ?
            $defaultvalues->notificationtext : ''), 'format' => FORMAT_HTML);

        $defaultvalues->beforebookedtext = array('text' => (isset($defaultvalues->beforebookedtext) ?
            $defaultvalues->beforebookedtext : ''), 'format' => FORMAT_HTML);

        $defaultvalues->beforecompletedtext = array('text' => (isset($defaultvalues->beforecompletedtext) ?
            $defaultvalues->beforecompletedtext : ''), 'format' => FORMAT_HTML);

        $defaultvalues->aftercompletedtext = array('text' => (isset($defaultvalues->aftercompletedtext) ?
            $defaultvalues->aftercompletedtext : ''), 'format' => FORMAT_HTML);

        $defaultvalues->annotation = array('text' => (isset($defaultvalues->annotation) ?
            $defaultvalues->annotation : ''), 'format' => FORMAT_HTML);

        if (isset($defaultvalues->bookingclosingtime) && $defaultvalues->bookingclosingtime) {
            $defaultvalues->restrictanswerperiod = "checked";
        }
        if (isset($defaultvalues->coursestarttime) && $defaultvalues->coursestarttime) {
            $defaultvalues->startendtimeknown = "checked";
        }

        $draftitemid = file_get_submitted_draft_itemid('myfilemanageroption');
        file_prepare_draft_area($draftitemid, $this->_customdata['context']->id, 'mod_booking', 'myfilemanageroption',
            $this->_customdata['optionid'], array('subdirs' => false, 'maxfiles' => 50, 'accepted_types' => array('*'),
                'maxbytes' => 0));
        $defaultvalues->myfilemanageroption = $draftitemid;

        $draftimageid = file_get_submitted_draft_itemid('bookingoptionimage');
        file_prepare_draft_area($draftimageid, $this->_customdata['context']->id, 'mod_booking', 'bookingoptionimage',
            $this->_customdata['optionid'], array('subdirs' => false, 'maxfiles' => 1, 'accepted_types' => array('image', '.webp'),
                'maxbytes' => 0));
        $defaultvalues->bookingoptionimage = $draftimageid;

        if (isset($defaultvalues->optionid) && $defaultvalues->optionid > 0) {
            // Defaults for customfields.
            $cfdefaults = $DB->get_records('booking_customfields', array('optionid' => $defaultvalues->optionid));
            if (!empty($cfdefaults)) {
                foreach ($cfdefaults as $defaultval) {
                    $cfgvalue = $defaultval->cfgname;
                    $defaultvalues->$cfgvalue = $defaultval->value;
                }
            }
        }

        // To handle costumfields correctly.
        // We use instanceid for optionid.
        // But cf always uses the id key. we can't override it completly though.
        // Therefore, we change it to optionid just for the defaultvalues creation.
        if (isset($defaultvalues->id) && isset($defaultvalues->optionid)) {
            $handler = booking_handler::create();
            $id = $defaultvalues->id;
            $defaultvalues->id = $defaultvalues->optionid;
            $handler->instance_form_before_set_data($defaultvalues);
            if (class_exists('local_entities\entitiesrelation_handler')) {
                $erhandler = new entitiesrelation_handler('bookingoption');
                $erhandler->instance_form_before_set_data($this->_form, $defaultvalues, $defaultvalues->optionid);
            }
            $defaultvalues->id = $id;
        }

        parent::set_data($defaultvalues);
    }

    public function get_data() {
        $data = parent::get_data();

        if ($data) {

            // Data from editors needs special treatment.

            if (isset($data->description)) {
                $data->descriptionformat = $data->description['format'];
                $data->description = $data->description['text'];
            } else {
                $data->descriptionformat = 0;
                $data->description = '';
            }

            if (isset($data->notificationtext)) {
                $data->notificationtextformat = $data->notificationtext['format'];
                $data->notificationtext = $data->notificationtext['text'];
            } else {
                $data->notificationtextformat = 0;
                $data->notificationtext = '';
            }

            if (isset($data->beforebookedtext)) {
                $data->beforebookedtext = $data->beforebookedtext['text'];
            } else {
                $data->beforebookedtext = '';
            }

            if (isset($data->beforecompletedtext)) {
                $data->beforecompletedtext = $data->beforecompletedtext['text'];
            } else {
                $data->beforecompletedtext = '';
            }

            if (isset($data->aftercompletedtext)) {
                $data->aftercompletedtext = $data->aftercompletedtext['text'];
            } else {
                $data->aftercompletedtext = '';
            }

            if (isset($data->annotation)) {
                $data->annotation = $data->annotation['text'];
            } else {
                $data->annotation = '';
            }
        }

        $handler = booking_handler::create();
        $handler->instance_form_validation((array)$data, []);
        return $data;
    }

    /**
     *
     * Show the booking information to edit
     *
     * @param bool $entity
     */
    public function get_customfieldcategories(booking_handler $handler) {
        $categories = $handler->get_categories_with_fields();
        foreach ($categories as $category) {
            $name = $category->get('name');
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            // Mabye use later: $id = $category->get('id'); END.
            $categorynames[$name] = $name;
        }
        if (count($categorynames) == 0) {
            return ['not category yet'];
        }

        return $categorynames;
    }

}
