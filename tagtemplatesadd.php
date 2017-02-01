<?php
/**
 * Import options or just add new users from CSV
 *
 * @package Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("lib.php");
require_once("locallib.php");
require_once('tagtemplatesadd_form.php');

$cmid = required_param('cmid', PARAM_INT); // Course Module ID
$tid = optional_param('tid', '', PARAM_INT);

$url = new moodle_url('/mod/booking/tagtemplatesadd.php', array('cmid' => $cmid, 'tid' => $tid));
$urlRedirect = new moodle_url('/mod/booking/tagtemplates.php', array('cmid' => $cmid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("addnewtagtemplate", "booking"));
$PAGE->set_title(format_string(get_string("addnewtagtemplate", "booking")));
$PAGE->set_heading(get_string("addnewtagtemplate", "booking"));
$PAGE->set_pagelayout('standard');

$mform = new tagtemplatesadd_form($url);

if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form
    redirect($urlRedirect, '', 0);
    die();
} else if ($data = $mform->get_data()) {

    // Add new record
    $tag = new stdClass();
    $tag->id = $data->id;
    $tag->courseid = $cm->course;
    $tag->tag = $data->tag;
    $tag->text = $data->text;
    $tag->textformat = FORMAT_HTML;

    if ($tag->id != '') {
        $DB->update_record("booking_tags", $tag);
    } else {
        $DB->insert_record("booking_tags", $tag);
    }

    redirect($urlRedirect, get_string('tagsucesfullysaved', 'booking'), 5);
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("addnewtagtemplate", "booking"), 3, 'helptitle', 'uniqueid');

    $default_values = new stdClass();
    if ($tid != '') {
        $default_values = $DB->get_record('booking_tags', array('id' => $tid));
        $default_values->text = array('text' => $default_values->text, 'format' => FORMAT_HTML);
    }

    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    // displays the form
    $mform->set_data($default_values);
    $mform->display();
}

echo $OUTPUT->footer();
