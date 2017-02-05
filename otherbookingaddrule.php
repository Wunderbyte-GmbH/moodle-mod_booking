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
 * Add new rule.
 *
 * @package Booking
 * @copyright 2016 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("locallib.php");
require_once('otherbookingaddrule_form.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$optionid = required_param('optionid', PARAM_INT); // Option ID
$obid = optional_param('obid', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$url = new moodle_url('/mod/booking/otherbookingaddrule.php',
        array('id' => $id, 'optionid' => $optionid, 'obid' => $obid));
$urlRedirect = new moodle_url('/mod/booking/otherbooking.php',
        array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

if ($delete == 1) {
    $DB->delete_records("booking_other", array("id" => $obid));

    redirect($urlRedirect, get_string("deletedrule", "booking"), 5);
}

$PAGE->navbar->add(get_string("otherbookingaddrule", "booking"));
$PAGE->set_title(format_string(get_string("otherbookingaddrule", "booking")));
$PAGE->set_heading(get_string("otherbookingaddrule", "booking"));
$PAGE->set_pagelayout('standard');

$mform = new otherbookingaddrule_form($url->out(false),
        array('id' => $id, 'optionid' => $optionid));

if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form
    redirect($urlRedirect, '', 0);
    die();
} else if ($data = $mform->get_data()) {

    // Add new record
    $rule = new stdClass();
    $rule->id = $data->id;
    $rule->optionid = $optionid;
    $rule->otheroptionid = (int) $data->otheroptionid;
    $rule->userslimit = $data->userslimit;

    if ($rule->id > 0) {
        $DB->update_record("booking_other", $rule);
    } else {
        $DB->insert_record("booking_other", $rule);
    }

    redirect($urlRedirect, get_string('otherbookingsucesfullysaved', 'booking'), 5);
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("otherbookingaddrule", "booking"), 3, 'helptitle', 'uniqueid');

    $default_values = new stdClass();
    if ($obid > 0) {
        $default_values = $DB->get_record('booking_other', array('id' => $obid));
    }

    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    // displays the form
    $mform->set_data($default_values);
    $mform->display();
}

echo $OUTPUT->footer();
