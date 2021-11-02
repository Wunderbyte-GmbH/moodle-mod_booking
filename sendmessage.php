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
require_once(__DIR__ . '/../../config.php');
require_once("lib.php");
require_once("sendmessageform.class.php");

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$uids = required_param('uids', PARAM_RAW);

$url = new moodle_url('/mod/booking/sendmessage.php',
        array('id' => $id, 'optionid' => $optionid, 'uids' => $uids));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

$strbooking = get_string('modulename', 'booking');

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:communicate', $context);

$defaultvalues = new stdClass();
$defaultvalues->optionid = $optionid;
$defaultvalues->id = $id;
$defaultvalues->uids = $uids;

$redirecturl = new moodle_url('report.php', array('id' => $id, 'optionid' => $optionid));

$mform = new mod_booking_sendmessage_form();

$PAGE->set_pagelayout('standard');

$PAGE->set_title(get_string('sendcustommessage', 'booking'));

if ($mform->is_cancelled()) {
    redirect($redirecturl, '', 0);
} else if ($data = $mform->get_data()) {
    // Clean form data.
    $cleanuids = clean_param_array(json_decode($uids), PARAM_INT);
    booking_sendcustommessage($optionid, $data->subject, $data->message, $cleanuids);

    redirect($redirecturl, get_string('messagesend', 'booking'), 5);
}

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

echo $OUTPUT->heading(get_string("sendcustommessage", "booking"), 2);

$mform->set_data($defaultvalues);
$mform->display();

echo $OUTPUT->footer();