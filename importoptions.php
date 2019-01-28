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
 * Import options or just add new users from CSV
 *
 * @package Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("lib.php");
require_once('importoptions_form.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.

$url = new moodle_url('/mod/booking/importoptions.php', array('id' => $id));
$urlredirect = new moodle_url('/mod/booking/view.php', array('id' => $id));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);
$context = context_module::instance($cm->id);

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importcsvtitle", "booking"));
$booking = new \mod_booking\booking($cm->id);
$PAGE->set_title(format_string($booking->booking->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$mform = new importoptions_form($url);

$completion = new \completion_info($course);

// Form processing and displaying is done here.
if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($urlredirect, '', 0);
    die();
} else if ($fromform = $mform->get_data()) {

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');

    $csvfile = $mform->get_file_content('csvfile');

    $lines = explode(PHP_EOL, $csvfile);
    $csvarr = array();
    foreach ($lines as $line) {
        $csvarr[] = str_getcsv($line);
    }

    // Check if CSV is ok.

    if ($csvarr[0][0] == 'name' && $csvarr[0][1] == 'startdate' && $csvarr[0][2] == 'enddate' &&
        $csvarr[0][3] == 'institution' && $csvarr[0][4] == 'institutionaddress' &&
        $csvarr[0][5] == 'teacheremail' && $csvarr[0][6] == 'useremail' &&
        $csvarr[0][7] == 'finished' && $csvarr[0][8] == 'maxanswers' &&
        $csvarr[0][9] == 'maxoverbooking' && $csvarr[0][10] == 'limitanswers' &&
        $csvarr[0][11] == 'location') {
        $addtocalendarcol = false;
        if (isset($csvarr[0][12]) && $csvarr[0][12] == 'addtocalendar') {
            $addtocalendarcol = true;
        }
        array_shift($csvarr);
        $i = 0;
        foreach ($csvarr as $line) {

            $i++;

            if (count($line) >= 12) {

                $user = false;
                $teacher = false;
                $bookingoption = false;
                $startdate = 0;
                $enddate = 0;

                $bookingoptionname = $booking->booking->name;

                if (trim($line[1]) != 0) {
                    $startdate = date_create_from_format("!" . $fromform->dateparseformat, $line[1]);
                    if ($startdate !== false) {
                        $startdate = $startdate->getTimestamp();
                    }
                }

                $derors = DateTime::getLastErrors();
                if ($derors['error_count'] > 0) {

                    echo $OUTPUT->notification(
                        get_string('dateerror', 'booking', $i) . implode(', ', $line));

                    continue;
                }

                if (trim($line[2]) != 0) {
                    $enddate = date_create_from_format("!" . $fromform->dateparseformat, $line[2]);
                    $enddate = $enddate->getTimestamp();
                }

                $derors = DateTime::getLastErrors();
                if ($derors['error_count'] > 0) {
                    echo $OUTPUT->notification(
                        get_string('dateerror', 'booking', $i) . implode(', ', $line));
                    continue;
                }

                if (strlen(trim($line[5])) > 0) {
                    $teacher = $DB->get_record('user', array('email' => $line[5]));
                }

                if (strlen(trim($line[6])) > 0) {
                    $user = $DB->get_record('user',
                        array('suspended' => 0, 'deleted' => 0, 'confirmed' => 1,
                            'email' => $line[6]), '*', IGNORE_MULTIPLE);
                }

                if (strlen(trim($line[0])) > 0) {
                    $bookingoptionname = $line[0];
                }

                $addtocalendar = 0;
                if ($addtocalendarcol) {
                    if (strlen(trim($line[12])) > 0) {
                        $colval = trim($line[12]);
                        if ($colval == "yes" || $colval == 1) {
                            $addtocalendar = 1;
                        }
                    }
                }

                $bookingoption = $DB->get_record_sql(
                    'SELECT * FROM {booking_options}
                         WHERE institution LIKE :institution
                         AND text LIKE :text
                         AND bookingid = :bookingid
                         AND coursestarttime = :coursestarttime',
                    array('institution' => $line[3], 'text' => $bookingoptionname,
                        'bookingid' => $booking->id, 'coursestarttime' => $startdate));

                // Create institution if it does not exist.
                $institutionname = mod_booking_fix_encoding($line[3]);
                $instexists = $DB->record_exists('booking_institutions', array('course' => $course->id,
                    "name" => $institutionname));
                $instnameempty = empty($institutionname);
                if (!$instexists && !$instnameempty) {
                    $institution = new stdClass();
                    $institution->name = $institutionname;
                    $institution->course = $course->id;
                    $DB->insert_record("booking_institutions", $institution);
                }

                if (empty($bookingoption)) {
                    $bookingobject = new stdClass();
                    $bookingobject->bookingid = $booking->id;
                    $bookingobject->startendtimeknown = 1;
                    $bookingobject->text = mod_booking_fix_encoding($bookingoptionname);
                    $bookingobject->description = '';
                    $bookingobject->courseid = $booking->course->id;
                    $bookingobject->coursestarttime = $startdate;
                    $bookingobject->courseendtime = $enddate;
                    $bookingobject->institution = $institutionname;
                    $bookingobject->address = mod_booking_fix_encoding($line[4]);
                    $bookingobject->maxanswers = $line[8];
                    $bookingobject->maxoverbooking = $line[9];
                    $bookingobject->limitanswers = $line[10];
                    $bookingobject->location = mod_booking_fix_encoding($line[11]);
                    $bookingobject->addtocalendar = $addtocalendar;
                    $bookingobject->disablebookingusers = 0;
                    $bookingobject->pollurl = "";
                    $bookingobject->pollurlteachers = "";
                    $bookingobject->beforebookedtext = "";
                    $bookingobject->beforecompletedtext = "";
                    $bookingobject->beforebookedtext = "";
                    $bookingobject->aftercompletedtext = "";
                    $bookingobject->duration = 0;

                    $optionid = booking_update_options($bookingobject, $context);

                    $bookingobject->id = $optionid;
                    $bookingoption = $bookingobject;
                }

                if ($teacher) {
                    $getuser = $DB->get_record('booking_teachers',
                        array('bookingid' => $booking->id, 'userid' => $teacher->id,
                            'optionid' => $bookingoption->id));

                    if ($getuser === false) {
                        $newteacher = new stdClass();
                        $newteacher->bookingid = $booking->id;
                        $newteacher->userid = $teacher->id;
                        $newteacher->optionid = $bookingoption->id;

                        $DB->insert_record('booking_teachers', $newteacher, true);
                    }
                } else {
                    echo $OUTPUT->notification(
                        get_string('noteacherfound', 'booking', $i) . $line[5]);
                }

                if ($user) {
                    $getuser = $DB->get_record('booking_answers',
                        array('bookingid' => $booking->id, 'userid' => $user->id,
                            'optionid' => $bookingoption->id));

                    if ($getuser === false) {
                        $bookingdata = new \mod_booking\booking_option($cm->id, $bookingoption->id,
                            array(), 0, 0, false);
                        $bookingdata->user_submit_response($user);

                        if ($completion->is_enabled($cm) && $bookingdata->booking->enablecompletion &&
                            $line[7] == 0) {
                            $completion->update_state($cm, COMPLETION_INCOMPLETE, $user->id);
                        }

                        if ($completion->is_enabled($cm) && $bookingdata->booking->enablecompletion &&
                            $line[7] == 1) {
                            $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
                        }
                    }
                } else {
                    echo $OUTPUT->notification(get_string('nouserfound', 'booking') . $line[6]);
                }
            }
        }

        echo $OUTPUT->box(get_string('importfinished', 'booking'));
    } else {
        // Not ok, write error.
        echo $OUTPUT->notification(get_string('wrongfile', 'booking'));
    }

    // In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');
    $mform->display();
}

echo $OUTPUT->footer();
