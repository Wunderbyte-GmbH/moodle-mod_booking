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
 * Manage teacher unavailability blocks for slot booking.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_option;
use mod_booking\form\teacherunavailability_form;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$scopeoptionid = optional_param('scopeoptionid', 0, PARAM_INT);
$teacherid = optional_param('teacherid', 0, PARAM_INT);
$date = optional_param('date', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
$bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);

$isteacherofoption = booking_check_if_teacher($bookingoption->settings);
$canmanageunavailability =
    has_capability('mod/booking:manageslotunavailability', $context)
    || has_capability('mod/booking:updatebooking', $context);

if (!$canmanageunavailability && !$isteacherofoption) {
    require_capability('mod/booking:manageslotunavailability', $context);
}

if (empty($teacherid)) {
    $teacherid = $USER->id;
}

if (!$canmanageunavailability && $teacherid !== (int)$USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', null, get_string('slot_error_editownonly', 'mod_booking'));
}

if (empty($date)) {
    $date = time();
}
$teacher = core_user::get_user($teacherid, '*', MUST_EXIST);

$baseurl = new moodle_url('/mod/booking/teacherunavailability.php', [
    'id' => $id,
    'optionid' => $optionid,
    'scopeoptionid' => $scopeoptionid,
    'teacherid' => $teacherid,
]);
$reporturl = new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('slot_teacher_unavailability', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('slot_teacher_unavailability', 'mod_booking'), 3);
echo html_writer::div(
    get_string('slot_teacher_unavailability_for', 'mod_booking', fullname($teacher)),
    'mb-3'
);

echo html_writer::tag('h4', get_string('slot_unavailability_blocks', 'mod_booking'));

$formparams = [
    'id' => $id,
    'optionid' => $scopeoptionid,
    'teacherid' => $teacherid,
    'date' => $date,
];
$form = new teacherunavailability_form(null, null, 'post', '', [], true, $formparams);
$form->set_data_for_dynamic_submission();

$formcontainerid = 'teacher-unavailability-dynamic-form';
echo html_writer::div($form->render(), '', [
    'id' => $formcontainerid,
    'data-id' => $id,
    'data-optionid' => $scopeoptionid,
    'data-teacherid' => $teacherid,
    'data-date' => $date,
    'data-reporturl' => $reporturl->out(false),
]);

$PAGE->requires->js_call_amd('mod_booking/teacherUnavailability', 'init', [$formcontainerid]);

echo $OUTPUT->footer();
