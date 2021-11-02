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
 * View confirmation message
 *
 * @package Booking
 * @copyright 2016 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");

$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT); // Option ID.

$url = new moodle_url('/mod/booking/viewconfirmation.php',
        array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if (!$booking = new mod_booking\booking_option($id, $optionid, array(), 0, 0, false)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

$PAGE->navbar->add(get_string("bookedtext", "booking"));
$PAGE->set_title(get_string("bookedtext", "booking"));
$PAGE->set_heading(get_string("bookedtext", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("bookedtext", "booking"), 3, 'helptitle', 'uniqueid');

$user = $DB->get_record('user', array('id' => $USER->id));
$answer = $DB->get_record('booking_answers', array('userid' => $USER->id, 'optionid' => $optionid));
if (!$answer) {
    echo $OUTPUT->error_text(get_string("notbooked", "booking"));
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', array('id' => $course->id)));
    echo $OUTPUT->footer();
}
$bookingmanager = $DB->get_record('user', array('username' => $booking->booking->settings->bookingmanager));
$data = booking_generate_email_params($booking->booking->settings, $booking->option, $user, $cm->id, $booking->optiontimes,
    false, false, true);

if ($answer->waitinglist == 1) {
    $message = booking_get_email_body($booking->booking->settings, 'waitingtext', 'confirmationmessage', $data);
} else {
    $message = booking_get_email_body($booking->booking->settings, 'bookedtext', 'confirmationmessagewaitinglist', $data);
}

echo "{$message}";

echo $OUTPUT->footer();