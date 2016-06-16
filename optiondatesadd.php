<?php

/**
 * Add dates to option.
 *
 * @package   Booking
 * @copyright 2016 Andraž Prinčič www.princic.net
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
require_once("../../config.php");
require_once("lib.php");
require_once("locallib.php");
require_once('optiondatesadd_form.php');

$cmid = required_param('cmid', PARAM_INT);                 // Course Module ID
$boptionid = required_param('boptionid', PARAM_INT);
$oid = optional_param('oid', '', PARAM_INT);

$url = new moodle_url('/mod/booking/optiondatesadd.php', array('cmid' => $cmid, 'boptionid' => $boptionid));
$urlRedirect = new moodle_url('/mod/booking/optiondates.php', array('id' => $cmid, 'optionid' => $boptionid));
$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('booking', $cmid)) {
    print_error("Course Module ID was incorrect");
}

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);

if (!$booking = booking_get_booking($cm, '', array(), true, null, false)) {
    error("Course module is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("addnewoptiondates", "booking"));
$PAGE->set_title(format_string(get_string("addnewoptiondates", "booking")));
$PAGE->set_heading(get_string("addnewoptiondates", "booking"));
$PAGE->set_pagelayout('standard');

$mform = new optiondatesadd_form($url);

if ($mform->is_cancelled()) {
    //Handle form cancel operation, if cancel button is present on form
    redirect($urlRedirect, '', 0);
    die;
} else if ($data = $mform->get_data()) {

    // Add new record
    $tag = new stdClass();
    $tag->id = $data->id;
    $tag->bookingid = $booking->id;
    $tag->optionid = $boptionid;
    $tag->coursestarttime = $data->coursestarttime;
    $tag->courseendtime = $data->courseendtime;

    if ($tag->id != '') {
        $DB->update_record("booking_optiondates", $tag);
    } else {
        $DB->insert_record("booking_optiondates", $tag);
    }

    booking_updatestartenddate($boptionid);
    
    redirect($urlRedirect, get_string('optiondatessucesfullysaved', 'booking'), 5);
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("addnewoptiondates", "booking"), 3, 'helptitle', 'uniqueid');

    $default_values = new stdClass();
    if ($oid != '') {
        $default_values = $DB->get_record('booking_optiondates', array('id' => $oid));
    }

    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    //displays the form
    $mform->set_data($default_values);
    $mform->display();
}

echo $OUTPUT->footer();
