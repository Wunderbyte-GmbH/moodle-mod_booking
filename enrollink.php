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
 * Enrollink page
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use mod_booking\enrollink;

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");


$erlid = optional_param('erlid', '', PARAM_TEXT); // Course id.

if (empty($erlid)) {
    redirect(new moodle_url('/'));
}

$enrollink = enrollink::get_instance($erlid);

// Check if there are conditions blocking before login is required.
$info = $enrollink->enrolment_blocking();
if (!empty($info)) {
    $infostring = $enrollink->get_readable_info($info);
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url('/mod/booking/enrollink.php', ['erlid' => $erlid]);
    $output = $PAGE->get_renderer('mod_booking');
    echo $OUTPUT->header();
    echo $output->render_from_template(
        'mod_booking/enrollink',
        [
            'info' => $infostring,
            'error' => 1,
            ]
    );
} else {
    require_login($courseid = 0, $autologinguest = true, $cm = null, $setwantsurltome = true);

    $PAGE->set_context(context_system::instance());
    $PAGE->set_url('/mod/booking/enrollink.php', ['erlid' => $erlid]);

    echo $OUTPUT->header();
    $output = $PAGE->get_renderer('mod_booking');
    global $USER;
    $info = $enrollink->enrol_user($USER->id);
    $courselink = $enrollink->get_courselink_url();
    $bodetailslink = $enrollink->get_bookingdetailslink_url();
    $title = $enrollink->get_bookingoptiontitle();
    $infostring = $enrollink->get_readable_info($info);
    echo $output->render_from_template(
        'mod_booking/enrollink',
        [
            'info' => $infostring,
            'error' => $info == "enrolmentexception" ? 1 : 0,
            'courselink' => $courselink ?? false,
            'bodetailslink' => $bodetailslink ?? false,
            'namebookingoption' => $title,
        ]
    );
}

echo $OUTPUT->footer();
