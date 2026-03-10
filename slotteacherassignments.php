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

use mod_booking\booking_option;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->dirroot . '/user/lib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

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

$slotconfig = $bookingoption->settings->slotconfig ?? null;
$teacherpool = [];
if (!empty($slotconfig) && !empty($slotconfig->teacher_pool)) {
    $teacherpool = json_decode((string)$slotconfig->teacher_pool, true);
    if (!is_array($teacherpool)) {
        $teacherpool = [];
    }
}
$teacherpool = array_values(array_unique(array_filter(array_map('intval', $teacherpool), static function (int $teacherid): bool {
    return $teacherid > 0;
})));

$students = get_enrolled_users(
    $context,
    'mod/booking:choose',
    0,
    'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.idnumber, u.email'
);
usort($students, static function (stdClass $a, stdClass $b): int {
    return strcasecmp(fullname($a), fullname($b));
});

$studentids = array_values(array_map(static function (stdClass $student): int {
    return (int)$student->id;
}, $students));
$studentlookup = array_fill_keys($studentids, true);

$teachers = !empty($teacherpool) ? user_get_users_by_id($teacherpool) : [];
$teacheroptions = [];
foreach ($teacherpool as $teacherid) {
    if (empty($teachers[$teacherid])) {
        continue;
    }
    $teacheroptions[$teacherid] = fullname($teachers[$teacherid]);
}

if ($action === 'save' && confirm_sesskey()) {
    $transaction = $DB->start_delegated_transaction();

    $DB->delete_records('booking_slot_student_teacher', ['optionid' => $optionid]);

    $now = time();
    foreach ($studentids as $studentid) {
        $selectedteachers = optional_param_array('teachers_' . $studentid, [], PARAM_INT);
        $selectedteachers = array_values(array_unique(array_filter(
            array_map('intval', $selectedteachers),
            static function (int $teacherid) use ($teacheroptions): bool {
                return $teacherid > 0 && isset($teacheroptions[$teacherid]);
            }
        )));

        foreach ($selectedteachers as $teacherid) {
            if (!isset($studentlookup[$studentid])) {
                continue;
            }

            $record = (object)[
                'optionid' => $optionid,
                'userid' => $studentid,
                'teacherid' => $teacherid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('booking_slot_student_teacher', $record);
        }
    }

    $transaction->allow_commit();

    redirect(
        new moodle_url('/mod/booking/slotteacherassignments.php', ['id' => $id, 'optionid' => $optionid]),
        get_string('slot_student_teacher_assignments_saved', 'mod_booking'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$existingrecords = $DB->get_records('booking_slot_student_teacher', ['optionid' => $optionid], '', 'id, userid, teacherid');
$assignedbystudent = [];
foreach ($existingrecords as $record) {
    $studentid = (int)$record->userid;
    $teacherid = (int)$record->teacherid;
    if (!isset($assignedbystudent[$studentid])) {
        $assignedbystudent[$studentid] = [];
    }
    if (isset($teacheroptions[$teacherid])) {
        $assignedbystudent[$studentid][] = $teacherid;
    }
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

if (empty($teacheroptions)) {
    echo $OUTPUT->notification(get_string('slot_student_teacher_assignments_no_teachers', 'mod_booking'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

if (empty($students)) {
    echo $OUTPUT->notification(get_string('slot_student_teacher_assignments_no_students', 'mod_booking'), 'info');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('slot_student_teacher_assignments_desc', 'mod_booking'));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $baseurl->out(false),
]);

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'optionid', 'value' => $optionid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('fullnameuser'),
    get_string('slot_student_teacher_assignments_teachers', 'mod_booking'),
];

$rows = [];
foreach ($students as $student) {
    $studentid = (int)$student->id;
    $selected = $assignedbystudent[$studentid] ?? [];

    $studentlabel = html_writer::div(fullname($student));
    if (!empty($student->email)) {
        $studentlabel .= html_writer::div(s($student->email), 'text-muted small');
    }

    $select = html_writer::select(
        $teacheroptions,
        'teachers_' . $studentid . '[]',
        $selected,
        false,
        [
            'multiple' => 'multiple',
            'size' => min(8, max(4, count($teacheroptions))),
            'class' => 'custom-select',
            'style' => 'min-width: 280px;',
        ]
    );

    $rows[] = new html_table_row([
        $studentlabel,
        $select,
    ]);
}

$table->data = $rows;
echo html_writer::table($table);

echo html_writer::start_div('mt-3');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('savechanges'),
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
