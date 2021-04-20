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
 * This page allows a user to subscribe/unsubscribe other users from a booking option
 * TODO: upgrade logging, add logging for added/deleted users
 *
 * @author David Bogner davidbogner@gmail.com
 * @package mod/booking
 */
use mod_booking\booking_option;
use mod_booking\form\subscribegroup_form;

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT); // Course_module ID.
$optionid = required_param('optionid', PARAM_INT);

$url = new moodle_url('/mod/booking/subscribegroup.php', array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if (!$booking = new \mod_booking\booking($cm->id)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

$option = new booking_option($cm->id, $optionid, array(), 0, 0, false);

if (has_capability('mod/booking:subscribeusersgromgroup', $context)) {
    print_error('nopermissions');
}

$mform = new subscribegroup_form($url, array('bookingid' => $cm->instance, 'optionid' => $optionid, 'cm' => $cm, 'context' => $context));

if ($mform->is_cancelled()) {
    $redirecturl = new moodle_url('report.php', array('id' => $cm->id, 'optionid' => $optionid));
    redirect($redirecturl, '', 0);
} else if ($fromform = $mform->get_data()) {

    if (isset($fromform->groupid) && !empty($fromform->groupid)) {
        $option->book_from_group($fromform->groupid);
    }

    $redirecturl = new moodle_url('report.php', array('id' => $cm->id, 'optionid' => $optionid));
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