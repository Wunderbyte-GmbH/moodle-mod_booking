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

$sql = "SELECT
ba.id,
c.id courseid,
c.fullname,
b.id bookingid,
b.name,
bo.text,
bo.id optionid,
bo.coursestarttime,
bo.courseendtime,
cm.id cmid
FROM
{booking_answers} ba
    LEFT JOIN
{booking_options} bo ON ba.optionid = bo.id
    LEFT JOIN
{booking} b ON b.id = bo.bookingid
    LEFT JOIN
{course} c ON c.id = b.course
    LEFT JOIN
    {course_modules} cm ON cm.module = (SELECT
            id
        FROM
            {modules}
        WHERE
            name = 'booking')
        AND instance = b.id
WHERE
    userid = ?
ORDER BY c.id ASC, b.id ASC , bo.id ASC";

$mybookings = $DB->get_records_sql($sql, array($USER->id), 0, 0);
$cid = -1;
$bid = -1;

foreach ($mybookings as $key => $value) {
    if ($bid != -1 && $bid != $value->bookingid) {
        echo "</ul>";
    }
    if ($cid != $value->courseid) {
        $courseurl = new moodle_url("/course/view.php?id={$value->courseid}");
        echo "<h2><a href='{$courseurl}'>{$value->fullname}</a></h2>";
    }

    if ($bid != $value->bookingid) {
        $bookingurl = new moodle_url("/mod/booking/view.php?id={$value->cmid}");
        echo "<h3><a href='{$bookingurl}'>{$value->name}</a></h3>";
        echo "<ul>";
    }

    $optionstatus = booking_getoptionstatus($value->coursestarttime, $value->courseendtime);
    $optionurl = new moodle_url("/mod/booking/view.php?id={$value->cmid}&optionid={$value->optionid}&action=showonlyone&whichview=showonlyone#goenrol");
    echo "<li>[{$optionstatus}] <a href='{$optionurl}'>{$value->text}</a></li>";

    $cid = $value->courseid;
    $bid = $value->bookingid;
}

echo $OUTPUT->box_end();

echo $OUTPUT->footer();