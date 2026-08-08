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
 * SofaTicket entry scanner page.
 *
 * Uses the device camera (getUserMedia + the standard BarcodeDetector API) to read a ticket QR,
 * then verifies it and checks the participant in via the mod_booking_verify_ticket webservice.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cmid);
require_capability('mod/booking:scanticket', $context);

$url = new moodle_url('/mod/booking/scan.php', ['id' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->activityheader->disable();
$PAGE->add_body_class('limitedwidth');
$PAGE->set_pagetype('mod-booking-scan');
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('ticketscanner', 'mod_booking'));
$PAGE->set_heading(get_string('ticketscanner', 'mod_booking'));

/** @var \mod_booking\output\renderer $output */
$output = $PAGE->get_renderer('mod_booking');

$templatedata = [
    'cmid' => $cmid,
];

echo $output->header();
echo $output->heading(get_string('ticketscanner', 'mod_booking'));

// Camera requires a secure context (HTTPS or localhost); warn otherwise.
$issecure = is_https() || strpos($CFG->wwwroot, 'http://localhost') === 0 || strpos($CFG->wwwroot, 'http://127.0.0.1') === 0;
if (!$issecure) {
    echo $OUTPUT->notification(get_string('ticketscannerhttpswarning', 'mod_booking'), 'warning');
}

echo $output->render_from_template('mod_booking/scanner', $templatedata);

$PAGE->requires->js_call_amd('mod_booking/scanner', 'init', [[
    'cmid' => $cmid,
    'serialscan' => (bool) get_config('booking', 'bookingticketserialscan'),
    'duplicatewindow' => (int) get_config('booking', 'bookingticketduplicatewindow'),
]]);

echo $output->footer();
