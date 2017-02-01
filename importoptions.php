<?php

/**
 * Import options or just add new users from CSV
 *
 * @package Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once ("../../config.php");
require_once ("lib.php");
require_once ('importoptions_form.php');

function modbooking_fixEncoding($in_str) {
    $cur_encoding = mb_detect_encoding($in_str);
    if ($cur_encoding == "UTF-8" && mb_check_encoding($in_str, "UTF-8")) {
        return $in_str;
    } else {
        return utf8_encode($in_str);
    }
}

$id = required_param('id', PARAM_INT); // Course Module ID

$url = new moodle_url('/mod/booking/importoptions.php', array('id' => $id));
$urlRedirect = new moodle_url('/mod/booking/view.php', array('id' => $id));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = booking_get_booking($cm, '', array(), true, null, false)) {
    error("Course module is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importcsvtitle", "booking"));
$PAGE->set_title(format_string($booking->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$mform = new importoptions_form($url);

$completion = new completion_info($course);

// Form processing and displaying is done here
if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form
    redirect($urlRedirect, '', 0);
    die();
} else if ($fromform = $mform->get_data()) {

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');

    $csvfile = $mform->get_file_content('csvfile');

    $lines = explode(PHP_EOL, $csvfile);
    $csvArr = array();
    foreach ($lines as $line) {
        $csvArr[] = str_getcsv($line);
    }

    // Check if CSV is ok

    if ($csvArr[0][0] == 'name' && $csvArr[0][1] == 'startdate' && $csvArr[0][2] == 'enddate' &&
             $csvArr[0][3] == 'institution' && $csvArr[0][4] == 'institutionaddress' &&
             $csvArr[0][5] == 'teacheremail' && $csvArr[0][6] == 'useremail' &&
             $csvArr[0][7] == 'finished' && $csvArr[0][8] == 'maxanswers' &&
             $csvArr[0][9] == 'maxoverbooking' && $csvArr[0][10] == 'limitanswers' &&
             $csvArr[0][11] == 'location') {
        array_shift($csvArr);
        $i = 0;
        foreach ($csvArr as $line) {

            $i++;

            if (count($line) == 12) {

                $user = FALSE;
                $teacher = FALSE;
                $booking_option = FALSE;
                $startDate = 0;
                $endDate = 0;

                $booking_option_name = $booking->name;

                if (trim($line[1]) != 0) {
                    $startDate = date_create_from_format("!" . $fromform->dateparseformat, $line[1]);
                    $startDate = $startDate->getTimestamp();
                }

                $dErors = DateTime::getLastErrors();
                if ($dErors['error_count'] > 0) {

                    echo $OUTPUT->notification(
                            get_string('dateerror', 'booking', $i) . implode(', ', $line));

                    continue;
                }

                if (trim($line[2]) != 0) {
                    $endDate = date_create_from_format("!" . $fromform->dateparseformat, $line[2]);
                    $endDate = $endDate->getTimestamp();
                }

                $dErors = DateTime::getLastErrors();
                if ($dErors['error_count'] > 0) {

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
                    $booking_option_name = $line[0];
                }

                $booking_option = $DB->get_record_sql(
                        'SELECT * FROM {booking_options} WHERE institution LIKE :institution AND text LIKE :text AND bookingid = :bookingid AND coursestarttime = :coursestarttime',
                        array('institution' => $line[3], 'text' => $booking_option_name,
                            'bookingid' => $booking->id, 'coursestarttime' => $startDate));

                if (empty($booking_option)) {
                    $bookingObject = new stdClass();
                    $bookingObject->bookingid = $booking->id;
                    $bookingObject->text = modbooking_fixEncoding($booking_option_name);
                    $bookingObject->description = '';
                    $bookingObject->courseid = $booking->course;
                    $bookingObject->coursestarttime = $startDate;
                    $bookingObject->courseendtime = $endDate;
                    $bookingObject->institution = modbooking_fixEncoding($line[3]);
                    $bookingObject->address = modbooking_fixEncoding($line[4]);
                    $bookingObject->maxanswers = $line[8];
                    $bookingObject->maxoverbooking = $line[9];
                    $bookingObject->limitanswers = $line[10];
                    $bookingObject->location = modbooking_fixEncoding($line[11]);

                    $bid = $DB->insert_record('booking_options', $bookingObject, TRUE);

                    $bookingObject->id = $bid;
                    $booking_option = $bookingObject;
                }

                if ($teacher) {
                    $getUser = $DB->get_record('booking_teachers',
                            array('bookingid' => $booking->id, 'userid' => $teacher->id,
                                'optionid' => $booking_option->id));

                    if ($getUser === FALSE) {
                        $newTeacher = new stdClass();
                        $newTeacher->bookingid = $booking->id;
                        $newTeacher->userid = $teacher->id;
                        $newTeacher->optionid = $booking_option->id;

                        $DB->insert_record('booking_teachers', $newTeacher, TRUE);
                    }
                } else {
                    echo $OUTPUT->notification(
                            get_string('noteacherfound', 'booking', $i) . $line[5]);
                }

                if ($user) {
                    $getUser = $DB->get_record('booking_answers',
                            array('bookingid' => $booking->id, 'userid' => $user->id,
                                'optionid' => $booking_option->id));

                    if ($getUser === FALSE) {
                        $bookingData = new \mod_booking\booking_option($cm->id, $booking_option->id,
                                array(), 0, 0, false);
                        $bookingData->user_submit_response($user);

                        if ($completion->is_enabled($cm) && $bookingData->booking->enablecompletion &&
                                 $line[7] == 0) {
                            $completion->update_state($cm, COMPLETION_INCOMPLETE, $user->id);
                        }

                        if ($completion->is_enabled($cm) && $bookingData->booking->enablecompletion &&
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
        // Not ok, write error!
        echo $OUTPUT->notification(get_string('wrongfile', 'booking'));
    }

    // In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');

    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    // displays the form
    $mform->display();
}

echo $OUTPUT->footer();
