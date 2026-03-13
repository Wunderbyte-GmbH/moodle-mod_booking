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
 * Slot booking student teacher assignments page.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
$bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);
$isteacherofoption = booking_check_if_teacher($bookingoption->settings);
$canmanage = is_siteadmin()
    || has_capability('mod/booking:manageslotunavailability', $context)
    || has_capability('mod/booking:updatebooking', $context);

if (!$canmanage && !$isteacherofoption) {
    require_capability('mod/booking:manageslotunavailability', $context);
}

$baseurl = new moodle_url('/mod/booking/slotteacherassignments.php', [
    'id' => $id,
    'optionid' => $optionid,
]);
$reporturl = new moodle_url('/mod/booking/report.php', [
    'id' => $id,
    'optionid' => $optionid,
]);

$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('slot_student_teacher_assignments', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));

$form = new \mod_booking\form\slotteacherassignments_form(
    $baseurl->out(false),
    ['id' => $id, 'optionid' => $optionid]
);

if ($form->is_cancelled()) {
    redirect($reporturl);
}

if ($data = $form->get_data()) {
    $result = $form->process_dynamic_submission();
    redirect(
        $baseurl,
        $result->message ?? get_string('slot_student_teacher_assignments_saved', 'mod_booking'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('slot_student_teacher_assignments', 'mod_booking'), 3);

echo html_writer::div(
    html_writer::link($reporturl, get_string('back')),
    'mb-3'
);

echo html_writer::div(
    format_string($bookingoption->option->text ?? ''),
    'mb-3'
);

echo html_writer::tag('p', get_string('slot_student_teacher_assignments_desc', 'mod_booking'));

$form->set_data_for_dynamic_submission();
echo $form->render();

echo $OUTPUT->footer();
