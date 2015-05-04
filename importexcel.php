<?php

/**
 * Import excel data - change activity completion to user
 *
 * @package   Booking
 * @copyright 2015 Andraž Prinčič www.princic.net
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
require_once("../../config.php");
require_once("locallib.php");
require_once('importexcel_form.php');

$id = required_param('id', PARAM_INT);                 // Course Module ID

$url = new moodle_url('/mod/booking/importexcel.php', array('id' => $id));
$urlRedirect = new moodle_url('/mod/booking/view.php', array('id' => $id));
$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    print_error("Course Module ID was incorrect");
}

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = booking_get_booking($cm, '')) {
    error("Course module is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importexceltitle", "booking"));
$PAGE->set_title(format_string($booking->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$mform = new importexcel_form($url);

//Form processing and displaying is done here
if ($mform->is_cancelled()) {
    //Handle form cancel operation, if cancel button is present on form
    redirect($urlRedirect, '', 0);
    die;
} else if ($fromform = $mform->get_data()) {
    $csvfile = $mform->get_file_content('excelfile');

    $lines = explode(PHP_EOL, $csvfile);
    $csvArr = array();
    foreach ($lines as $line) {
        $csvArr[] = str_getcsv($line);
    }

    $optionIDPos = -1;
    $userIDPos = -1;
    $completedPos = -1;

    foreach ($csvArr[0] as $key => $value) {
        switch ($value) {
            case get_string("optionid", "booking"):
                $optionIDPos = $key;
                break;

            case get_string("user") . " " . get_string("idnumber"):
                $userIDPos = $key;
                break;

            case get_string("searchFinished", "booking"):
                $completedPos = $key;
                break;

            default:
                break;
        }
    }

    if ($optionIDPos > -1 && $userIDPos > -1 && $completedPos > -1) {
        array_shift($csvArr);

        $completion = new completion_info($course);
        
        foreach ($csvArr as $line) {
            if (count($line) >= 3) {
                $user = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $line[$userIDPos], 'optionid' => $line[$optionIDPos]));

                if ($user !== FALSE) {
                    $user->completed = $line[$completedPos];
                    $user->timemodified = time();
                    $DB->update_record('booking_answers', $user, false);                    

                    if ($completion->is_enabled($cm) && $booking->enablecompletion && $user->completed == 0) {
                        $completion->update_state($cm, COMPLETION_INCOMPLETE, $user->userid);
                    }

                    if ($completion->is_enabled($cm) && $booking->enablecompletion && $user->completed == 1) {
                        $completion->update_state($cm, COMPLETION_COMPLETE, $user->userid);
                    }
                }
            }
        }

        redirect($urlRedirect, get_string('importfinished', 'booking'), 5);
    } else {
        redirect($urlRedirect, get_string('wrongfile', 'booking'), 5);
    }

    //In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importexceltitle", "booking"), 3, 'helptitle', 'uniqueid');

    $mform->display();
}

echo $OUTPUT->footer();
