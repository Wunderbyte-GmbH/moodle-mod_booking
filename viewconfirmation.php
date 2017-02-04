<?php
/**
 * View confirmation message
 *
 * @package Booking
 * @copyright 2016 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT); // Course Module ID
$optionid = required_param('optionid', PARAM_INT); // Option ID

$url = new moodle_url('/mod/booking/viewconfirmation.php',
        array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if (!$booking = booking_get_booking($cm, '', array(), true, $optionid)) {
    error("Course module is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

$PAGE->navbar->add(get_string("bookedtext", "booking"));
$PAGE->set_title(format_string(get_string("bookedtext", "booking")));
$PAGE->set_heading(get_string("bookedtext", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("bookedtext", "booking"), 3, 'helptitle', 'uniqueid');

$user = $DB->get_record('user', array('id' => $USER->id));
$bookingmanager = $DB->get_record('user', array('username' => $booking->bookingmanager));
$data = booking_generate_email_params($booking, $booking->option[$optionid], $user, $cm->id);

$message = booking_get_email_body($booking, 'bookedtext', 'confirmationmessage', $data);

echo "{$message}";

echo $OUTPUT->footer();