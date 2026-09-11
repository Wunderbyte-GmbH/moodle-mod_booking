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
 * Move slot action for booking answers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\local\slotbooking\slot_mover;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$baid = required_param('baid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
$canmoveslots = has_capability('mod/booking:moveslots', $context)
    || has_capability('mod/booking:updatebooking', $context);
if (!$canmoveslots) {
    require_capability('mod/booking:moveslots', $context);
}

// The booking owner whose slots are being edited (managers act on someone else's answer).
$movecontext = slot_mover::get_move_context($optionid, $baid);
$owneruserid = (int)$movecontext['answer']->userid;

$baseurl = new moodle_url('/mod/booking/moveslot.php', [
    'id' => $id,
    'optionid' => $optionid,
    'baid' => $baid,
]);
$returnurl = new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('slot_move_action', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Manager update: render the shared "Update booking" editor (move + cancel + change in one).
// The slotupdate_form DynamicForm and slot_update_service (actor=manager) own validation, routing,
// persistence and events; this page is just the host + post-commit redirect.
$containerid = 'booking-slotupdate-' . $optionid;
$PAGE->requires->js_call_amd('mod_booking/condition/slotUpdate', 'init', [$containerid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('slot_move_action', 'mod_booking'), 3);

echo html_writer::start_tag('div', [
    'id' => $containerid,
    'class' => 'booking-slotupdate-prepage booking-move-slot',
    'data-optionid' => $optionid,
    'data-userid' => $owneruserid,
    'data-baid' => $baid,
    'data-selfservice' => '0',
    'data-returnurl' => $returnurl->out(false),
]);
echo html_writer::tag('p', get_string('slot_update_tab', 'mod_booking'), ['class' => 'mb-2']);
echo html_writer::tag('div', '', ['data-region' => 'slotupdate-form']);
echo html_writer::tag('button', get_string('slot_update_button', 'mod_booking'), [
    'type' => 'button',
    'class' => 'btn btn-primary mt-2',
    'data-action' => 'slotupdate-submit',
]);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
