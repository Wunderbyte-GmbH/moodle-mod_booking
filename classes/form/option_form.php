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

/**
 * Option form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;
use mod_booking\output\eventslist;

defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/formslib.php");

use mod_booking\utils\wb_payment;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\customfield\booking_handler;
use mod_booking\price;
use mod_booking\singleton_service;
use local_entities\entitiesrelation_handler;
use local_entities\local\entities\entitydate;
use mod_booking\bo_actions\actions_info;
use mod_booking\bo_availability\bo_info;
use mod_booking\subbookings\subbookings_info;
use mod_booking\option\dates_handler;
use mod_booking\elective;
use mod_booking\teachers_handler;
use moodle_url;
use moodleform;
use stdClass;

class option_form extends moodleform {

    /** @var bool $formmode 'simple' or 'expert' */
    public $formmode = null;

    public function definition() {
        global $CFG, $COURSE, $DB, $PAGE, $OUTPUT;

        /* At first get the option form configuration from DB.
        Unfortunately, we need this, because hideIf does not work with
        editors, headers and html elements. */
        $optionformconfig = [];
        if ($optionformconfigrecords = $DB->get_records('booking_optionformconfig')) {
            foreach ($optionformconfigrecords as $optionformconfigrecord) {
                $optionformconfig[$optionformconfigrecord->elementname] = $optionformconfigrecord->active;
            }
        }

        // Get the form mode, which can be 'simple' or 'expert'.
        if (isset($this->_customdata['formmode'])) {
            // Formmode can also be set via custom data.
            // Currently we only need this for the optionformconfig...
            // ...which needs to be set to 'expert', so it shows all checkboxes.
            $this->formmode = $this->_customdata['formmode'];
        } else {
            // Normal case: we get formmode from user preferences.
            $this->formmode = get_user_preferences('optionform_mode');
        }

        if (empty($this->formmode)) {
            // Default: Simple mode.
            $this->formmode = 'simple';
        }

        $mform = & $this->_form;

        $cmid = 0;
        $optionid = 0;
        if (isset($this->_customdata['cmid'])) {
            $cmid = $this->_customdata['cmid'];
            $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
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
        $optiontemplates = ['' => ''];
        $alloptiontemplates = $DB->get_records('booking_options', ['bookingid' => 0], '', $fields = 'id, text', 0, 0);

        // If there is no license key and there is more than one template, we only use the first one.
        if (count($alloptiontemplates) > 1 && !wb_payment::pro_version_is_activated()) {
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
        $mform->addElement('text', 'identifier', get_string('optionidentifier', 'mod_booking'), ['size' => '10']);
        $mform->addRule('identifier', get_string('required'), 'required', null, 'client');
        $mform->addRule('identifier', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('identifier', PARAM_TEXT);
        $mform->addHelpButton('identifier', 'optionidentifier', 'mod_booking');

        // By default, a random identifier will be generated.
        $randomidentifier = booking_option::create_truly_unique_option_identifier();

        $mform->setDefault('identifier', $randomidentifier);

        // Prefix to be shown before the title.
        $mform->addElement('text', 'titleprefix', get_string('titleprefix', 'mod_booking'), ['size' => '10']);
        $mform->addRule('titleprefix', get_string('maximumchars', '', 10), 'maxlength', 10, 'client');
        $mform->setType('titleprefix', PARAM_TEXT);
        $mform->addHelpButton('titleprefix', 'titleprefix', 'mod_booking');
        if (!empty($bookingoptionsettings)) {
            $mform->setDefault('titleprefix', $bookingoptionsettings->titleprefix);
        }

        // Booking option name.
        $mform->addElement('text', 'text', get_string('bookingoptionname', 'mod_booking'), ['size' => '64']);
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
                        ['size' => '64']);
                        $mform->setType($customfieldname, PARAM_NOTAGS);
                        break;
                    case 'select':
                        $soptions = explode("\n", $customfieldarray['options']);
                        $soptionselements = [];
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
                        $soptionselements = [];
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
            1 => get_string('optioninvisible', 'mod_booking'),
        ];
        $mform->addElement('select', 'invisible', get_string('optionvisibility', 'mod_booking'), $visibilityoptions);
        $mform->setType('invisible', PARAM_INT);
        $mform->setDefault('invisible', 0);
        $mform->addHelpButton('invisible', 'optionvisibility', 'mod_booking');

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['description']) || $optionformconfig['description'] == 1) {
            $mform->addElement('editor', 'description', get_string('description'));
            $mform->setType('description', PARAM_CLEANHTML);
        }

        // Internal annotation.
        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['annotation']) || $optionformconfig['annotation'] == 1) {
            $mform->addElement('editor', 'annotation', get_string('optionannotation', 'mod_booking'));
            $mform->setType('annotation', PARAM_CLEANHTML);
            $mform->addHelpButton('annotation', 'optionannotation', 'mod_booking');
        }

        // Location.
        $sql = 'SELECT DISTINCT location FROM {booking_options} ORDER BY location';
        $locationarray = $DB->get_fieldset_sql($sql);

        $locationstrings = [];
        foreach ($locationarray as $item) {
            $locationstrings[$item] = $item;
        }

        $options = [
                'noselectionstring' => get_string('donotselectlocation', 'mod_booking'),
                'tags' => true,
        ];
        $mform->addElement('autocomplete', 'location', get_string('location', 'mod_booking'), $locationstrings, $options);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('location', PARAM_TEXT);
        } else {
            $mform->setType('location', PARAM_CLEANHTML);
        }
        $mform->addHelpButton('location', 'location', 'mod_booking');

        // Institution.
        $sql = 'SELECT DISTINCT institution FROM {booking_options} ORDER BY institution';
        $institutionarray = $DB->get_fieldset_sql($sql);

        $institutionstrings = [];
        foreach ($institutionarray as $item) {
            $institutionstrings[$item] = $item;
        }

        $options = [
                'noselectionstring' => get_string('donotselectinstitution', 'mod_booking'),
                'tags' => true,
        ];
        $mform->addElement('autocomplete', 'institution',
            get_string('institution', 'mod_booking'), $institutionstrings, $options);
        $mform->addHelpButton('institution', 'institution', 'mod_booking');

        $mform->addElement('text', 'address', get_string('address', 'mod_booking'),
                ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('address', PARAM_TEXT);
        } else {
            $mform->setType('address', PARAM_CLEANHTML);
        }

        // Upload an image for the booking option.
        $mform->addElement('filemanager',
                        'bookingoptionimage',
                        get_string('bookingoptionimage', 'mod_booking'),
                        null,
                        ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1, 'accepted_types' => ['image']]
                    );

        $mform->addElement('checkbox', 'limitanswers', get_string('limitanswers', 'mod_booking'));
        $mform->addHelpButton('limitanswers', 'limitanswers', 'mod_booking');

        $mform->addElement('text', 'maxanswers', get_string('maxparticipantsnumber', 'mod_booking'));
        $mform->setType('maxanswers', PARAM_INT);
        $mform->disabledIf('maxanswers', 'limitanswers', 'notchecked');

        if (!get_config('booking', 'turnoffwaitinglist')) {
            $mform->addElement('text', 'maxoverbooking', get_string('maxoverbooking', 'mod_booking'));
            $mform->setType('maxoverbooking', PARAM_INT);
            $mform->disabledIf('maxoverbooking', 'limitanswers', 'notchecked');
        }

        $mform->addElement('text', 'minanswers', get_string('minanswers', 'mod_booking'));
        $mform->setType('minanswers', PARAM_INT);
        $mform->setDefault('minanswers', 0);

        $coursearray = [];
        $coursearray[0] = get_string('donotselectcourse', 'mod_booking');
        $totalcount = 1;
        // TODO: Using  moodle/course:viewhiddenactivities is not 100% accurate for finding teacher/non-editing teacher at least.
        $allcourses = get_courses_search([], 'c.shortname ASC', 0, 9999999,
            $totalcount, ['enrol/manual:enrol']);

        $coursearray[-1] = get_string('newcourse', 'booking');
        foreach ($allcourses as $id => $courseobject) {
            $coursearray[$id] = $courseobject->shortname;
        }
        $options = [
            'noselectionstring' => get_string('donotselectcourse', 'mod_booking'),
        ];
        $mform->addElement('autocomplete', 'courseid', get_string("choosecourse", "booking"), $coursearray, $options);
        $mform->addHelpButton('courseid', 'choosecourse', 'mod_booking');

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
            '', ['group' => 1], [2, 0]);
        $mform->setType('enrolmentstatus', PARAM_INT);
        $mform->setDefault('enrolmentstatus', 2);
        $mform->addHelpButton('enrolmentstatus', 'enrolmentstatus', 'mod_booking');
        $mform->disabledIf('enrolmentstatus', 'startendtimeknown', 'notchecked');

        $mform->addElement('date_time_selector', 'courseendtime',
            get_string("courseendtime", "booking"));
        $mform->setType('courseendtime', PARAM_INT);
        $mform->disabledIf('courseendtime', 'startendtimeknown', 'notchecked');

        // Add to course calendar dropdown.
        $caleventtypes = [
            0 => get_string('caldonotadd', 'mod_booking'),
            1 => get_string('caladdascourseevent', 'mod_booking'),
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

        $mform->addElement('text', 'pollurl', get_string('bookingpollurl', 'mod_booking'), ['size' => '64']);
        $mform->setType('pollurl', PARAM_TEXT);
        $mform->addHelpButton('pollurl', 'feedbackurl', 'mod_booking');

        $mform->addElement('text', 'pollurlteachers',
                get_string('bookingpollurlteachers', 'mod_booking'), ['size' => '64']);
        $mform->setType('pollurlteachers', PARAM_TEXT);
        $mform->addHelpButton('pollurlteachers', 'feedbackurlteachers', 'mod_booking');

        $mform->addElement('text', 'howmanyusers', get_string('bookotheruserslimit', 'mod_booking'), 0);
        $mform->addRule('howmanyusers', get_string('err_numeric', 'form'), 'numeric', null, 'client');
        $mform->setType('howmanyusers', PARAM_INT);

        $mform->addElement('text', 'removeafterminutes', get_string('removeafterminutes', 'mod_booking'),
                0);
        $mform->addRule('removeafterminutes', get_string('err_numeric', 'form'), 'numeric', null, 'client');
        $mform->setType('removeafterminutes', PARAM_INT);

        $mform->addElement('filemanager',
                        'myfilemanageroption',
                        get_string('bookingattachment', 'mod_booking'),
                        null,
                        ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 50, 'accepted_types' => ['*']]
                        );

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
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

        // Add teachers.
        $teacherhandler = new teachers_handler($optionid);
        $teacherhandler->add_to_mform($mform);

        // Responsible contact person.
        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['responsiblecontactheader']) || $optionformconfig['responsiblecontactheader'] == 1) {
            // Advanced options.
            $mform->addElement('header', 'responsiblecontactheader', get_string('responsiblecontact', 'mod_booking'));
        }
        // Responsible contact person - autocomplete.
        $options = [
            'ajax' => 'mod_booking/form_users_selector',
            'multiple' => false,
            'noselectionstring' => get_string('choose...', 'mod_booking'),
            'valuehtmlcallback' => function($value) {
                global $OUTPUT;
                $user = singleton_service::get_instance_of_user((int)$value);
                if (!$user || !user_can_view_profile($user)) {
                    return false;
                }
                $details = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                ];
                return $OUTPUT->render_from_template(
                        'mod_booking/form-user-selector-suggestion', $details);
            },
        ];
        $mform->addElement('autocomplete', 'responsiblecontact',
            get_string('responsiblecontact', 'mod_booking'), [], $options);
        $mform->addHelpButton('responsiblecontact', 'responsiblecontact', 'mod_booking');

        // Add price.
        $price = new price('option', $this->_customdata['optionid']);
        $price->add_price_to_mform($mform);

        // If the form is no elective, and we can pay with credits, we can actually use this.
        if (!$booking->is_elective() && $booking->uses_credits()) {
            $mform->addElement('text', 'credits', get_string('credits', 'mod_booking'));
            $mform->setType('credits', PARAM_INT);
        }

        // Add entities.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            $erhandler->instance_form_definition($mform, $optionid, $this->formmode);

            // This checkbox is specific to mod_booking which is why it...
            // ...cannot be put directly into instance_form_definition of entitiesrelation_handler.
            $mform->addElement('advcheckbox', 'er_saverelationsforoptiondates',
                get_string('er_saverelationsforoptiondates', 'local_entities'));
            if ($optionid == 0) {
                // If it's a new option, we set the default to checked.
                $mform->setDefault('er_saverelationsforoptiondates', 1);
            } else {
                // If we edit an existing option, we do not check by default.
                $mform->setDefault('er_saverelationsforoptiondates', 0);
            }
        }

        // Add custom fields.
        $handler = booking_handler::create();
        $handler->instance_form_definition($mform, $optionid);

        // TODO: expert/simple mode needs to work with this too!
        // Add availability conditions.
        bo_info::add_conditions_to_mform($mform, $optionid, $this);

        // TODO: expert/simple mode needs to work with this too!
        // Add subbookings options.
        subbookings_info::add_subbookings_to_mform($mform, $this->_customdata);

        // Add elective mform elements..
        elective::instance_option_form_definition($mform, $this->_customdata);

        // Actions are not yet finished - so we hide them for now.
        // Add booking actions mform elements.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        actions_info::add_actions_to_mform($mform, $this->_customdata);

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['advancedoptions']) || $optionformconfig['advancedoptions'] == 1) {
            // Advanced options.
            $mform->addElement('header', 'advancedoptions', get_string('advancedoptions', 'mod_booking'));
        }

        $mform->addElement('advcheckbox', 'disablebookingusers', get_string('disablebookingusers', 'mod_booking'));
        $mform->setType('disablebookingusers', PARAM_INT);

        $mform->addElement('advcheckbox', 'disablecancel', get_string('disablecancel', 'mod_booking'));
        $mform->setType('disablecancel', PARAM_INT);
        $mform->setDefault('disablecancel', (int) booking_option::get_value_of_json_by_key($optionid, "disablecancel"));

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['notificationtext']) || $optionformconfig['notificationtext'] == 1) {
            $mform->addElement('editor', 'notificationtext', get_string('notificationtext', 'mod_booking'));
            $mform->setType('notificationtext', PARAM_CLEANHTML);
        }

        $mform->addElement('text', 'shorturl', get_string('shorturl', 'mod_booking'),
                ['size' => '1333']);
        $mform->setType('shorturl', PARAM_TEXT);
        $mform->disabledIf('shorturl', 'optionid', 'eq', -1);

        // Google URL shortener does not work anymore - let's remove this in the future.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $mform->addElement('checkbox', 'generatenewurl', get_string('generatenewurl', 'mod_booking'));
        $mform->disabledIf('generatenewurl', 'optionid', 'eq', -1); */

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['bookingoptiontextheader']) || $optionformconfig['bookingoptiontextheader'] == 1) {
            // Booking option text.
            $mform->addElement('header', 'bookingoptiontextheader',
                    get_string('textdependingonstatus', 'mod_booking'));
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['beforebookedtext']) || $optionformconfig['beforebookedtext'] == 1) {
            $mform->addElement('editor', 'beforebookedtext', get_string("beforebookedtext", "booking"),
                    null, null);
            $mform->setType('beforebookedtext', PARAM_CLEANHTML);
            $mform->addHelpButton('beforebookedtext', 'beforebookedtext', 'mod_booking');
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['beforecompletedtext']) || $optionformconfig['beforecompletedtext'] == 1) {
            $mform->addElement('editor', 'beforecompletedtext',
                    get_string("beforecompletedtext", "booking"), null, null);
            $mform->setType('beforecompletedtext', PARAM_CLEANHTML);
            $mform->addHelpButton('beforecompletedtext', 'beforecompletedtext', 'mod_booking');
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($this->formmode == 'expert' ||
            !isset($optionformconfig['aftercompletedtext']) || $optionformconfig['aftercompletedtext'] == 1) {
            $mform->addElement('editor', 'aftercompletedtext',
                    get_string("aftercompletedtext", "booking"), null, null);
            $mform->setType('aftercompletedtext', PARAM_CLEANHTML);
            $mform->addHelpButton('aftercompletedtext', 'aftercompletedtext', 'mod_booking');
        }

        // Templates and recurring 'events' - only visible when adding new.
        if ($this->_customdata['optionid'] == -1) {

            // Workaround: Only show, if it is not turned off in the option form config.
            // We currently need this, because hideIf does not work with headers.
            // In expert mode, we do not hide anything.
            if ($this->formmode == 'expert' ||
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
                2592000 => get_string('month'),
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
            if ($this->formmode == 'expert' ||
                !isset($optionformconfig['templateheader']) || $optionformconfig['templateheader'] == 1) {
                $mform->addElement('header', 'templateheader',
                    get_string('addastemplate', 'mod_booking'));
            }

            $numberoftemplates = $DB->count_records('booking_options', ['bookingid' => 0]);

            if ($numberoftemplates < 1 || wb_payment::pro_version_is_activated()) {
                $addastemplate = [
                        0 => get_string('notemplate', 'mod_booking'),
                        1 => get_string('asglobaltemplate', 'mod_booking'),
                ];
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
        if ($this->formmode == 'simple' && $cfgelements = $DB->get_records('booking_optionformconfig')) {
            foreach ($cfgelements as $cfgelement) {
                if ($cfgelement->active == 0) {
                    $mform->addElement('hidden', 'cfg_' . $cfgelement->elementname, (int) $cfgelement->active);
                    $mform->setType('cfg_' . $cfgelement->elementname, PARAM_INT);
                    $mform->hideIf($cfgelement->elementname, 'cfg_' . $cfgelement->elementname, 'eq', 0);
                }
            }
        }

        // Buttons.
        $buttonarray = [];
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton',
                get_string('submitandgoback', 'mod_booking'));
        $buttonarray[] = &$mform->createElement("submit", 'submitandadd',
                get_string('submitandadd', 'mod_booking'));
        $buttonarray[] = &$mform->createElement("submit", 'submitandstay',
            get_string('submitandstay', 'mod_booking'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');

        $data = new eventslist(
            $optionid,
            ['\mod_booking\event\bookingoption_updated']
        );

        $html = $OUTPUT->render_from_template('mod_booking/eventslist', $data);
        $mform->addElement('static', 'eventslist', '', $html);

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

        if (isset($data['identifier'])) {
            $sql = "SELECT id FROM {booking_options} WHERE id <> :optionid AND identifier = :identifier";
            $params = ['optionid' => $data['optionid'], 'identifier' => $data['identifier']];
            if ($DB->get_records_sql($sql, $params)) {
                $errors['identifier'] = get_string('error:identifierexists', 'mod_booking');
            }
        }

        if (class_exists('local_entities\entitiesrelation_handler')) {
            // If we have the handler, we need first to add the new optiondates to the form.
            // This constant change between object and array is stupid, but comes from the mform handler.
            $fromform = (object)$data;

            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            self::order_all_dates_to_book_in_form($fromform);
            $erhandler->instance_form_validation((array)$fromform, $errors);
        }

        // Price validation.
        if ($data["useprice"] == 1) {
            $pricecategories = $DB->get_records_sql("SELECT * FROM {booking_pricecategories} WHERE disabled = 0");
            foreach ($pricecategories as $pricecategory) {
                // Check for negative prices, they are not allowed.
                if (isset($data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"]) &&
                    $data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"] < 0) {
                    $errors["pricegroup_$pricecategory->identifier"] =
                        get_string('error:negativevaluenotallowed', 'mod_booking');
                }
                // If checkbox to use prices is turned on, we do not allow empty strings as prices!
                if (isset($data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"]) &&
                    $data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"] === "") {
                    $errors["pricegroup_$pricecategory->identifier"] =
                        get_string('error:pricemissing', 'mod_booking');
                }
            }
        }

        $cfhandler = booking_handler::create();
        $errors = array_merge($errors, $cfhandler->instance_form_validation($data, $files));

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

        $defaultvalues->description = [
                        'text' => (isset($defaultvalues->description) ? $defaultvalues->description : ''),
                        'format' => FORMAT_HTML,
                        ];

        $defaultvalues->notificationtext = [
                        'text' => (isset($defaultvalues->notificationtext) ? $defaultvalues->notificationtext : ''),
                        'format' => FORMAT_HTML,
                        ];

        $defaultvalues->beforebookedtext = [
                        'text' => (isset($defaultvalues->beforebookedtext) ? $defaultvalues->beforebookedtext : ''),
                        'format' => FORMAT_HTML,
                        ];

        $defaultvalues->beforecompletedtext = [
                        'text' => (isset($defaultvalues->beforecompletedtext) ? $defaultvalues->beforecompletedtext : ''),
                        'format' => FORMAT_HTML,
                        ];

        $defaultvalues->aftercompletedtext = [
                        'text' => (isset($defaultvalues->aftercompletedtext) ? $defaultvalues->aftercompletedtext : ''),
                        'format' => FORMAT_HTML,
                        ];

        $defaultvalues->annotation = [
                        'text' => (isset($defaultvalues->annotation) ? $defaultvalues->annotation : ''),
                        'format' => FORMAT_HTML,
                        ];

        if (isset($defaultvalues->bookingclosingtime) && $defaultvalues->bookingclosingtime) {
            $defaultvalues->restrictanswerperiodclosing = "checked";
        }
        if (isset($defaultvalues->bookingopeningtime) && $defaultvalues->bookingopeningtime) {
            $defaultvalues->restrictanswerperiodopening = "checked";
        }
        if (isset($defaultvalues->coursestarttime) && $defaultvalues->coursestarttime) {
            $defaultvalues->startendtimeknown = "checked";
        }

        $draftitemid = file_get_submitted_draft_itemid('myfilemanageroption');
        file_prepare_draft_area($draftitemid,
                                $this->_customdata['context']->id,
                                'mod_booking', 'myfilemanageroption',
                                $this->_customdata['optionid'],
                                ['subdirs' => false, 'maxfiles' => 50, 'accepted_types' => ['*'], 'maxbytes' => 0]
                            );
        $defaultvalues->myfilemanageroption = $draftitemid;

        // If we duplicated, we already have an image file with an itemid.
        if (!empty($defaultvalues->copyoptionid) && $filefromdb = $DB->get_record_sql("SELECT *
            FROM {files}
            WHERE component = 'mod_booking'
            AND filearea = 'bookingoptionimage'
            AND filesize > 0
            AND mimetype LIKE 'image%'
            AND itemid = :optionid", ['optionid' => $defaultvalues->copyoptionid])) {

            $draftimageid = file_get_submitted_draft_itemid('bookingoptionimage');
            file_prepare_draft_area($draftimageid,
                                    $filefromdb->contextid,
                                    'mod_booking',
                                    'bookingoptionimage',
                                    $defaultvalues->copyoptionid,
                                    ['subdirs' => false, 'maxfiles' => 1, 'accepted_types' => ['image', '.webp'], 'maxbytes' => 0]
                                );
            $defaultvalues->bookingoptionimage = $draftimageid;
        } else {
            // If an image has already been saved before, load it.
            $draftimageid = file_get_submitted_draft_itemid('bookingoptionimage');
            file_prepare_draft_area($draftimageid,
                                    $this->_customdata['context']->id,
                                    'mod_booking',
                                    'bookingoptionimage',
                                    $this->_customdata['optionid'],
                                    ['subdirs' => false, 'maxfiles' => 1, 'accepted_types' => ['image', '.webp'], 'maxbytes' => 0]
                                );
            $defaultvalues->bookingoptionimage = $draftimageid;
        }

        if (isset($defaultvalues->optionid) && $defaultvalues->optionid > 0) {
            // Defaults for teachers.
            $teacherhandler = new teachers_handler($defaultvalues->optionid);
            $teacherhandler->instance_form_before_set_data($this->_form);

            // Defaults for customfields.
            $cfdefaults = $DB->get_records('booking_customfields', ['optionid' => $defaultvalues->optionid]);
            if (!empty($cfdefaults)) {
                foreach ($cfdefaults as $defaultval) {
                    $cfgvalue = $defaultval->cfgname;
                    $defaultvalues->$cfgvalue = $defaultval->value;
                }
            }

            // Defaults for availability conditions.
            if ($acdefaultsjson = $DB->get_field('booking_options', 'availability',
                ['id' => $defaultvalues->optionid])) {
                $acdefaults = (array) json_decode($acdefaultsjson);
                foreach ($acdefaults as $acdefault) {
                    if (!empty($acdefault->class)) {
                        $condition = new $acdefault->class;
                        $condition->set_defaults($defaultvalues, $acdefault);
                    }
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
                $erhandler = new entitiesrelation_handler('mod_booking', 'option');
                $erhandler->instance_form_before_set_data($this->_form, $defaultvalues, $defaultvalues->optionid);
            }
            $defaultvalues->id = $id;
        }

        elective::option_form_set_data($defaultvalues);

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

            // Ensure further php 8.1 compatibility.
            if (isset($data->pollurl)) {
                $data->pollurl = trim($data->pollurl);
            }
        }

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

    /**
     * Helper function to explode strings to array of starttime & endtime.
     *
     * @param [type] $array
     * @return array
     */
    private static function return_timestamps($array):array {

        $returnarray = [];
        foreach ($array as $date) {
            list($startime, $endtime) = explode('-', $date);
            $returnarray[] = [
                'starttime' => $startime,
                'endtime' => $endtime,
            ];
        }
        return $returnarray;
    }

    /**
     * This function creates the entitydate instances in an array under the datestobook key.
     * The entitiesrelation handlers we use to validate and save expects a certain structure.
     *
     * @param stdClass $fromform
     * @return void
     */
    private static function order_all_dates_to_book_in_form(stdClass &$fromform) {
        dates_handler::add_values_from_post_to_form($fromform);

        // For the form validation, we need to pass the values to book in a special form.
        // We only need those timestamps which are new.
        // But it might be advisable to also check the key stillexistingdates in the future.
        $datestobook = self::return_timestamps(array_merge($fromform->newoptiondates, $fromform->stillexistingdates));

        $fromform->datestobook = [];

        $link = new moodle_url('/mod/booking/view.php', [
            'optionid' => $fromform->optionid,
            'id' => booking_option::get_cmid_from_optionid($fromform->optionid),
            'whichview' => 'showonlyone',
        ]);

        foreach ($datestobook as $date) {

            $fromform->datestobook[] = new entitydate(
                $fromform->optionid ?? 0,
                'mod_booking',
                'optiondate',
                $fromform->text,
                $date['starttime'],
                $date['endtime'],
                1,
                $link);
        }

        // If there are no date to book (no optiondates)...
        // ... we need to take into account the single dates.
        if (count($fromform->datestobook) < 1) {

            $fromform->datestobook[] = new entitydate(
                $fromform->optionid ?? 0,
                'mod_booking',
                'optiondate',
                $fromform->text,
                $fromform->coursestarttime,
                $fromform->courseendtime,
                1,
                $link);
        }
    }

}
