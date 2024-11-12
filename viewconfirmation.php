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
 * View confirmation message
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2016 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

use mod_booking\booking_option;
use mod_booking\message_controller;
use mod_booking\singleton_service;
use mod_booking\placeholders\placeholders_info;

$cmid = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT); // Option ID.

$url = new moodle_url('/mod/booking/viewconfirmation.php',
        ['id' => $cmid, 'optionid' => $optionid]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

if (!$bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cmid)) {
    throw new moodle_exception('badcontext');
}

$PAGE->navbar->add(get_string("bookedtext", "booking"));
$PAGE->set_title(get_string("bookedtext", "booking"));
$PAGE->set_heading(get_string("bookedtext", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("bookedtext", "booking"), 3, 'helptitle', 'uniqueid');

$settings = singleton_service::get_instance_of_booking_option_settings($optionid);
$answer = singleton_service::get_instance_of_booking_answers($settings);

$userid = $USER->id;
if (isset($answer->usersonlist[$userid])) {
    $text = get_string('viewconfirmationbooked', 'booking');
} else if (isset($answer->usersonwaitinglist[$userid])) {
    $text = get_string('viewconfirmationwaiting', 'booking');
} else {
    echo $OUTPUT->error_text(get_string("notbooked", "booking"));
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $course->id]));
    echo $OUTPUT->footer();
    return;
}

$text = placeholders_info::render_text($text, $cmid, $optionid);

echo "{$text}";

echo $OUTPUT->footer();
