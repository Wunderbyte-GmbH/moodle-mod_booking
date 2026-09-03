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
 * Slot calendar page.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\local\slotbooking\slot_dto;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/booking:view', $context);

// Only used to decide whether there are booked slots to show; the report module
// loads the full slot data on demand via the mod_booking_get_booked_slots webservice.
$hasbookedslots = !empty(slot_dto::build_report_slots($optionid, $id)['slots']);

$baseurl = new moodle_url('/mod/booking/slotcalendar.php', [
    'id' => $id,
    'optionid' => $optionid,
]);
$reporturl = new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('slot_calendar_title', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$containerid = 'booking-slot-calendar-report';
$PAGE->requires->js_call_amd('mod_booking/slotCalendarReport', 'init', [$containerid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('slot_calendar_title', 'mod_booking'), 3);

echo html_writer::div(
    html_writer::link($reporturl, get_string('back')),
    'mb-3'
);

if (!$hasbookedslots) {
    echo html_writer::div(get_string('none'), 'alert alert-info');
} else {
    echo html_writer::start_tag('div', [
        'id' => $containerid,
        'class' => 'booking-slotcalendar-report',
        'data-cmid' => $id,
        'data-optionid' => $optionid,
        'data-students-label' => get_string('bookedusers', 'mod_booking'),
        'data-teachers-label' => get_string('slot_calendar_teachers', 'mod_booking'),
        'data-occupancy-label' => get_string('slot_calendar_occupancy', 'mod_booking'),
        'data-price-label' => get_string('bookingoptionprice', 'mod_booking'),
        'data-moveslot-label' => get_string('slot_move_action', 'mod_booking'),
        'data-selectslot-label' => get_string('slot_calendar_select_slot', 'mod_booking'),
        'data-nobookedslots-label' => get_string('slot_calendar_no_booked_slots', 'mod_booking'),
        'data-none-label' => get_string('none'),
    ]);
    echo html_writer::tag('div', '', ['data-region' => 'slot-calendar-picker']);
    echo html_writer::tag('div', '', ['data-region' => 'slot-calendar-students', 'class' => 'mt-3']);
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();
