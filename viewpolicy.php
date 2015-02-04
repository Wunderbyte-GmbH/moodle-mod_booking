<?php

require_once('../../config.php');
require_once("$CFG->dirroot/mod/booking/locallib.php");
$id = required_param('id', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

// $booking = $DB->get_record("booking", array("id" => $id));
$booking = new booking($cmid);
$booking->apply_tags();

$context = context_course::instance($booking->booking->course);

if (!$course = $DB->get_record("course", array("id" => $booking->booking->course))) {
    print_error('coursemisconf');
}

require_login($course->id, false);

$PAGE->set_url('/mod/booking/viewpolicy.php', array('id' => $id));
$PAGE->set_title(get_string("bookingpolicy", "booking"));
$PAGE->set_heading(get_string("bookingpolicy", "booking"));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string("bookingpolicy", "booking"), 2);

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); //could use an id separate from tag-blogs, but would have to copy the css style to make it look the same

echo $booking->booking->bookingpolicy;

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
?>