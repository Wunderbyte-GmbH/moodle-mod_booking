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
 * Module setup form
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_wunderbyte_table\local\customfield\wbt_field_controller_info;
use mod_booking\booking;
use mod_booking\customfield\booking_handler;
use mod_booking\elective;
use mod_booking\output\eventslist;
use mod_booking\placeholders\placeholders_info;
use mod_booking\semester;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle booking module setup form
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_mod_form extends moodleform_mod {
    /**
     * $options
     *
     * @var array
     */
    public $options = [];

    /**
     * Return an array of categories catid as key and categoryname as value
     *
     * @param int $catid
     * @param string $dashes
     * @param array $options
     *
     * @return array of course category names indexed by category id
     */
    public function show_sub_categories($catid, $dashes = '', $options = []) {
        global $DB;
        $dashes .= '&nbsp;&nbsp;';
        $categories = $DB->get_records('booking_category', ['cid' => $catid]);
        if (count((array) $categories) > 0) {
            foreach ($categories as $category) {
                $options[$category->id] = $dashes . $category->name;
                $options = $this->show_sub_categories($category->id, $dashes, $options);
            }
        }

        return $options;
    }

    /**
     * Add completion rules.
     *
     * @return array
     *
     */
    public function add_completion_rules() {
        $mform = & $this->_form;

        $group = [];
        $group[] = & $mform->createElement(
            'checkbox',
            'enablecompletionenabled',
            '',
            get_string('completionoptioncompletedform', 'mod_booking')
        );
        $group[] = $mform->createElement(
            'text',
            'enablecompletion',
            get_string('completionoptioncompletedform', 'mod_booking'),
            ['size' => '1']
        );

        $mform->addGroup(
            $group,
            'enablecompletiongroup',
            get_string('enablecompletiongroup', 'mod_booking'),
            [' '],
            false
        );
        $mform->disabledIf('enablecompletion', 'enablecompletionenabled', 'notchecked');
        $mform->setDefault('enablecompletion', 1);
        $mform->setType('enablecompletion', PARAM_INT);
        $mform->addHelpButton('enablecompletiongroup', 'enablecompletion', 'mod_booking');

        return ['enablecompletiongroup'];
    }

    /**
     * Completion rule enabled.
     *
     * @param array $data
     *
     * @return bool
     *
     */
    public function completion_rule_enabled($data) {
        return !empty($data['enablecompletion'] != 0);
    }

    /**
     *
     * {@inheritDoc}
     * @see moodleform_mod::definition()
     */
    public function definition() {
        global $CFG, $DB, $COURSE, $USER, $PAGE, $OUTPUT;

        $systemcontext = context_system::instance();
        $coursecontext = context_course::instance($COURSE->id);
        // phpcs:ignore
        // $modulecontext = context_module::instance($this->_cm->id);

        $isproversion = wb_payment::pro_version_is_activated();

        $mform = &$this->_form;

        $bookingid = (int)$this->_instance;
        if (!empty($bookingid)) {
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        }

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $bookininstancetemplates = ['' => ''];
        $bookinginstances = $DB->get_records('booking_instancetemplate', [], '', 'id, name', 0, 0);

        foreach ($bookinginstances as $key => $value) {
            $bookininstancetemplates[$value->id] = $value->name;
        }

        $mform->addElement(
            'select',
            'instancetemplateid',
            get_string('populatefromtemplate', 'mod_booking'),
            $bookininstancetemplates
        );

        // Name of Booking instance.
        $mform->addElement(
            'text',
            'name',
            get_string('bookingname', 'mod_booking'),
            ['size' => '64']
        );
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Get the possible views.
        $viewparamoptions = booking::get_array_of_possible_views();

        // Default view param (0...List view, 1...Cards view).
        $mform->addElement(
            'select',
            'viewparam',
            get_string('viewparam', 'mod_booking'),
            $viewparamoptions
        );
        $mform->setType('viewparam', PARAM_INT);
        $mform->setDefault(
            'viewparam',
            (int)booking::get_value_of_json_by_key($bookingid, 'viewparam') ?? MOD_BOOKING_VIEW_PARAM_LIST
        );

        if ($isproversion) {
            $mform->addElement(
                'advcheckbox',
                'switchtemplates',
                get_string('switchtemplates', 'mod_booking')
            );
            $mform->addHelpButton('switchtemplates', 'switchtemplates', 'mod_booking');
            $mform->setType('switchtemplates', PARAM_INT);
            $mform->setDefault(
                'switchtemplates',
                (int)booking::get_value_of_json_by_key($bookingid, 'switchtemplates') ?? 0
            );

            // Options for the switchtemplates selection autocomplete.
            $swtopts = [
                'noselectionstring' => get_string('choose...', 'mod_booking'),
                'tags' => true,
                'multiple' => true,
            ];
            $mform->addElement(
                'autocomplete',
                'switchtemplatesselection',
                get_string('switchtemplatesselection', 'mod_booking'),
                $viewparamoptions,
                $swtopts
            );
            $mform->addHelpButton('switchtemplatesselection', 'switchtemplatesselection', 'mod_booking');
            $switchtemplatesselection = (array)booking::get_value_of_json_by_key($bookingid, 'switchtemplatesselection');
            if (empty($switchtemplatesselection)) {
                $switchtemplatesselection = array_keys($viewparamoptions);
            }
            $mform->setDefault('switchtemplatesselection', $switchtemplatesselection);
            $mform->hideIf('switchtemplatesselection', 'switchtemplates', 'neq', 1);
        } else {
            // No PRO version.
            $mform->addElement('html', '<div class="mb-3" style="margin-left: 13rem;">' . get_string('badge:pro', 'mod_booking') .
                " <span class='small'>" . get_string('proversion:extraviews', 'mod_booking') . '</span></div>');
        }

        // Choose semester.
        $semestersarray = semester::get_semesters_id_name_array();
        if (!empty($semestersarray)) {
            $semesteridoptions = [
                'tags' => false,
                'multiple' => false,
            ];
            $mform->addElement(
                'autocomplete',
                'semesterid',
                get_string('choosesemester', 'mod_booking'),
                $semestersarray,
                $semesteridoptions
            );
            $mform->setType('semesterid', PARAM_INT);
            $mform->setDefault('semesterid', semester::get_semester_with_highest_id());
        }

        // Event type.
        $sql = 'SELECT DISTINCT eventtype FROM {booking} ORDER BY eventtype';
        $eventtypearray = $DB->get_fieldset_sql($sql);

        $eventstrings = [];
        foreach ($eventtypearray as $item) {
            $eventstrings[$item] = $item;
        }
        $options = [
                'noselectionstring' => get_string('noeventtypeselected', 'mod_booking'),
                'tags' => true,
        ];
        $mform->addElement('autocomplete', 'eventtype', get_string('eventtype', 'mod_booking'), $eventstrings, $options);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('eventtype', PARAM_TEXT);
        } else {
            $mform->setType('eventtype', PARAM_CLEANHTML);
        }
        $mform->addRule('eventtype', null, 'required', null, 'client');
        $mform->addHelpButton('eventtype', 'eventtype', 'mod_booking');

        $this->standard_intro_elements(get_string('bookingtext', 'mod_booking'));

        $mform->addElement(
            'text',
            'duration',
            get_string('bookingduration', 'mod_booking'),
            ['size' => '64']
        );
        $mform->setType('duration', PARAM_TEXT);

        $mform->addElement('text', 'points', get_string('bookingpoints', 'mod_booking'), 0);
        $mform->setType('points', PARAM_FLOAT);

        $teachers = get_enrolled_users($coursecontext, 'mod/booking:addinstance');

        $teachersstring = [];
        foreach ($teachers as $item) {
            $teachersstring[$item->id] = "$item->firstname $item->lastname";
        }

        $options = [
                'tags' => true,
        ];
        $mform->addElement(
            'autocomplete',
            'organizatorname',
            get_string('organizatorname', 'mod_booking'),
            $teachersstring,
            $options
        );
        $mform->setType('organizatorname', PARAM_RAW);
        $mform->addHelpButton('organizatorname', 'organizatorname', 'mod_booking');

        $mform->addElement(
            'text',
            'pollurl',
            get_string('bookingpollurl', 'mod_booking'),
            ['size' => '64']
        );
        $mform->setType('pollurl', PARAM_TEXT);
        $mform->addHelpButton('pollurl', 'feedbackurl', 'mod_booking');

        $mform->addElement(
            'text',
            'pollurlteachers',
            get_string('bookingpollurlteachers', 'mod_booking'),
            ['size' => '64']
        );
        $mform->setType('pollurlteachers', PARAM_TEXT);
        $mform->addHelpButton('pollurlteachers', 'feedbackurlteachers', 'mod_booking');

        $mform->addElement(
            'filemanager',
            'myfilemanager',
            get_string('bookingattachment', 'mod_booking'),
            null,
            ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 50, 'accepted_types' => ['*']]
        );

        $whichviewopts = [
            'showall' => get_string('showallbookingoptions', 'mod_booking'),
            'mybooking' => get_string('showmybookingsonly', 'mod_booking'),
            'myoptions' => get_string('optionsiteach', 'mod_booking'),
            'optionsiamresponsiblefor' => get_string('optionsiamresponsiblefor', 'mod_booking'),
            'showactive' => get_string('activebookingoptions', 'mod_booking'),
            'myinstitution' => get_string('myinstitution', 'mod_booking'),
            'showvisible' => get_string('visibleoptions', 'mod_booking'),
            'showinvisible' => get_string('invisibleoptions', 'mod_booking'),
        ];

        if ($isproversion) {
            // Some tabs are only available in PRO version.
            $whichviewopts['showfieldofstudy'] = get_string('showmyfieldofstudyonly', 'mod_booking');
            $whichviewopts['showwhatsnew'] = get_string('whatsnew', 'mod_booking');
        }

        // View selections to show on booking options overview.
        $options = [
            'multiple' => true,
        ];
        $mform->addElement(
            'autocomplete',
            'showviews',
            get_string('showviews', 'mod_booking'),
            $whichviewopts,
            $options
        );
        $mform->setType('showviews', PARAM_TAGLIST);
        $defaults = array_keys($whichviewopts);
        $mform->setDefault('showviews', $defaults);

        // Default view for option overview.
        $mform->addElement(
            'select',
            'whichview',
            get_string('whichview', 'mod_booking'),
            $whichviewopts
        );
        $mform->setType('whichview', PARAM_TAGLIST);

        // Select default sort column for options overview.
        $sortposibilities = [];
        $sortposibilities['coursestarttime'] = get_string('optiondatestart', 'mod_booking');
        $sortposibilities['titleprefix'] = get_string('titleprefix', 'mod_booking');
        $sortposibilities['text'] = get_string('bookingoptionnamewithoutprefix', 'mod_booking');
        $sortposibilities['location'] = get_string('location', 'mod_booking');
        $sortposibilities['institution'] = get_string('institution', 'mod_booking');
        $mform->addElement(
            'select',
            'defaultoptionsort',
            get_string('sortby'),
            $sortposibilities
        );
        $mform->setDefault('defaultoptionsort', 'text');

        // Select default sort order.
        $sortorderoptions = [
            'asc' => get_string('sortorder:asc', 'mod_booking'),
            'desc' => get_string('sortorder:desc', 'mod_booking'),
        ];
        $mform->addElement(
            'select',
            'defaultsortorder',
            get_string('sortorder', 'mod_booking'),
            $sortorderoptions
        );
        $mform->setDefault('defaultsortorder', 'asc');

        // Choose default template.
        $alloptiontemplates = $DB->get_records_menu('booking_options', ['bookingid' => 0], '', $fields = 'id, text', 0, 0);
        $alloptiontemplates[0] = get_string('dontusetemplate', 'mod_booking');
        $mform->addElement(
            'select',
            'templateid',
            get_string('defaulttemplate', 'mod_booking'),
            $alloptiontemplates
        );
        $mform->setDefault('templateid', 0);

        // Choose if extra info should be shown right on the course page.
        // Note: "List on course page" is no longer supported.
        $listoncoursepageoptions = [];
        $listoncoursepageoptions[0] = get_string('hidelistoncoursepage', 'mod_booking');
        $listoncoursepageoptions[1] = get_string('showcoursenameandbutton', 'mod_booking');
        $mform->addElement(
            'select',
            'showlistoncoursepage',
            get_string('showlistoncoursepage', 'mod_booking'),
            $listoncoursepageoptions
        );
        if (!empty($bookingsettings)) {
            $mform->setDefault('showlistoncoursepage', (int)$bookingsettings->showlistoncoursepage);
        } else {
            $mform->setDefault('showlistoncoursepage', 0); // List on course page is turned off by default for new instances.
        }
        $mform->addHelpButton('showlistoncoursepage', 'showlistoncoursepage', 'mod_booking');
        $mform->setType('showlistoncoursepage', PARAM_INT);

        $mform->addElement(
            'textarea',
            'coursepageshortinfo',
            get_string('coursepageshortinfolbl', 'mod_booking'),
            'wrap="virtual" rows="5" cols="65"'
        );
        $mform->setDefault('coursepageshortinfo', get_string('coursepageshortinfo', 'mod_booking'));
        $mform->addHelpButton('coursepageshortinfo', 'coursepageshortinfolbl', 'mod_booking');
        $mform->setType('coursepageshortinfo', PARAM_TEXT);
        // Hide short info for the first two options.
        $mform->hideIf('coursepageshortinfo', 'showlistoncoursepage', 'in', [0]);

        // Booking manager.
        $contextbooking = $this->get_context();
        $choosepotentialmanager = [];
        $potentials[$USER->id] = $USER;
        $potentials1 = get_users_by_capability(
            $contextbooking,
            'mod/booking:readresponses',
            'u.id, u.firstname, u.lastname, u.username, u.email'
        );
        $potentials2 = get_users_by_capability(
            $contextbooking,
            'moodle/course:update',
            'u.id, u.firstname, u.lastname, u.username, u.email'
        );
        $potentialmanagers = array_merge($potentials1, $potentials2, $potentials);

        // Before creating the array, we have to check if there is a booking manager already set.
        // If so, but the user has left the course, an arbitrary value will be shown. Therefore we add the...
        // ... existing bookingmanager to the array.
        if (
            ((int)$this->_instance)
            && ($existingmanager = $DB->get_field('booking', 'bookingmanager', ['id' => $this->_instance]))
        ) {
            if ($existinguser = $DB->get_record('user', ['username' => $existingmanager])) {
                $found = false;
                foreach ($potentialmanagers as $user) {
                    if ($user->id == $existinguser->id) {
                        $found = true;
                    }
                }
                if (!$found) {
                    $potentialmanagers = array_merge($potentialmanagers, [$existinguser]);
                }
            }
        }

        foreach ($potentialmanagers as $potentialmanager) {
            $choosepotentialmanager[$potentialmanager->username] = $potentialmanager->firstname
                    . ' ' . $potentialmanager->lastname . ' (' .
            $potentialmanager->email . ')';
        }
        $mform->addElement(
            'autocomplete',
            'bookingmanager',
            get_string('usernameofbookingmanager', 'mod_booking'),
            $choosepotentialmanager
        );
        $mform->addHelpButton('bookingmanager', 'usernameofbookingmanager', 'mod_booking');
        $mform->setType('bookingmanager', PARAM_TEXT);
        $mform->setDefault('bookingmanager', $USER->username);
        $mform->addRule('bookingmanager', null, 'required', null, 'client');

        // Configure fields and columns section.
        $mform->addElement('header', 'configurefields', get_string('configurefields', 'mod_booking'));

        $tmpaddfields = $DB->get_records('user_info_field', []);

        $responsesfields = [
            'completed' => get_string('completed', 'mod_booking'),
            'status' => get_string('presence', 'mod_booking'),
            'rating' => get_string('rating', 'core_rating'),
            'numrec' => get_string('numrec', 'mod_booking'),
            'places' => get_string('places', 'mod_booking'),
            'fullname' => get_string('fullname', 'mod_booking'),
            'timecreated' => get_string('timecreated', 'mod_booking'),
            'institution' => get_string('institution', 'mod_booking'),
            'waitinglist' => get_string('searchwaitinglist', 'mod_booking'),
            'city' => new lang_string('city'),
            'department' => new lang_string('department'),
            'notes' => get_string('notes', 'mod_booking'),
            'userpic' => get_string('userpic'),
            'indexnumber' => get_string('indexnumber', 'mod_booking'),
            'email' => get_string('email', 'mod_booking'),
            'certificate' => get_string('certificate', 'mod_booking'),
            'allusercertificates' => get_string('allusercertificates', 'mod_booking'),
        ];

        $reportfields = [ // This is the download file.
            'optionid' => get_string("optionid", "mod_booking"),
            'booking' => get_string("bookingoptionname", "mod_booking"),
            'institution' => get_string("institution", "mod_booking"),
            'location' => get_string("location", "mod_booking"),
            'coursestarttime' => get_string("coursestarttime", "mod_booking"),
            'city' => new lang_string('city'),
            'department' => new lang_string('department'),
            'courseendtime' => get_string("courseendtime", "mod_booking"),
            'numrec' => get_string("numrec", "mod_booking"), 'userid' => get_string("userid", "mod_booking"),
            'username' => get_string("username"), 'firstname' => get_string("firstname"),
            'lastname' => get_string("lastname"), 'email' => get_string("email"),
            'completed' => get_string("completed", "mod_booking"),
            'waitinglist' => get_string("waitinglist", "mod_booking"),
            'status' => get_string('presence', 'mod_booking'),
            'groups' => get_string("group"),
            'notes' => get_string('notes', 'mod_booking'),
            'idnumber' => get_string("idnumber"),
            'timecreated' => get_string('timecreated', 'mod_booking'),
        ];

        $optionsfields = [
            'description' => get_string('description', 'mod_booking'),
            'statusdescription' => get_string('textdependingonstatus', 'mod_booking'),
            'teacher' => get_string('teachers', 'mod_booking'),
            'responsiblecontact' => get_string('responsiblecontact', 'mod_booking'),
            'attachment' => get_string('bookingattachment', 'mod_booking'),
            'showdates' => get_string('dates', 'mod_booking'),
            'dayofweektime' => get_string('dayofweektime', 'mod_booking'),
            'location' => get_string('location', 'mod_booking'),
            'institution' => get_string('institution', 'mod_booking'),
            'minanswers' => get_string('minanswers', 'mod_booking'),
            'bookingopeningtime' => get_string('bookingopeningtime', 'mod_booking'),
            'bookingclosingtime' => get_string('bookingclosingtime', 'mod_booking'),
            'coursestarttime' => get_string('optiondatestart', 'mod_booking'),
        ];

        if (get_config('booking', 'usecompetencies')) {
            $optionsfields['competencies'] = get_string('competencies', 'mod_booking');
        }
        $optionsdownloadfields = [
            'identifier' => get_string('optionidentifier', 'mod_booking'),
            'titleprefix' => get_string('titleprefix', 'mod_booking'),
            'text' => get_string('bookingoption', 'mod_booking'),
            'description' => get_string('description', 'mod_booking'),
            'teacher' => get_string('teachers', 'mod_booking'),
            'responsiblecontact' => get_string('responsiblecontact', 'mod_booking'),
            'showdates' => get_string('dates', 'mod_booking'),
            'dayofweektime' => get_string('dayofweektime', 'mod_booking'),
            'location' => get_string('location', 'mod_booking'),
            'institution' => get_string('institution', 'mod_booking'),
            'course' => get_string('course', 'core'),
            'courseshortname' => get_string('courseshortname', 'mod_booking'),
            'minanswers' => get_string('minanswers', 'mod_booking'),
            'bookings' => get_string('bookings', 'mod_booking'),
            'bookingopeningtime' => get_string('bookingopeningtime', 'mod_booking'),
            'bookingclosingtime' => get_string('bookingclosingtime', 'mod_booking'),
            'places' => get_string('places', 'mod_booking'),
            'invisible' => get_string('visibilitystatus', 'mod_booking'),
        ];

        if (class_exists('local_shopping_cart\shopping_cart')) {
            $reportfields['price'] = get_string('price', 'mod_booking');
            $responsesfields['price'] = get_string('price', 'mod_booking');
            $optionsdownloadfields['price'] = get_string('price', 'mod_booking');
        }

        $signinsheetfields = [
            'fullname' => get_string('fullname', 'mod_booking'),
            'firstname' => get_string('firstname'),
            'lastname' => get_string('lastname'),
            'institution' => get_string('institution', 'mod_booking'),
            'description' => new lang_string('description'),
            'city' => new lang_string('city'),
            'country' => new lang_string('country'),
            'idnumber' => new lang_string('idnumber'),
            'email' => new lang_string('email'),
            'phone1' => new lang_string('phone1'),
            'department' => new lang_string('department'),
            'address' => new lang_string('address'),
            'role' => new lang_string('role'),
            'userpic' => get_string('userpic'),
            'places' => get_string('places', 'mod_booking'),
            'timecreated' => get_string('bookingdate', 'mod_booking'),
            'signature' => get_string('signature', 'mod_booking'),
        ];

        for ($i = 1; $i < 4; $i++) {
            $name = 'signinextracols' . $i;
            $visiblename = get_string('signinextracols', 'mod_booking') . " $i";
            $signinsheetfields[$name] = $visiblename;
        }

        foreach ($tmpaddfields as $field) {
            $responsesfields[$field->shortname] = format_string($field->name);
            $reportfields[$field->shortname] = format_string($field->name);
            $signinsheetfields[$field->shortname] = format_string($field->name);
        }

        // Fields for booking option overview.
        $options = [
            'multiple' => true,
            'tags' => false,
            'noselectionstring' => get_string('optionspagefields', 'mod_booking'),
        ];
        $mform->addElement(
            'autocomplete',
            'optionsfields',
            get_string('optionspagefields', 'mod_booking'),
            $optionsfields,
            $options
        );
        $defaults = array_keys($optionsfields);
        $mform->setDefault('optionsfields', $defaults);

        // Custom fields to be shown on detail page (optionview.php).
        $customfields = booking_handler::get_customfields();
        $customfieldshortnames = [];
        if (!empty($customfields)) {
            foreach ($customfields as $cf) {
                $name = format_string($cf->name);
                $customfieldshortnames[$cf->shortname] = "$name ($cf->shortname)";
            }
            $mform->addElement(
                'select',
                'customfieldsforfilter',
                get_string('customfieldsforfilter', 'mod_booking'),
                $customfieldshortnames
            );
            $mform->getElement('customfieldsforfilter')->setMultiple(true);
            $preset = (array)booking::get_value_of_json_by_key($bookingid, 'customfieldsforfilter') ?? [];
            $mform->setDefault('customfieldsforfilter', array_keys($preset));
        }

        // Fields for download of booking option overview.
        $options = [
            'multiple' => true,
            'tags' => false,
            'noselectionstring' => get_string('optionsdownloadfields', 'mod_booking'),
        ];
        $optionsdownloadfields = array_merge($optionsdownloadfields, $customfieldshortnames);
        $mform->addElement(
            'autocomplete',
            'optionsdownloadfields',
            get_string('optionsdownloadfields', 'mod_booking'),
            $optionsdownloadfields,
            $options
        );
        $defaults = array_keys($optionsdownloadfields);
        $mform->setDefault('optionsdownloadfields', $defaults);

        // Fields on manage responses page.
        $options = [
                        'multiple' => true,
                        'tags' => false,
                        'noselectionstring' => get_string('manageresponsespagefields', 'mod_booking'),
        ];
        $mform->addElement(
            'autocomplete',
            'responsesfields',
            get_string('manageresponsespagefields', 'mod_booking'),
            $responsesfields,
            $options
        );
        $defaults = array_keys($responsesfields);
        $mform->setDefault('responsesfields', $defaults);

        // Fields for downloads of responses.
        $options = [
                        'multiple' => true,
                        'tags' => false,
                        'noselectionstring' => get_string('manageresponsesdownloadfields', 'mod_booking'),
        ];
        $mform->addElement(
            'autocomplete',
            'reportfields',
            get_string('manageresponsesdownloadfields', 'mod_booking'),
            $reportfields,
            $options
        );
        $defaults = array_keys($reportfields);
        $mform->setDefault('reportfields', $defaults);

        // Fields for sign-in sheet.
        $options = [
                        'multiple' => true,
                        'tags' => false,
                        'noselectionstring' => get_string('signinsheetfields', 'mod_booking'),
        ];
        $mform->addElement(
            'autocomplete',
            'signinsheetfields',
            get_string('signinsheetfields', 'mod_booking'),
            $signinsheetfields,
            $options
        );
        $defaults = array_keys($signinsheetfields);
        $mform->setDefault('signinsheetfields', $defaults);

        // Upload general images which need to have the same name as the value of a certain customfield.
        // These images will be used as a fallback for each option which has no image of its own.
        $mform->addElement(
            'header',
            'uploadheaderimages',
            get_string('uploadheaderimages', 'mod_booking')
        );

        $customfieldsrecords = booking_handler::get_customfields();
        $customfieldsarray = [];
        foreach ($customfieldsrecords as $customfieldsrecord) {
            $customfieldsarray[$customfieldsrecord->id] = $customfieldsrecord->name . ' (' . $customfieldsrecord->shortname . ')';
        }

        $mform->addElement(
            'autocomplete',
            'bookingimagescustomfield',
            get_string('bookingimagescustomfield', 'mod_booking'),
            $customfieldsarray,
            ['tags' => false]
        );

        $mform->addElement(
            'filemanager',
            'bookingimages',
            get_string('bookingimages', 'mod_booking'),
            null,
            ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 500, 'accepted_types' => ['image']]
        );

        // Confirmation message.
        if (get_config('booking', 'uselegacymailtemplates')) {
            $mform->addElement(
                'header',
                'emailsettings',
                get_string('emailsettings', 'mod_booking')
            );

            $url = new moodle_url('/mod/booking/edit_rules.php');
            $linktorules = $url->out();
            $mform->addElement('html', get_string('helptext:emailsettings', 'mod_booking', $linktorules));

            $mform->addElement('advcheckbox', 'sendmail', get_string("activatemails", "mod_booking"));

            $mform->addElement(
                'advcheckbox',
                'copymail',
                get_string("copymail", "mod_booking")
            );

            $mform->addElement(
                'advcheckbox',
                'sendmailtobooker',
                get_string('sendmailtobooker', 'mod_booking')
            );
            $mform->addHelpButton('sendmailtobooker', 'sendmailtobooker', 'mod_booking');

            $mform->addElement('text', 'daystonotify', get_string('daystonotify', 'mod_booking'));
            $mform->setType('daystonotify', PARAM_INT);
            $mform->setDefault('daystonotify', 0);
            $mform->addHelpButton('daystonotify', 'daystonotify', 'mod_booking');

            $mform->addElement('text', 'daystonotify2', get_string('daystonotify2', 'mod_booking'));
            $mform->setType('daystonotify2', PARAM_INT);
            $mform->setDefault('daystonotify2', 0);
            $mform->addHelpButton('daystonotify2', 'daystonotify', 'mod_booking');

            $mform->addElement('text', 'daystonotifyteachers', get_string('daystonotifyteachers', 'mod_booking'));
            $mform->setDefault('daystonotifyteachers', 0);
            $mform->addHelpButton('daystonotifyteachers', 'daystonotify', 'mod_booking');
            $mform->setType('daystonotifyteachers', PARAM_INT);

            $mailtemplatessource = [];
            $mailtemplatessource[0] = get_string('mailtemplatesinstance', 'mod_booking');
            $mailtemplatessource[1] = get_string('mailtemplatesglobal', 'mod_booking');
            $mform->addElement(
                'select',
                'mailtemplatessource',
                get_string('mailtemplatessource', 'mod_booking'),
                $mailtemplatessource
            );
            $mform->setDefault('mailtemplatessource', 0); // Instance specific mail templates are the default.
            $mform->addHelpButton('mailtemplatessource', 'mailtemplatessource', 'mod_booking');

            $mform->setType('mailtemplatessource', PARAM_INT);

            // Placeholders info text.
            $placeholders = placeholders_info::return_list_of_placeholders();
            $mform->addElement('html', get_string('helptext:placeholders', 'mod_booking', $placeholders));

            // Add the fields to allow editing of the default text.
            $editoroptions = [
                'subdirs' => false,
                'maxfiles' => 0,
                'maxbytes' => 0,
                'trusttext' => false,
                'context' => $systemcontext,
            ];

            $fieldmapping = (object) ['status' => '{status}', 'participant' => '{participant}',
                'title' => '{title}', 'duration' => '{duration}', 'starttime' => '{starttime}',
                'endtime' => '{endtime}', 'startdate' => '{startdate}', 'enddate' => '{enddate}',
                'courselink' => '{courselink}', 'bookinglink' => '{bookinglink}',
                'location' => '{location}', 'institution' => '{institution}', 'address' => '{address}',
                'eventtype' => '{eventtype}', 'email' => '{email}', 'bookingdetails' => '{bookingdetails}',
                'gotobookingoption' => '{gotobookingoption}', 'changes' => '{changes}',
                'usercalendarurl' => '{usercalendarurl}', 'coursecalendarurl' => '{coursecalendarurl}',
                'numberparticipants' => '{numberparticipants}', 'numberwaitinglist' => '{numberwaitinglist}',
            ];

            $mform->addElement(
                'editor',
                'bookedtext',
                get_string('bookedtext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('bookedtextmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('bookedtext', $default);
            $mform->addHelpButton('bookedtext', 'placeholders', 'mod_booking');
            $mform->disabledIf('bookedtext', 'mailtemplatessource', 'eq', 1);

            $mform->addElement(
                'editor',
                'waitingtext',
                get_string('waitingtext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('waitingtextmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('waitingtext', $default);
            $mform->addHelpButton('waitingtext', 'placeholders', 'mod_booking');
            $mform->disabledIf('waitingtext', 'mailtemplatessource', 'eq', 1);

            $mform->addElement(
                'editor',
                'notifyemail',
                get_string('notifyemail', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('notifyemailmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('notifyemail', $default);
            $mform->addHelpButton('notifyemail', 'placeholders', 'mod_booking');
            $mform->disabledIf('notifyemail', 'mailtemplatessource', 'eq', 1);

            $default = [
                'text' => get_string('notifyemailteachersmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);

            $mform->addElement(
                'editor',
                'notifyemailteachers',
                get_string('notifyemailteachers', 'mod_booking'),
                null,
                $editoroptions
            );
            $mform->setDefault('notifyemailteachers', $default);
            $mform->addHelpButton('notifyemailteachers', 'placeholders', 'mod_booking');
            $mform->disabledIf('notifyemailteachers', 'mailtemplatessource', 'eq', 1);
            $mform->setType('notifyemailteachers', PARAM_RAW);

            $mform->addElement(
                'editor',
                'statuschangetext',
                get_string('statuschangetext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('statuschangetextmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('statuschangetext', $default);
            $mform->addHelpButton('statuschangetext', 'placeholders', 'mod_booking');
            $mform->disabledIf('statuschangetext', 'mailtemplatessource', 'eq', 1);

            $mform->addElement(
                'editor',
                'userleave',
                get_string('userleave', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('userleavemessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('userleave', $default);
            $mform->addHelpButton('userleave', 'placeholders', 'mod_booking');
            $mform->disabledIf('userleave', 'mailtemplatessource', 'eq', 1);

            $mform->addElement(
                'editor',
                'deletedtext',
                get_string('deletedtext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('deletedbookingusermessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('deletedtext', $default);
            $mform->addHelpButton('deletedtext', 'placeholders', 'mod_booking');
            $mform->disabledIf('deletedtext', 'mailtemplatessource', 'eq', 1);

            // Message to be sent when fields relevant for a booking option calendar entry (or ical) change.
            $mform->addElement(
                'editor',
                'bookingchangedtext',
                get_string('bookingchangedtext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('bookingchangedtextmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('bookingchangedtext', $default);
            $mform->addHelpButton('bookingchangedtext', 'bookingchangedtext', 'mod_booking');
            $mform->disabledIf('bookingchangedtext', 'mailtemplatessource', 'eq', 1);

            $mform->addElement(
                'editor',
                'pollurltext',
                get_string('pollurltext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('pollurltextmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('pollurltext', $default);
            $mform->addHelpButton('pollurltext', 'placeholders', 'mod_booking');
            $mform->disabledIf('pollurltext', 'mailtemplatessource', 'eq', 1);

            $mform->addElement(
                'editor',
                'pollurlteacherstext',
                get_string('pollurlteacherstext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('pollurlteacherstextmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('pollurlteacherstext', $default);
            $mform->addHelpButton('pollurlteacherstext', 'placeholders', 'mod_booking');
            $mform->disabledIf('pollurlteacherstext', 'mailtemplatessource', 'eq', 1);

            $mform->addElement(
                'editor',
                'activitycompletiontext',
                get_string('activitycompletiontext', 'mod_booking'),
                null,
                $editoroptions
            );
            $default = [
                'text' => get_string('activitycompletiontextmessage', 'mod_booking', $fieldmapping),
                'format' => FORMAT_HTML,
            ];
            $default['text'] = str_replace("\n", '<br/>', $default['text']);
            $mform->setDefault('activitycompletiontext', $default);
            $mform->addHelpButton('activitycompletiontext', 'placeholders', 'mod_booking');
            $mform->disabledIf('activitycompletiontext', 'mailtemplatessource', 'eq', 1);
        }
        // Booking and cancelling actions.
        $mform->addElement(
            'header',
            'bookingandcancelling',
            get_string('bookingandcancelling', 'mod_booking')
        );

        $mform->addElement('advcheckbox', 'allowupdate', get_string('allowbookingafterstart', 'mod_booking'));

        $mform->addElement('advcheckbox', 'disablecancel', get_string('disablecancelforinstance', 'mod_booking'));
        $mform->setType('disablecancel', PARAM_INT);
        $mform->setDefault('disablecancel', (int) booking::get_value_of_json_by_key((int) $bookingid, "disablecancel"));

        $mform->addElement('advcheckbox', 'cancancelbook', get_string('cancancelbookallow', 'mod_booking'));
        $mform->disabledIf('cancancelbook', 'disablecancel', 'eq', 1);

        $cancancelbookdaysstring = get_string('cancancelbookdays', 'mod_booking');
        $canceldependenton = get_config('booking', 'canceldependenton');
        switch ($canceldependenton) {
            case 'semesterstart':
                $cancancelbookdaysstring = get_string('cancancelbookdays:semesterstart', 'mod_booking');
                break;
            case 'bookingclosingtime':
                $cancancelbookdaysstring = get_string('cancancelbookdays:bookingclosingtime', 'mod_booking');
                break;
            case 'bookingopeningtime':
                $cancancelbookdaysstring = get_string('cancancelbookdays:bookingopeningtime', 'mod_booking');
                break;
            default:
                $cancancelbookdaysstring = get_string('cancancelbookdays:coursestarttime', 'mod_booking');
                $canceldependenton = 'coursestarttime';
                break;
        }

        // Cancel date is either absolute or relative to defined start.
        $strid = 'cdo:' . $canceldependenton;
        $a = get_string($strid, 'mod_booking');

        $canceloptions = [
            MOD_BOOKING_CANCANCELBOOK_ABSOLUTE => get_string('cancancelbookabsolute', 'mod_booking'),
            MOD_BOOKING_CANCANCELBOOK_RELATIVE => get_string('cancancelbookrelative', 'mod_booking', $a),
            MOD_BOOKING_CANCANCELBOOK_UNLIMITED => get_string('cancancelbookunlimited', 'mod_booking'),
        ];

        $mform->addElement(
            'select',
            'cancelrelativedate',
            get_string('cancancelbooksetting', 'mod_booking'),
            $canceloptions
        );
        $mform->addHelpButton('cancelrelativedate', 'cancancelbooksetting', 'mod_booking');

        $mform->hideIf('cancelrelativedate', 'cancancelbook', 'eq', 0);
        $mform->hideIf('cancelrelativedate', 'disablecancel', 'neq', 0);
        $mform->setDefault(
            'cancelrelativedate',
            (int)booking::get_value_of_json_by_key($bookingid, 'cancelrelativedate') ?? MOD_BOOKING_CANCANCELBOOK_RELATIVE
        );

        $mform->addElement('date_time_selector', 'allowupdatetimestamp', get_string('canceldateabsolute', 'mod_booking'));
        $mform->hideIf('allowupdatetimestamp', 'cancancelbook', 'eq', 0);
        $mform->hideIf('allowupdatetimestamp', 'cancelrelativedate', 'neq', MOD_BOOKING_CANCANCELBOOK_ABSOLUTE);
        $mform->setDefault(
            'allowupdatetimestamp',
            booking::get_value_of_json_by_key($bookingid, 'allowupdatetimestamp') ?? ''
        );

        $opts = [10000 => get_string('cancancelbookdaysno', 'mod_booking')];
        $extraopts = array_combine(range(-100, 100), range(-100, 100));
        $opts = $opts + $extraopts;
        $mform->addElement('select', 'allowupdatedays', $cancancelbookdaysstring, $opts);
        $mform->hideIf('allowupdatedays', 'cancelrelativedate', 'neq', MOD_BOOKING_CANCANCELBOOK_RELATIVE);

        $mform->setDefault('allowupdatedays', 10000); // One million means "no limit".
        $mform->disabledIf('allowupdatedays', 'cancancelbook', 'eq', 0);
        $mform->disabledIf('allowupdatedays', 'disablecancel', 'eq', 1);

        $mform->addElement('advcheckbox', 'disablebooking', get_string('disablebookingforinstance', 'mod_booking'));
        $mform->setType('disablebooking', PARAM_INT);
        $mform->setDefault('disablebooking', (int) booking::get_value_of_json_by_key((int) $bookingid, "disablebooking"));

        // Define maximum of options to be booked from a certain category, defined by tag.
        $enabled = get_config('booking', 'maxoptionsfromcategory') == 1;
        $field = get_config('booking', 'maxoptionsfromcategoryfield');
        if ($enabled && !empty($field)) {
            global $DB;
            $customfield = singleton_service::get_customfield_field_by_shortname($field);
            $customfieldname = format_string($customfield->name);
            $mform->addElement(
                'text',
                'maxoptionsfromcategorycount',
                get_string('maxoptionsfromcategorycount', 'mod_booking', $customfieldname),
                0
            );
            $savedsettings = booking::get_value_of_json_by_key($bookingid, 'maxoptionsfromcategory') ?? '';
            if (!empty($savedsettings)) {
                $savedsettings = (array)json_decode($savedsettings);
                $fieldnames = [];
                foreach ($savedsettings as $fieldname => $savedsetting) {
                    $fieldnames[] = $fieldname;
                    $count = $savedsetting->count;
                }
                // Count is stored in each of the classes, so we just take the first one.
                $mform->setDefault('maxoptionsfromcategorycount', $savedsetting->count);
            } else {
                $mform->setDefault('maxoptionsfromcategorycount', 0);
            }
            $mform->setType('maxoptionsfromcategorycount', PARAM_INT);

            $fieldcontroller = wbt_field_controller_info::get_instance_by_shortname($field);

            $records = $fieldcontroller->get_values_array();
            // Extract values into a clean array.
            $options = [
                '' => get_string('choosedots'),
            ];
            foreach ($records as $id => $record) {
                if (isset($record->data)) {
                    $record = $record->data;
                }
                if (
                    (empty($record) && $record !== "0")
                    || in_array($record, $options)
                ) {
                    continue;
                }

                $options[$id] = format_string($record);
            }

            $mform->addElement(
                'select',
                'maxoptionsfromcategoryvalue',
                get_string('maxoptionsfromcategoryvalue', 'mod_booking', $customfieldname),
                $options
            );
            $mform->getElement('maxoptionsfromcategoryvalue')->setMultiple(true);
            if (!empty($savedsettings)) {
                $mform->setDefault('maxoptionsfromcategoryvalue', array_keys($savedsettings));
            };
            $mform->hideIf('maxoptionsfromcategoryvalue', 'maxoptionsfromcategorycount', 'eq', 0);

            $mform->addElement('advcheckbox', 'maxoptionsfrominstance', get_string('maxoptionsfrominstance', 'mod_booking'));
            $maxoptionsfrominstancesetting = booking::get_value_of_json_by_key($bookingid, 'maxoptionsfrominstance') ?? 1;
            $mform->setDefault('maxoptionsfrominstance', $maxoptionsfrominstancesetting);
            $mform->hideIf('maxoptionsfrominstance', 'maxoptionsfromcategorycount', 'eq', 0);
        }

        $mform->addElement(
            'advcheckbox',
            'circumventavailabilityconditions',
            get_string('circumventavailabilityconditions', 'mod_booking'),
        );
        $mform->addElement(
            'static',
            'circumventavailabilityconditionsdesc',
            '',
            get_string('circumventavailabilityconditions_desc', 'mod_booking')
        );
        $mform->hideIf('circumventavailabilityconditionsdesc', 'circumventavailabilityconditions', 'notchecked');

        $mform->addElement(
            'text',
            'circumventpassword',
            get_string('circumventpassword', 'mod_booking'),
            ''
        );
        $mform->setType('circumventpassword', PARAM_TEXT);
        $mform->hideIf('circumventpassword', 'circumventavailabilityconditions', 'notchecked');

        $circumventcond = booking::get_value_of_json_by_key($bookingid, 'circumventcond') ?? [];
        if (empty($circumventcond)) {
            $mform->setDefault(
                'circumventavailabilityconditions',
                0,
            );
        } else {
            $mform->setDefault(
                'circumventavailabilityconditions',
                1,
            );
            $mform->setDefault(
                'circumventpassword',
                $circumventcond->cvpwd ?? '',
            );
        }

        // Miscellaneous settings.
        $mform->addElement(
            'header',
            'miscellaneoussettingshdr',
            get_string('advancedoptions', 'mod_booking')
        );

        $mform->addElement(
            'editor',
            'bookingpolicy',
            get_string("bookingpolicy", "mod_booking"),
            null,
            null
        );
        $mform->setType('bookingpolicy', PARAM_CLEANHTML);

        $mform->addElement('advcheckbox', 'autoenrol', get_string('autoenrol', 'mod_booking'));
        $mform->setDefault('autoenrol', 1);
        $mform->addHelpButton('autoenrol', 'autoenrol', 'mod_booking');

        $mform->addElement('advcheckbox', 'addtogroup', get_string('addtogroup', 'mod_booking'));
        $mform->addHelpButton('addtogroup', 'addtogroup', 'mod_booking');
        $mform->hideIf('addtogroup', 'autoenrol', 'notchecked');

        $groupoptions = [
            MOD_BOOKING_ENROL_INTO_GROUP_OF_BOOKINGOPTION => get_string('addtogroupofcurrentcoursebookingoption', 'mod_booking'),
        ];
        $groups = groups_get_all_groups($COURSE->id);
        foreach ($groups as $id => $groupdata) {
            $groupoptions[$id] = $groupdata->name;
        };
        $enroltogroupselect = $mform->addElement(
            'select',
            'addtogroupofcurrentcourse',
            get_string('addtogroupofcurrentcourse', 'mod_booking'),
            $groupoptions
        );
        $enroltogroupselect->setMultiple(true);
        $mform->addHelpButton('addtogroupofcurrentcourse', 'addtogroupofcurrentcourse', 'mod_booking');
        $mform->setDefault(
            'addtogroupofcurrentcourse',
            booking::get_value_of_json_by_key($bookingid, 'addtogroupofcurrentcourse') ?? []
        );
        $mform->addElement(
            'advcheckbox',
            'unenrolfromgroupofcurrentcourse',
            get_string('unenrolfromgroupofcurrentcourse', 'mod_booking'),
        );

        $mform->setDefault(
            'unenrolfromgroupofcurrentcourse',
            booking::get_value_of_json_by_key($bookingid, 'unenrolfromgroupofcurrentcourse') ?? 1
        );

        $opts = [0 => get_string('unlimitedplaces', 'mod_booking')];
        $extraopts = array_combine(range(1, 100), range(1, 100));
        $opts = $opts + $extraopts;
        $mform->addElement('select', 'maxperuser', get_string('maxperuser', 'mod_booking'), $opts);
        $mform->setDefault('maxperuser', 0);
        $mform->addHelpButton('maxperuser', 'maxperuser', 'mod_booking');

        $mform->addElement('advcheckbox', 'showinapi', get_string("showinapi", "mod_booking"));

        $mform->addElement('advcheckbox', 'numgenerator', get_string("numgenerator", "mod_booking"));

        $mform->addElement('text', 'paginationnum', get_string('paginationnum', 'mod_booking'), 0);
        $mform->setDefault('paginationnum', MOD_BOOKING_PAGINATIONDEF);
        $mform->setType('paginationnum', PARAM_INT);

        $mform->addElement('text', 'banusernames', get_string('banusernames', 'mod_booking'), 0);
        $mform->setType('banusernames', PARAM_TEXT);
        $mform->addHelpButton('banusernames', 'banusernames', 'mod_booking');

        if ($COURSE->enablecompletion > 0) {
            $opts = [-1 => get_string('disable')];

            $result = $DB->get_records_sql(
                'SELECT cm.id, cm.course, cm.module, cm.instance, m.name
                FROM {course_modules} cm LEFT JOIN {modules} m ON m.id = cm.module WHERE cm.course = ?
                AND cm.completion > 0',
                [$COURSE->id]
            );

            foreach ($result as $r) {
                $dynamicactivitymodulesdata = $DB->get_record($r->name, ['id' => $r->instance]);
                if (!empty($dynamicactivitymodulesdata)) {
                    $opts[$r->id] = $dynamicactivitymodulesdata->name;
                }
            }

            $mform->addElement(
                'select',
                'completionmodule',
                get_string('completionmodule', 'mod_booking'),
                $opts
            );
            $mform->setDefault('completionmodule', -1);
            $mform->addHelpButton('completionmodule', 'completionmodule', 'mod_booking');
        } else {
            $mform->addElement('hidden', 'completionmodule', '-1');
        }
        $mform->setType('completionmodule', PARAM_INT);

        $options = [];

        $options[0] = "&nbsp;";
        $categories = $DB->get_records(
            'booking_category',
            ['course' => $COURSE->id, 'cid' => 0]
        );

        foreach ($categories as $category) {
            $options[$category->id] = $category->name;
            $subcategories = $DB->get_records(
                'booking_category',
                ['course' => $COURSE->id, 'cid' => $category->id]
            );
            $options = $this->show_sub_categories($category->id, '', $options);
        }

        $opts = [0 => get_string('nocomments', 'mod_booking'),
            1 => get_string('allcomments', 'mod_booking'),
            2 => get_string('enrolledcomments', 'mod_booking'),
            3 => get_string('completedcomments', 'mod_booking'),
        ];
        $mform->addElement('select', 'comments', get_string('comments', 'mod_booking'), $opts);
        $mform->setDefault('comments', 0);

        $opts = [0 => get_string('noratings', 'mod_booking'),
                        1 => get_string('allratings', 'mod_booking'),
                        2 => get_string('enrolledratings', 'mod_booking'),
                        3 => get_string('completedratings', 'mod_booking'),
        ];
        $mform->addElement('select', 'ratings', get_string('ratings', 'mod_booking'), $opts);
        $mform->setDefault('ratings', 0);

        $mform->addElement('advcheckbox', 'removeuseronunenrol', get_string("removeuseronunenrol", "mod_booking"));

        if (get_config('booking', 'conditionsoverwritingbillboard')) {
            $mform->addElement('advcheckbox', 'overwriteblockingwarnings', get_string("overwriteblockingwarnings", "mod_booking"));
            $mform->addElement(
                'textarea',
                'billboardtext',
                get_string("billboardtext", "mod_booking"),
                null,
                null
            );
            $mform->setDefault(
                'overwriteblockingwarnings',
                (int)booking::get_value_of_json_by_key($bookingid, 'overwriteblockingwarnings') ?? 0
            );
            $mform->setDefault(
                'billboardtext',
                booking::get_value_of_json_by_key($bookingid, 'billboardtext') ?? ''
            );
        }

        // Booking option text.
        $mform->addElement(
            'header',
            'bookingoptiontextheader',
            get_string('textdependingonstatus', 'mod_booking')
        );

        $mform->addElement(
            'editor',
            'beforecompletedtext',
            get_string("beforecompletedtext", "mod_booking"),
            null,
            null
        );
        $mform->setType('beforecompletedtext', PARAM_CLEANHTML);

        $mform->addElement(
            'editor',
            'aftercompletedtext',
            get_string("aftercompletedtext", "mod_booking"),
            null,
            null
        );
        $mform->setType('aftercompletedtext', PARAM_CLEANHTML);

        $mform->addElement(
            'editor',
            'beforebookedtext',
            get_string("beforebookedtext", "mod_booking"),
            null,
            null
        );
        $mform->setType('beforebookedtext', PARAM_CLEANHTML);

        // Sign-In Sheet Configuration.
        $mform->addElement('header', 'cfgsigninheader', get_string('cfgsignin', 'mod_booking'));

        $mform->addElement(
            'filemanager',
            'signinlogoheader',
            get_string('signinlogoheader', 'mod_booking'),
            null,
            ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1, 'accepted_types' => ['image']]
        );

        $mform->addElement(
            'filemanager',
            'signinlogofooter',
            get_string('signinlogofooter', 'mod_booking'),
            null,
            ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1, 'accepted_types' => ['image']]
        );

        // Teachers.
        $mform->addElement(
            'header',
            'teachers',
            get_string('teachers', 'mod_booking')
        );

        $teacherroleid = [0 => ''];
        $allrolenames = role_get_names();
        $assignableroles = get_roles_for_contextlevels(CONTEXT_COURSE);
        foreach ($allrolenames as $value) {
            if (in_array($value->id, $assignableroles)) {
                $teacherroleid[$value->id] = $value->localname;
            }
        }
        $mform->addElement('select', 'teacherroleid', get_string('teacherroleid', 'mod_booking'), $teacherroleid);
        $mform->setDefault('teacherroleid', 3);

        // Custom report templates.
        $mform->addElement(
            'header',
            'customreporttemplates',
            get_string('customreporttemplates', 'mod_booking')
        );

        $customreporttemplates = ['' => ''];
        $reporttemplatesdata = $DB->get_records('booking_customreport', ['course' => $COURSE->id], '', 'id, name', 0, 0);

        foreach ($reporttemplatesdata as $key => $value) {
            $customreporttemplates[$value->id] = $value->name;
        }
        $mform->addElement('select', 'customtemplateid', get_string('customreporttemplate', 'mod_booking'), $customreporttemplates);

        if ($isproversion) {
            $electivehandler = new elective();
            $electivehandler->instance_form_definition($mform);
        }

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*
        // Category.
        $mform->addElement('header', 'categoryheader', get_string('categoryheader', 'mod_booking'));

        $url = $CFG->wwwroot . '/mod/booking/categories.php';
        if (isset($COURSE->id)) {
            $url .= '?courseid=' . $COURSE->id;
        }

        $select = $mform->addElement('select', 'categoryid', get_string('categoryheader', 'mod_booking'),
                $options, array('size' => 15));
        $select->setMultiple(true);

        $mform->addElement('html',
                '<a target="_blank" href="' . $url . '">' . get_string('addcategory', 'mod_booking') .
                         '</a>');

        // Connected bookings.
        $mform->addElement('header', 'conectedbookingheader',
                get_string('connectedbooking', 'mod_booking'));

        $bookings = $DB->get_records('booking', array('course' => $COURSE->id));

        $opts = array(0 => get_string('notconectedbooking', 'mod_booking'));

        foreach ($bookings as $key => $value) {
            $opts[$value->id] = $value->name;
        }

        $mform->addElement('select', 'conectedbooking',
                get_string('connectedbooking', 'mod_booking'), $opts);
        $mform->setDefault('conectedbooking', 0);
        $mform->addHelpButton('conectedbooking', 'connectedbooking', 'mod_booking');

        // Custom labels.
        $mform->addElement('header', 'customlabels', get_string('customlabelsdeprecated', 'mod_booking'));

        $mform->addElement('text', 'btncacname', get_string('btncacname', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('btncacname', PARAM_TEXT);

        $mform->addElement('text', 'lblteachname', get_string('lblteachname', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblteachname', PARAM_TEXT);

        $mform->addElement('text', 'lblsputtname', get_string('lblsputtname', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblsputtname', PARAM_TEXT);

        $mform->addElement('text', 'btnbooknowname', get_string('btnbooknowname', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('btnbooknowname', PARAM_TEXT);

        $mform->addElement('text', 'btncancelname', get_string('btncancelname', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('btncancelname', PARAM_TEXT);

        $mform->addElement('text', 'lblbooking', get_string('lblbooking', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblbooking', PARAM_TEXT);

        $mform->addElement('text', 'lbllocation', get_string('lbllocation', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lbllocation', PARAM_TEXT);

        $mform->addElement('text', 'lblinstitution', get_string('lblinstitution', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblinstitution', PARAM_TEXT);

        $mform->addElement('text', 'lblname', get_string('lblname', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblname', PARAM_TEXT);

        $mform->addElement('text', 'lblsurname', get_string('lblsurname', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblsurname', PARAM_TEXT);

        $mform->addElement('text', 'booktootherbooking',
                get_string('lblbooktootherbooking', 'mod_booking'), array('size' => '64'));
        $mform->setType('booktootherbooking', PARAM_TEXT);

        $mform->addElement('text', 'lblacceptingfrom', get_string('lblacceptingfrom', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblacceptingfrom', PARAM_TEXT);

        $mform->addElement('text', 'lblnumofusers', get_string('lblnumofusers', 'mod_booking'),
                array('size' => '64'));
        $mform->setType('lblnumofusers', PARAM_TEXT);

        // Automatic option creation.
        $mform->addElement('header', 'autcrheader',
                get_string('autcrheader', 'mod_booking'));

        $mform->addElement('static', 'description', '', get_string('autcrwhatitis', 'mod_booking'));

        $cfields = $DB->get_records_menu('user_info_field', null, '', 'shortname, name', 0, 0);
        $cftemplates = $DB->get_records_menu('booking_options', array('bookingid' => 0), '', 'id, text', 0, 0);

        array_unshift($cfields, '');
        array_unshift($cftemplates, '');

        $mform->addElement('checkbox', 'autcractive', get_string('enable', 'mod_booking'));
        $mform->addElement('select', 'autcrprofile', get_string('customprofilefield', 'mod_booking'), $cfields);
        $mform->disabledIf('autcrprofile', 'autcractive');
        $mform->addElement('text', 'autcrvalue', get_string('customprofilefieldvalue', 'mod_booking'));
        $mform->setType('autcrvalue', PARAM_TEXT);
        $mform->disabledIf('autcrvalue', 'autcractive');
        $mform->addElement('select', 'autcrtemplate', get_string('optiontemplate', 'mod_booking'), $cftemplates);
        $mform->disabledIf('autcrtemplate', 'autcractive');
        */

        // Standard Moodle form elements.
        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();

        if (!empty($this->_cm->id)) {
            $data = new eventslist(
                $this->_cm->id,
                ['\mod_booking\event\bookinginstance_updated']
            );

            $html = $OUTPUT->render_from_template('mod_booking/eventslist', $data);
            $mform->addElement('static', 'eventslist', get_string('eventslist', 'mod_booking'), $html);
        }
        $PAGE->requires->js_call_amd('mod_booking/bookinginstancetemplateselect', 'init');
    }

    /**
     * Set defaults and prepare data for form.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        global $CFG;
        parent::data_preprocessing($defaultvalues);
        $options = ['subdirs' => false, 'maxfiles' => 50, 'accepted_types' => ['*'], 'maxbytes' => 0];

        $defaultvalues['enablecompletionenabled'] = !empty($defaultvalues['enablecompletion']) ? 1 : 0;
        if (empty($defaultvalues['enablecompletion'])) {
            $defaultvalues['enablecompletion'] = 1;
        }

        if ($this->current->instance) {
            $draftitemid = file_get_submitted_draft_itemid('myfilemanager');
            file_prepare_draft_area(
                $draftitemid,
                $this->context->id,
                'mod_booking',
                'myfilemanager',
                $this->current->id,
                $options
            );
            $defaultvalues['myfilemanager'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('bookingimages');
            file_prepare_draft_area(
                $draftitemid,
                $this->context->id,
                'mod_booking',
                'bookingimages',
                $this->current->id,
                $options
            );
            $defaultvalues['bookingimages'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('signinlogoheader');
            file_prepare_draft_area(
                $draftitemid,
                $this->context->id,
                'mod_booking',
                'signinlogoheader',
                $this->current->id,
                $options
            );
            $defaultvalues['signinlogoheader'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('signinlogofooter');
            file_prepare_draft_area(
                $draftitemid,
                $this->context->id,
                'mod_booking',
                'signinlogofooter',
                $this->current->id,
                $options
            );
            $defaultvalues['signinlogofooter'] = $draftitemid;
            core_tag_tag::get_item_tags_array('mod_booking', 'booking', $this->current->id);
        } else {
            $draftitemid = file_get_submitted_draft_itemid('myfilemanager');
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_booking', 'myfilemanager', 0, $options);
            $defaultvalues['myfilemanager'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('bookingimages');
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_booking', 'bookingimages', 0, $options);
            $defaultvalues['bookingimages'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('signinlogoheader');
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_booking', 'signinlogoheader', 0, $options);
            $defaultvalues['signinlogoheader'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('signinlogofooter');
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_booking', 'signinlogofooter', 0, $options);
            $defaultvalues['signinlogofooter'] = $draftitemid;
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

        $defaultvalues['bookingpolicy'] = [
            'text' => $defaultvalues['bookingpolicy'],
            'format' => $defaultvalues['bookingpolicyformat'],
        ];

        if (isset($defaultvalues['bookedtext'])) {
            $defaultvalues['bookedtext'] = [
                'text' => $defaultvalues['bookedtext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['waitingtext'])) {
            $defaultvalues['waitingtext'] = [
                'text' => $defaultvalues['waitingtext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['notifyemail'])) {
            $defaultvalues['notifyemail'] = [
                'text' => $defaultvalues['notifyemail'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['notifyemailteachers'])) {
            $defaultvalues['notifyemailteachers'] = [
                'text' => $defaultvalues['notifyemailteachers'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['statuschangetext'])) {
            $defaultvalues['statuschangetext'] = [
                'text' => $defaultvalues['statuschangetext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['deletedtext'])) {
            $defaultvalues['deletedtext'] = [
                'text' => $defaultvalues['deletedtext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['bookingchangedtext'])) {
            $defaultvalues['bookingchangedtext'] = [
                'text' => $defaultvalues['bookingchangedtext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['pollurltext'])) {
            $defaultvalues['pollurltext'] = [
                'text' => $defaultvalues['pollurltext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['pollurlteacherstext'])) {
            $defaultvalues['pollurlteacherstext'] = [
                'text' => $defaultvalues['pollurlteacherstext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['activitycompletiontext'])) {
            $defaultvalues['activitycompletiontext'] = [
                'text' => $defaultvalues['activitycompletiontext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['userleave'])) {
            $defaultvalues['userleave'] = [
                'text' => $defaultvalues['userleave'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['beforebookedtext'])) {
            $defaultvalues['beforebookedtext'] = [
                'text' => $defaultvalues['beforebookedtext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['beforecompletedtext'])) {
            $defaultvalues['beforecompletedtext'] = [
                'text' => $defaultvalues['beforecompletedtext'],
                'format' => FORMAT_HTML,
            ];
        }
        if (isset($defaultvalues['aftercompletedtext'])) {
            $defaultvalues['aftercompletedtext'] = [
                'text' => $defaultvalues['aftercompletedtext'],
                'format' => FORMAT_HTML,
            ];
        }
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     *
     * @return array
     *
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (empty($data['semesterid']) && (get_config('booking', 'canceldependenton') == "semesterstart")) {
            $errors['semesterid'] = get_string('error:semestermissingbutcanceldependentonsemester', 'mod_booking');
        }

        if (isset($data['bookingmanager']) && $DB->count_records('user', ['username' => $data['bookingmanager']]) != 1) {
            $errors['bookingmanager'] = get_string('bookingmanagererror', 'mod_booking');
        }

        if (!in_array($data['whichview'], $data['showviews'])) {
            $errors['whichview'] = get_string('whichviewerror', 'mod_booking');
        }

        if (strlen($data['pollurl']) > 0) {
            if (!filter_var($data['pollurl'], FILTER_VALIDATE_URL)) {
                $errors['pollurl'] = get_string('entervalidurl', 'mod_booking');
            }
        }

        if ($data['paginationnum'] < 1) {
                $errors['paginationnum'] = get_string('errorpagination', 'mod_booking');
        }

        if (strlen($data['pollurlteachers']) > 0) {
            if (!filter_var($data['pollurlteachers'], FILTER_VALIDATE_URL)) {
                $errors['pollurlteachers'] = get_string('entervalidurl', 'mod_booking');
            }
        }

        return $errors;
    }

    /**
     * Allows modules to modify the data returned by form get_data().
     * This method is also called in the bulk activity completion form.
     *
     * Only available on moodleform_mod.
     *
     * @param stdClass $data the form data to be modified.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $autocompletion = !empty($data->completion) && $data->completion == COMPLETION_TRACKING_AUTOMATIC;
            if (empty($data->enablecompletionenabled) || !$autocompletion) {
                $data->enablecompletion = 0;
            }
        }

        // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
        // TODO: Check if it's possible to overwrite instance specific mail templates with global mail templates...
        // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
        // TODO: ... if mailtemplatessource is set to 1 on saving.
    }

    /**
     * Get form data.
     *
     * @return object
     *
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $data->bookingpolicyformat = $data->bookingpolicy['format'];
            $data->bookingpolicy = $data->bookingpolicy['text'];
        }

        return $data;
    }
}
