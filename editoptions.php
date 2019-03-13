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
require_once("../../config.php");
require_once("locallib.php");
require_once("bookingform.class.php");

$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$copyoptionid = optional_param('copyoptionid', '', PARAM_ALPHANUM);
$sesskey = optional_param('sesskey', '', PARAM_INT);

$url = new moodle_url('/mod/booking/editoptions.php', array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);
$PAGE->requires->jquery_plugin('ui-css');

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = new \mod_booking\booking($cm->id)) {
    error("Course module is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    print_error('nopermissions');
}

$mform = new mod_booking_bookingform_form(null, array('bookingid' => $cm->instance, 'optionid' => $optionid, 'cmid' => $cm->id));

if ($optionid == -1) {
    // Adding new booking option - default values.
    $defaultvalues = $booking->booking;
    if ($copyoptionid != '') {
        if ($defaultvalues = $DB->get_record('booking_options', array('id' => $copyoptionid))) {
            $defaultvalues->text = $defaultvalues->text . get_string('copy', 'booking');
            $defaultvalues->optionid = -1;
            $defaultvalues->bookingname = $booking->booking->name;
            $defaultvalues->bookingid = $cm->instance;
            $defaultvalues->id = $cm->id;
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
        }
    }
    $defaultvalues->bookingname = $booking->booking->name;
    $defaultvalues->optionid = -1;
    $defaultvalues->bookingid = $booking->booking->id;
    $defaultvalues->id = $cm->id;
} else if ($defaultvalues = $DB->get_record('booking_options', array('bookingid' => $booking->booking->id, 'id' => $optionid))) {
    $defaultvalues->optionid = $optionid;
    $defaultvalues->bookingname = $booking->booking->name;
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
    $defaultvalues->id = $cm->id;
    if ($defaultvalues->bookingclosingtime) {
        $defaultvalues->restrictanswerperiod = "checked";
    }
    if ($defaultvalues->coursestarttime) {
        $defaultvalues->startendtimeknown = "checked";
    }

    $draftitemid = file_get_submitted_draft_itemid('myfilemanageroption');

    file_prepare_draft_area($draftitemid, $context->id, 'mod_booking', 'myfilemanageroption', $optionid,
            array('subdirs' => false, 'maxfiles' => 50, 'accepted_types' => array('*'), 'maxbytes' => 0));

    $defaultvalues->myfilemanageroption = $draftitemid;

    // Defaults for customfields.
    $cfdefaults = $DB->get_records('booking_customfields', array('optionid' => $optionid));
    $customfields = \mod_booking\booking_option::get_customfield_settings();
    if (!empty($cfdefaults)) {
        foreach ($cfdefaults as $defaultval) {
            $cfgvalue = $defaultval->cfgname;
            $defaultvalues->$cfgvalue = $defaultval->value;
        }
    }
} else {
    print_error('This booking option does not exist');
}

if ($mform->is_cancelled()) {
    $redirecturl = new moodle_url('view.php', array('id' => $cm->id));
    redirect($redirecturl, '', 0);
} else if ($fromform = $mform->get_data()) {
    // Validated data.
    if (confirm_sesskey() &&
            (has_capability('mod/booking:updatebooking', $context) ||
            has_capability('mod/booking:addeditownoption', $context))) {
        if (!isset($fromform->limitanswers)) {
            $fromform->limitanswers = 0;
        }

        $nbooking = booking_update_options($fromform, $context);

        if ($draftitemid = file_get_submitted_draft_itemid('myfilemanageroption')) {
            file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanageroption',
                    $nbooking, array('subdirs' => false, 'maxfiles' => 50));
        }

        $bookingdata = new \mod_booking\booking_option($cm->id, $nbooking);
        $bookingdata->sync_waiting_list();

        if (has_capability('mod/booking:addeditownoption', $context) && $optionid == -1 &&
                !has_capability('mod/booking:updatebooking', $context)) {
            booking_optionid_subscribe($USER->id, $nbooking, $cm);
        }

        if (isset($fromform->submittandaddnew)) {
            $redirecturl = new moodle_url('editoptions.php', array('id' => $cm->id, 'optionid' => -1));
            redirect($redirecturl, get_string('changessaved'), 0);
        } else {
            $redirecturl = new moodle_url('report.php',
                    array('id' => $cm->id, 'optionid' => $nbooking));
            redirect($redirecturl, get_string('changessaved'), 0);
        }
    }
} else {
    $PAGE->set_title(format_string($booking->booking->name));
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();

    $mform->set_data($defaultvalues);
    $mform->display();
}

$PAGE->requires->js_call_amd('mod_booking/institutionautocomplete', 'init', array($id));

echo $OUTPUT->footer();
