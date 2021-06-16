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

require_once("../../config.php");
require_once("locallib.php");
require_once($CFG->libdir . '/completionlib.php');
require_once("{$CFG->libdir}/tablelib.php");
require_once($CFG->dirroot . '/comment/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$action = optional_param('action', '', PARAM_ALPHA);
$optionid = optional_param('optionid', '', PARAM_INT);
$userid = optional_param('userid', '', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'booking');

if ($action !== 'join') {
    die();
}

$bu = new \mod_booking\booking_utils();

if ($link = $bu->show_conference_link($cm->id, $optionid, $userid)) {
    header("Location: https://www.wunderbyte.at");
    exit();
} else {

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

    // Todo: Calculate minutes to event start
    if ($minutes = $bu->minutestostart) {
        $explanationstring = get_string('bookingnotopenyet', 'booking', $minutes);
    } else if ($minutes = $bu->minutespassed) {
        $explanationstring = get_string('bookingpassed', 'booking', $minutes);
    } else {
        $explanationstring = get_string('linknotvalid', 'booking');
    }

    $contents = html_writer::tag('p', $explanationstring);
    $options = array('id' => $cm->id, 'optionid' =>$optionid, 'action' => 'showonlyone', 'whichview' => 'showonlyone');
    $contents .= $OUTPUT->single_button(new moodle_url('view.php', $options),
            get_string('continue'), 'get');
    echo $OUTPUT->box($contents, 'box generalbox', 'notice');
    echo $OUTPUT->footer();
    die();
}