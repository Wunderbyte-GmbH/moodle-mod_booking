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
require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");
require_once($CFG->libdir . '/formslib.php');

use mod_booking\form\option_form;
use mod_booking\form\modaloptiondateform;
use \core\output\notification;
use mod_booking\customfield\booking_handler;
use mod_booking\price;
use local_entities\entitiesrelation_handler;

global $DB, $OUTPUT, $PAGE, $USER;

$cmid = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$copyoptionid = optional_param('copyoptionid', 0, PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_INT);
$mode = optional_param('mode', '', PARAM_RAW);

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$url = new moodle_url('/mod/booking/editoptions.php', array('id' => $cmid, 'optionid' => $optionid));
$PAGE->set_url($url);
$PAGE->requires->jquery_plugin('ui-css');

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

// Initialize bookingid.
$bookingid = (int) $cm->instance;

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = new \mod_booking\booking($cmid)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cmid)) {
    throw new moodle_exception('badcontext');
}

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    throw new moodle_exception('nopermissions');
}

if (has_capability('mod/booking:cantoggleformmode', $context)) {
    // Switch mode after button has been clicked.
    switch ($mode) {
        case 'formmodesimple':
            set_user_preference('optionform_mode', 'simple');
            break;
        case 'formmodeexpert':
            set_user_preference('optionform_mode', 'expert');
            break;
    }
} else {
    // Without the capability, we always use simple mode.
    set_user_preference('optionform_mode', 'simple');
}

$mform = new option_form(null, array('bookingid' => $bookingid, 'optionid' => $optionid, 'cmid' => $cmid,
    'context' => $context));

// Duplicate this booking option.
if ($optionid == -1 && $copyoptionid != 0) {
    // Adding new booking option - default values.
    $defaultvalues = $DB->get_record('booking_options', array('id' => $copyoptionid));
    $oldoptionid = $defaultvalues->id;
    $defaultvalues->text = $defaultvalues->text . get_string('copy', 'booking');
    $defaultvalues->optionid = -1;
    $defaultvalues->bookingname = $booking->settings->name;
    $defaultvalues->bookingid = $bookingid;
    $defaultvalues->id = $cmid;

    // Create a new duplicate of the old booking option.
    $optionid = booking_update_options($defaultvalues, $context);
    $defaultvalues->optionid = $optionid;

    // If there are associated teachers, let's duplicate them too.
    $teacherstocopy = $DB->get_records('booking_teachers', ['bookingid' => $bookingid, 'optionid' => $oldoptionid]);

    // For each copied teacher change the old optionid to the new one and unset the old id.
    foreach ($teacherstocopy as $teachertocopy) {
        // Subscribe the copied teacher to the new booking option.
        subscribe_teacher_to_booking_option($teachertocopy->userid, $optionid, $cm);
    }

} else if ($optionid > 0 && $defaultvalues = $DB->get_record('booking_options',
                array('bookingid' => $booking->settings->id, 'id' => $optionid))) {
    $defaultvalues->optionid = $optionid;
    $defaultvalues->bookingname = $booking->settings->name;
    $defaultvalues->id = $cmid;
}

// Elective. Retrieve values.

$mustcombinearray = \mod_booking\booking_elective::get_combine_array($optionid, 1);

if (count($mustcombinearray) != 0) {
    $defaultvalues->mustcombine = $mustcombinearray;
}

$mustnotcombinearray = \mod_booking\booking_elective::get_combine_array($optionid, 0);

if (count($mustnotcombinearray) != 0) {
    $defaultvalues->mustnotcombine = $mustnotcombinearray;
}

if ($mform->is_cancelled()) {

    if (!empty($returnurl)) {
        redirect($returnurl);
    } else {
        $redirecturl = new moodle_url('/mod/booking/view.php', array('id' => $cmid));
        redirect($redirecturl, '', 0);
    }
} else if ($fromform = $mform->get_data()) {
    // Validated data.
    if (confirm_sesskey() &&
            (has_capability('mod/booking:updatebooking', $context) ||
            has_capability('mod/booking:addeditownoption', $context))) {
        if (!isset($fromform->limitanswers)) {
            $fromform->limitanswers = 0;
        }

        // Get all new dynamically loaded dates from $_POST and save them.
        $newoptiondates = [];
        // Also get the remaining existing dates.
        $stillexistingdates = [];

        foreach ($_POST as $key => $value) {
            // New option dates (created with date series function).
            if (substr($key, 0, 18) === 'coursetime-newdate') {
                $newoptiondates[] = $value;
            }

            // Also add custom dates to the new option dates.
            if (substr($key, 0, 21) === 'coursetime-customdate') {
                $newoptiondates[] = $value;
            }

            // Dates loaded from DB which have not been removed.
            if (substr($key, 0, 17) === 'coursetime-dateid') {
                $currentdateid = (int) explode('-', $key)[2];
                $stillexistingdates[$currentdateid] = $value;
            }
        }
        // Store the arrays in $fromform so we can use them later in booking_update_options.
        $fromform->newoptiondates = $newoptiondates;
        $fromform->stillexistingdates = $stillexistingdates;

        // Also, get semesterid and dayofweektime string from the dynamic form and load it into $fromform.
        if (isset($_POST['semesterid'])) {
            $fromform->semesterid = $_POST['semesterid'];
        }
        if (isset($_POST['dayofweektime'])) {
            $fromform->dayofweektime = $_POST['dayofweektime'];
        }

        // Todo: nbooking should be call $optionid.
        $nbooking = booking_update_options($fromform, $context);

        if ($draftitemid = file_get_submitted_draft_itemid('myfilemanageroption')) {
            file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanageroption',
                    $nbooking, array('subdirs' => false, 'maxfiles' => 50));
        }

        if ($draftimageid = file_get_submitted_draft_itemid('bookingoptionimage')) {
            file_save_draft_area_files($draftimageid, $context->id, 'mod_booking', 'bookingoptionimage',
                    $nbooking, array('subdirs' => false, 'maxfiles' => 1));
        }

        if (isset($fromform->addastemplate) && $fromform->addastemplate == 1) {
            $fromform->bookingid = 0;
            $nbooking = booking_update_options($fromform, $context);
            if ($nbooking === 'BOOKING_OPTION_NOT_CREATED') {
                $redirecturl = new moodle_url('/mod/booking/editoptions.php', array('id' => $cmid, 'optionid' => -1));
                redirect($redirecturl, get_string('option_template_not_saved_no_valid_license', 'booking'), 0,
                    notification::NOTIFY_ERROR);
            } else if (isset($fromform->submittandaddnew)) {
                $redirecturl = new moodle_url('/mod/booking/editoptions.php', array('id' => $cmid, 'optionid' => -1));
                redirect($redirecturl, get_string('newtemplatesaved', 'booking'), 0);
            } else {
                $redirecturl = new moodle_url('/mod/booking/view.php', array('id' => $cmid));
                redirect($redirecturl, get_string('newtemplatesaved', 'booking'), 0);
            }
        }

        $bookingdata = new \mod_booking\booking_option($cmid, $nbooking);
        $bookingdata->sync_waiting_list();

        if (has_capability('mod/booking:addeditownoption', $context) && $optionid == -1 &&
                !has_capability('mod/booking:updatebooking', $context)) {
            subscribe_teacher_to_booking_option($USER->id, $nbooking, $cm);
        }

        // Recurring.
        if ($optionid == -1 && isset($fromform->startendtimeknown) && $fromform->startendtimeknown == 1 &&
            isset($fromform->repeatthisbooking) && $fromform->repeatthisbooking == 1 && $fromform->howmanytimestorepeat > 0) {

            $fromform->parentid = $nbooking;
            $name = $fromform->text;

            $restrictanswerperiod = 0;
            if (isset($fromform->restrictanswerperiod) && $fromform->restrictanswerperiod) {
                $restrictanswerperiod = $fromform->coursestarttime - $fromform->bookingclosingtime;
            }

            for ($i = 0; $i < $fromform->howmanytimestorepeat; $i++) {
                $fromform->text = $name . " #" . ($i + 2);
                $fromform->coursestarttime = $fromform->coursestarttime + $fromform->howoftentorepeat;
                $fromform->courseendtime = $fromform->courseendtime + $fromform->howoftentorepeat;

                if ($restrictanswerperiod != 0) {
                    $fromform->bookingclosingtime = $fromform->coursestarttime + $restrictanswerperiod;
                }

                $nbooking = booking_update_options($fromform, $context, $cm);

                if ($draftitemid = file_get_submitted_draft_itemid('myfilemanageroption')) {
                    file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanageroption',
                            $nbooking, array('subdirs' => false, 'maxfiles' => 50));
                }

                if ($draftimageid = file_get_submitted_draft_itemid('bookingoptionimage')) {
                    file_save_draft_area_files($draftimageid, $context->id, 'mod_booking', 'bookingoptionimage',
                            $nbooking, array('subdirs' => false, 'maxfiles' => 1));
                }

                $bookingdata = new \mod_booking\booking_option($cmid, $nbooking);
                $bookingdata->sync_waiting_list();

                if (has_capability('mod/booking:addeditownoption', $context) && $optionid == -1 &&
                        !has_capability('mod/booking:updatebooking', $context)) {
                    subscribe_teacher_to_booking_option($USER->id, $nbooking, $cm);
                }
            }
        }

        // Save the prices.
        // Make sure we have the option id in the fromform.
        $fromform->optionid = $nbooking ?? $optionid;
        $price = new price($fromform->optionid);
        $price->save_from_form($fromform);

        // This is to save entity relation data.
        // The id key has to be set to option id.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('bookingoption');
            $erhandler->instance_form_save($fromform, $fromform->optionid);
        }

        // This is to save customfield data
        // The id key has to be set to option id.
        $fromform->id = $nbooking ?? $optionid;
        $handler = booking_handler::create();
        $handler->instance_form_save($fromform, $optionid == -1);

        // Redirect after pressing one of the 2 submit buttons.
        if (isset($fromform->submittandaddnew)) {
            $redirecturl = new moodle_url('/mod/booking/editoptions.php', array('id' => $cmid, 'optionid' => -1));
        } else {

            if (!empty($returnurl)) {
                $redirecturl = $returnurl;
            } else {
                $redirecturl = new moodle_url('/mod/booking/view.php', array('id' => $cmid));
            }
        }
        redirect($redirecturl, get_string('changessaved'), 0);
    }
} else {
    $PAGE->set_title(format_string($booking->settings->name));
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();

    if (has_capability('mod/booking:cantoggleformmode', $context)) {

        $currentpref = get_user_preferences('optionform_mode');
        switch ($currentpref) {
            case 'expert':
                $togglemode = 'formmodesimple';
                $formmodelabel = get_string('toggleformmode_simple', 'mod_booking');
                break;
            case 'simple':
            default:
                $togglemode = 'formmodeexpert';
                $formmodelabel = get_string('toggleformmode_expert', 'mod_booking');
                break;
        }

        // Add a button to toggle between simple and expert mode.
        $formmodeurl = new moodle_url('/mod/booking/editoptions.php', [
            'mode' => $togglemode,
            'id' => $cmid,
            'optionid' => $optionid
        ]);

        echo html_writer::link($formmodeurl->out(false),
            $formmodelabel,
            ['id' => $cmid, 'optionid' => $optionid,
                'class' => 'btn btn-secondary float-right']);
    }

    // Heading.
    if (empty($optionid)) {
        $heading = get_string('addnewbookingoption', 'mod_booking');
    } else {
        $heading = get_string('editbookingoption', 'mod_booking');
    }

    echo $OUTPUT->heading($heading);

    if (isset($defaultvalues)) {

        // We need to set the returnurl if present.
        if (!empty($returnurl)) {
            $defaultvalues->returnurl = $returnurl;
        }

        $mform->set_data($defaultvalues);
    }
    $mform->display();
}

$PAGE->requires->js_call_amd(
    'mod_booking/institutionautocomplete',
    'init',
    array($cmid)
);

// Initialize dynamic optiondate form.
$PAGE->requires->js_call_amd(
    'mod_booking/dynamicoptiondateform',
    'initdynamicoptiondateform',
    array($cmid, $bookingid, $optionid,
        get_string('modaloptiondateformtitle', 'mod_booking'),
        modaloptiondateform::class)
);

echo $OUTPUT->footer();
