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
require_once("lib.php");

$id = required_param('id', PARAM_INT); // course

$PAGE->set_url('/mod/booking/index.php', array('id' => $id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');

$strbooking = get_string("modulename", "booking");
$strbookings = get_string("modulenameplural", "booking");
$PAGE->set_title($strbookings);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($strbookings);
echo $OUTPUT->header();

if (!$bookings = get_all_instances_in_course("booking", $course)) {
    notice(get_string('thereareno', 'moodle', $strbookings), "../../course/view.php?id=$course->id");
}

$usesections = course_format_uses_sections($course->format);
if ($usesections) {
    $sections = get_fast_modinfo($course->id)->get_section_info_all();
}

$sql = "SELECT cha.*
FROM {booking} AS ch, {booking_answers} AS cha
WHERE cha.bookingid = ch.id AND
ch.course = $course->id AND cha.userid = $USER->id";

$answers = array();
if (isloggedin() and !isguestuser() and $allanswers = $DB->get_records_sql($sql)) {
    foreach ($allanswers as $aa) {
        $answers[$aa->bookingid] = $aa;
    }
    unset($allanswers);
}

$table = new html_table();

$timenow = time();

if ($course->format == "weeks") {
    $table->head = array(get_string("week", "booking"), get_string("question", "booking"),
                    get_string("answer", "booking"));
    $table->align = array("center", "left", "left");
} else if ($course->format == "topics") {
    $table->head = array(get_string("topic", "booking"), get_string("question", "booking"),
                    get_string("answer", "booking"));
    $table->align = array("center", "left", "left");
} else {
    $table->head = array(get_string("question", "booking"), get_string("answer", "booking"));
    $table->align = array("left", "left");
}

$currentsection = "";

foreach ($bookings as $booking) {
    if (!empty($answers[$booking->id])) {
        $answer = $answers[$booking->id];
    } else {
        $answer = "";
    }
    if (!empty($answer->optionid)) {
        $aa = format_string(booking_get_option_text($booking, $answer->optionid));
    } else {
        $aa = "";
    }
    $printsection = "";
    if ($booking->section !== $currentsection) {
        if ($booking->section) {
            $printsection = $sections[$booking->section]->name;
        }
        if ($currentsection !== "") {
            $table->data[] = 'hr';
        }
        $currentsection = $booking->section;
    }

    // Calculate the href
    if (!$booking->visible) {
        // Show dimmed if the mod is hidden
        $tthref = "<a class=\"dimmed\" href=\"view.php?id=$booking->coursemodule\">" .
        format_string($booking->name, true) . "</a>";
    } else {
        // Show normal if the mod is visible
        $tthref = "<a href=\"view.php?id=$booking->coursemodule\">" .
        format_string($booking->name, true) . "</a>";
    }

    if ($course->format == "weeks" || $course->format == "topics") {
        $table->data[] = array($printsection, $tthref, $aa);
    } else {
        $table->data[] = array($tthref, $aa);
    }
}
echo "<br />";
echo html_writer::table($table);

echo $OUTPUT->footer();
