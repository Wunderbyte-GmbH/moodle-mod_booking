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

use mod_booking\utils\db;

$url = new moodle_url('/mod/booking/mybookings.php');
$PAGE->set_url($url);

$course = $DB->get_record('course', array('id' => SITEID), '*', MUST_EXIST);

$PAGE->set_context(context_user::instance($USER->id));
$PAGE->navigation->extend_for_user($USER);
$mybookingsurl = new moodle_url('/mod/booking/mybookings.php');
$PAGE->navbar->add(get_string('mybookings', 'mod_booking'), $mybookingsurl);

$PAGE->set_pagelayout('incourse');
$PAGE->set_title($course->fullname);
$PAGE->set_heading(fullname($USER));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('mybookings', 'mod_booking'));

echo $OUTPUT->box_start();

$dbutill = new db();
$mybookings = $dbutill->mybookings();
$cid = -1;
$bid = -1;

$table = new html_table();
$table->head = array(get_string('course'), get_string('booking', 'booking'), get_string('bookingoptionsmenu', 'booking'),
    get_string('status', 'booking'), get_string('coursestarttime', 'booking'));

foreach ($mybookings as $key => $value) {
    $coursename = '';
    $bookingname = '';
    $startdate = '';

    if ($cid != $value->courseid) {
        $courseurl = new moodle_url("/course/view.php?id={$value->courseid}");
        $coursename = "<a href='{$courseurl}'>{$value->fullname}</a>";
    }

    if ($bid != $value->bookingid) {
        $bookingurl = new moodle_url("/mod/booking/view.php?id={$value->cmid}");
        $bookingname = "<a href='{$bookingurl}'>{$value->name}</a>";
    }

    $optionstatus = booking_getoptionstatus($value->coursestarttime, $value->courseendtime);
    $optionurl = new moodle_url("/mod/booking/view.php?id={$value->cmid}&optionid={$value->optionid}&action=showonlyone&whichview=showonlyone#goenrol");
    $optionname = "<a href='{$optionurl}'>{$value->text}</a>";

    if ($value->coursestarttime != 0) {
        $startdate = userdate($value->coursestarttime, get_string('strftimedatetime'));
    }

    $table->data[] = array($coursename, $bookingname, $optionname, $optionstatus, $startdate);

    $cid = $value->courseid;
    $bid = $value->bookingid;
}

echo html_writer::table($table);

echo $OUTPUT->box_end();

echo $OUTPUT->footer();