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
 * Index page
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once("lib.php");


$erlid = required_param('erlid', PARAM_TEXT); // Course id.

$PAGE->set_url('/mod/booking/enrollink.php', ['erlid' => $erlid]);
$complete = true;
// TODO: Logik in eigene Klasse. Auch aus customform.
$bundle = $DB->get_record('booking_enrollink_bundles', ['erlid' => $erlid], '*', MUST_EXIST);
// Optionid fetchen -> CMID von Buchungsinstanz setzen: $PAGE->set_context().
$itemsconsumed = $DB->get_records('booking_enrollink_items', ['erlid' => $erlid, 'consumed' => 1]);

if ($bundle->places <= count($itemsconsumed)) {
    // TODO: Display Error Message. "No more seats available".
    echo $OUTPUT->header();
    echo $OUTPUT->heading("No more seats available");
    echo $OUTPUT->footer();
    return;
}

require_login();
global $USER;
if (!isloggedin() || isguestuser()) {
    $info = 'notloggedin';
    //TODO: Display Error Message. "You need to be logged in to use this feature".
    echo $OUTPUT->header();
    echo $OUTPUT->heading("You need to be logged in to use this feature");
    echo $OUTPUT->footer();
    return;
}

$userid = $USER->id;
$courseid = $bundle->courseid;

// Check if the user is already enrolled in the course.
$context = context_course::instance($bundle->courseid);
if (is_enrolled($context, $USER)) {
    $info = 'alreadyenroled';
    echo $OUTPUT->header();
    echo $OUTPUT->heading("You are already enrolled in this course");
    echo $OUTPUT->footer();
    return;
}

// Get the manual enrolment plugin.
$enrolmanual = enrol_get_plugin('manual');
if (!$enrolmanual) {
    throw new moodle_exception('Manual enrolment plugin is disabled.');
}

// Fetch the enrolment instance for the course.
$instances = enrol_get_instances($courseid, true);
$manualinstance = null;
foreach ($instances as $instance) {
    if ($instance->enrol === 'manual') {
        $manualinstance = $instance;
        break;
    }
}

if (!$manualinstance) {
    throw new moodle_exception('No manual enrolment instance found for the course.');
}

//TODO: Timeend: Selbstlernkurs Beschränkung von Bernhard.

// Enrol the user into the course.
$enrolmanual->enrol_user(
    $manualinstance,
    $userid,
    5, // Role ID (e.g., 5 = student).
    time() // Enrolment start time.
);
$data = (object) [
    'erlid' => $erlid,
    'userid' => $userid,
    'consumed' => 1,
    'timecreated' => time(),
    'timemodified' => time(),
];
// Update records.
$DB->insert_record('booking_enrollink_items', $data);


echo $OUTPUT->header();
echo $OUTPUT->heading("User enrolled successfully!");
// Link to course.
echo $OUTPUT->footer();
