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
 * Add dates to option.
 *
 * @package Booking
 * @copyright 2016 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");

global $DB, $PAGE, $OUTPUT, $USER;

$cmid = required_param('cmid', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$url = new moodle_url('/mod/booking/optionview.php', array('cmid' => $cmid, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

$bookingid = $cm->instance;

require_course_login($course, false, $cm);

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

// Check if optionid is valid.

$booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

if ($settings = singleton_service::get_instance_of_booking_option_settings($optionid)) {

    if ($userid == $USER->id || $userid == 0) {
        $user = $USER;
    } else {
        $user = singleton_service::get_instance_of_user($userid);
    }


    $bookinganswer = singleton_service::get_instance_of_booking_answers($settings, $user->id);

    $output = $PAGE->get_renderer('mod_booking');
    $data = new \mod_booking\output\bookingoption_description($booking, $settings->id,
                null, DESCRIPTION_WEBSITE, true, null, $user);

    $PAGE->navbar->add($data->title);
    $PAGE->set_title(format_string($data->title));
    $PAGE->set_heading($data->title);
    $PAGE->set_pagelayout('standard');
    echo $OUTPUT->header();
    echo $output->render_booking_option_view($data);

} else {
    $url = new moodle_url('mod/booking/view.php', ['id' => $cmid]);
    redirect($url);
}

echo $OUTPUT->footer();
