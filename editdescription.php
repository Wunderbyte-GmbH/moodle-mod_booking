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

use mod_booking\form\editdescription_form;

$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_INT);

$url = new moodle_url('/mod/booking/editdescription.php', array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = new \mod_booking\booking($cm->id)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    print_error('nopermissions');
}

$mform = new editdescription_form($url, array('bookingid' => $cm->instance, 'optionid' => $optionid, 'cmid' => $cm->id, 'context' => $context));

if ($optionid > 0 && $defaultvalues = $DB->get_record('booking_options', array('bookingid' => $booking->settings->id, 'id' => $optionid))) {
    $defaultvalues->optionid = $optionid;
    $defaultvalues->bookingname = $booking->settings->name;
    $defaultvalues->id = $cm->id;
}

if ($mform->is_cancelled()) {
    $redirecturl = new moodle_url('view.php', array('id' => $cm->id));
    redirect($redirecturl, '', 0);
} else if ($fromform = $mform->get_data()) {

    $dataobject = (object) ['id' => $optionid, 'description' => $fromform->description];

    $DB->update_record('booking_options', $dataobject);

    $redirecturl = new moodle_url('view.php', array('id' => $cm->id));
    redirect($redirecturl, get_string('changessaved'), 0);
} else {
    $PAGE->set_title(format_string($booking->settings->name));
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    if (isset($defaultvalues)) {
        $mform->set_data($defaultvalues);
    }
    $mform->display();
}

echo $OUTPUT->footer();
