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
require_once('../../config.php');
require_once("$CFG->dirroot/mod/booking/locallib.php");
$id = required_param('id', PARAM_INT);

$booking = new \mod_booking\booking($id);
$booking->apply_tags();

$context = context_course::instance($booking->booking->course);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_login($course->id, false);

$PAGE->set_url('/mod/booking/viewpolicy.php', array('id' => $id));
$PAGE->set_title(get_string("bookingpolicy", "booking"));
$PAGE->set_heading(get_string("bookingpolicy", "booking"));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string("bookingpolicy", "booking"), 2);

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); // Could use an id separate from tag-blogs, but looks the same with that id.

echo $booking->booking->bookingpolicy;

echo $OUTPUT->box_end();

echo $OUTPUT->footer();