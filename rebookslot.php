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
 * Self-service rebooking action for the participant's own booked slot.
 *
 * Mirrors moveslot.php but is gated by ownership and the per-option opt-in plus the
 * mod/booking:moveslotsself capability, and persists through slot_mover::move_self().
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
require_capability('mod/booking:moveslotsself', $context);

$answer = $DB->get_record('booking_answers', ['id' => $baid, 'optionid' => $optionid], '*', MUST_EXIST);
if ((int)$answer->userid !== (int)$USER->id || !slot_mover::self_rebooking_allowed($optionid, $answer)) {
    throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
}

$baseurl = new moodle_url('/mod/booking/rebookslot.php', [
    'id' => $id,
    'optionid' => $optionid,
    'baid' => $baid,
]);
$returnurl = new moodle_url('/mod/booking/view.php', ['id' => $id]);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('slot_rebook_action', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Self-service update: render the shared "Update booking" editor (move + cancel + change in one).
// The slotupdate_form DynamicForm and slot_update_service (actor=self) own validation, routing,
// persistence and events; this page is just the host + post-commit redirect.
$containerid = 'booking-slotupdate-' . $optionid;
$PAGE->requires->js_call_amd('mod_booking/condition/slotUpdate', 'init', [$containerid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('slot_rebook_action', 'mod_booking'), 3);

echo html_writer::start_tag('div', [
    'id' => $containerid,
    'class' => 'booking-slotupdate-prepage booking-rebook-slot',
    'data-optionid' => $optionid,
    'data-userid' => (int)$answer->userid,
    'data-baid' => $baid,
    'data-selfservice' => '1',
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
