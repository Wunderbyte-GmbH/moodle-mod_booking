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

use mod_booking\all_options;
use mod_booking\booking;

global $DB, $CFG, $USER, $OUTPUT, $PAGE;

require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");
require_once($CFG->libdir . '/completionlib.php');
require_once("{$CFG->libdir}/tablelib.php");
require_once($CFG->dirroot . '/comment/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$action = optional_param('action', '', PARAM_ALPHA);
$optionid = optional_param('optionid', '', PARAM_INT);
$sessionid = optional_param('sessionid', '', PARAM_INT);
$fieldid = optional_param('fieldid', '', PARAM_INT);
$meetingtype = optional_param('meetingtype', '', PARAM_ALPHA);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'booking');

if ($action !== 'join') {
    die();
}

if (!$bookingoption = new \mod_booking\booking_option($cm->id, $optionid)) {
    die();
}

$booking = $bookingoption->booking;

$bu = new \mod_booking\booking_utils($booking, $bookingoption);
$userid = $USER->id;

$explanationstring = null;

// Only if there was a valid link and session is open, we redirect.
if ($link = $bu->show_conference_link($bookingoption, $userid, $sessionid)) {

    // We can find the actual link:
    if (!empty($fieldid)) {
        $link = $DB->get_field('booking_customfields', 'value', array('id' => $fieldid));
    } else {
        // If fieldid is not present, we'll use optionid, optiondateid and meetingtype to find the correct link.
        $customfields = $DB->get_records('booking_customfields', ['optionid' => $optionid, 'optiondateid' => $sessionid, 'cfgname' => $meetingtype]);
        $customfield = array_pop($customfields);
        $link = $customfield->value;
    }

    // Check if it's actually a link.
    if (filter_var($link, FILTER_VALIDATE_URL)) {
        header("Location: $link");
        exit();
    } else {
        $explanationstring = "Check your link, it doesn't seem to be valid: $link";
    }
}

$context = context_module::instance($cm->id);

require_login($course, false, $cm);

$url = new moodle_url('/mod/booking/link.php', [
        'id' => $id,
        'action' => 'join',
        'optionid' => $optionid
]);
$PAGE->set_url($url);

$PAGE->set_context($context);

echo $OUTPUT->header();

if (!$explanationstring) {
    if ($seconds = $bu->secondstostart) {
        $minutes = $bu->get_pretty_duration($seconds);
        $explanationstring = get_string('bookingnotopenyet', 'booking', $minutes);
    } else if ($minutes = $bu->secondspassed) {
        $explanationstring = get_string('bookingpassed', 'booking');
    } else {
        $explanationstring = get_string('linknotvalid', 'booking');
    }
}

$contents = html_writer::tag('p', $explanationstring);
$options = array('id' => $cm->id, 'optionid' =>$optionid, 'action' => 'showonlyone', 'whichview' => 'showonlyone');
$contents .= $OUTPUT->single_button(new moodle_url('view.php', $options),
        get_string('continue'), 'get');
echo $OUTPUT->box($contents, 'box generalbox', 'notice');
echo $OUTPUT->footer();
die();
