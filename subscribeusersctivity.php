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
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

use mod_booking\utils\db;
use mod_booking\booking_option;
use mod_booking\form\subscribeusersctivity;

$id = required_param('id', PARAM_INT); // Course_module ID.
$optionid = required_param('optionid', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_login($course, true, $cm);

// Print the page header.
$context = context_module::instance($cm->id);
$PAGE->set_context($context);

$bookingoption = new booking_option($id, $optionid);
$url = new moodle_url('/mod/booking/subscribeusersctivity.php', array('id' => $id, 'optionid' => $optionid));
$backurl = new moodle_url('/mod/booking/report.php', array('id' => $cm->id, 'optionid' => $optionid));
$errorurl = new moodle_url('/mod/booking/view.php', array('id' => $id));

if (!booking_check_if_teacher ($bookingoption->option)) {
    if (!(has_capability('mod/booking:subscribeusers', $context) || has_capability('moodle/site:accessallgroups', $context))) {
        throw new moodle_exception('nopermissions', 'core', $errorurl, get_string('bookotherusers', 'mod_booking'));
    }
}

$mform = new subscribeusersctivity($url, array('optionid' => $optionid, 'bookingid' => $bookingoption->booking->id));

if ($mform->is_cancelled()) {
    redirect($backurl, '', 0);
} else if ($fromform = $mform->get_data()) {
    $dbutill = new db();
    $totransfer = $dbutill->getusersactivity($id, $fromform->bookingoption, false);

    $oldbookingoption = new booking_option($id, $fromform->bookingoption);
    $oldbookingoption->transfer_users_to_otheroption($optionid, $totransfer);

    redirect($backurl, get_string('sucesfullytransfered', 'booking'), 0);
}

$PAGE->set_url($url);
$PAGE->set_title(get_string('modulename', 'booking'));
$PAGE->set_heading($COURSE->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading("{$bookingoption->option->text}", 3, 'helptitle', 'uniqueid');

$mform->display();

echo $OUTPUT->footer();
