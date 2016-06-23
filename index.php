<?php

// $Id: index.php,v 1.32.2.6 2008/02/26 23:19:05 skodak Exp $

require_once("../../config.php");
require_once("lib.php");

$id = required_param('id', PARAM_INT);   // course

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
if (isloggedin() and ! isguestuser() and $allanswers = $DB->get_records_sql($sql)) {
    foreach ($allanswers as $aa) {
        $answers[$aa->bookingid] = $aa;
    }
    unset($allanswers);
}

$table = new html_table();

$timenow = time();

if ($course->format == "weeks") {
    $table->head = array(get_string("week"), get_string("question"), get_string("answer"));
    $table->align = array("center", "left", "left");
} else if ($course->format == "topics") {
    $table->head = array(get_string("topic"), get_string("question"), get_string("answer"));
    $table->align = array("center", "left", "left");
} else {
    $table->head = array(get_string("question"), get_string("answer"));
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

    //Calculate the href
    if (!$booking->visible) {
        //Show dimmed if the mod is hidden
        $tt_href = "<a class=\"dimmed\" href=\"view.php?id=$booking->coursemodule\">" . format_string($booking->name, true) . "</a>";
    } else {
        //Show normal if the mod is visible
        $tt_href = "<a href=\"view.php?id=$booking->coursemodule\">" . format_string($booking->name, true) . "</a>";
    }

    if ($course->format == "weeks" || $course->format == "topics") {
        $table->data[] = array($printsection, $tt_href, $aa);
    } else {
        $table->data[] = array($tt_href, $aa);
    }
}
echo "<br />";
echo html_writer::table($table);

echo $OUTPUT->footer();
?>
