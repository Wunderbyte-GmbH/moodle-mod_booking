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
 * Started for one booking option (optionid) it only accepts tickets of that option and offers the
 * option's dates for the check-in; started for a booking instance (id) it accepts any ticket of the
 * instance. Who may scan is decided by ticket_manager::can_scan().
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_booking\local\ticket\ticket_manager;
use mod_booking\singleton_service;

$cmid = optional_param('id', 0, PARAM_INT);
$optionid = optional_param('optionid', 0, PARAM_INT);

$settings = null;
if (!empty($optionid)) {
    $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
    if (empty($settings->id)) {
        throw new moodle_exception('invalidrecord', 'error', '', 'booking_options');
    }
    $cmid = (int) $settings->cmid;
}
if (empty($cmid)) {
    throw new moodle_exception('missingparam', 'error', '', 'id');
}

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cmid);
ticket_manager::require_can_scan($cmid, $optionid);

$urlparams = $optionid ? ['optionid' => $optionid] : ['id' => $cmid];
$url = new moodle_url('/mod/booking/scan.php', $urlparams);
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
    'optionid' => $optionid,
];

echo $output->header();
echo $output->heading(get_string('ticketscanner', 'mod_booking'));

$initialdateid = 0;
if ($settings !== null) {
    $templatedata['optionname'] = format_string($settings->get_title_with_prefix());
    // Offer the option's dates before the first scan, the nearest one preselected.
    $initialdateid = ticket_manager::pick_nearest_optiondate(
        ticket_manager::real_sessions($settings->sessions ?? [])
    );
    $dates = [];
    foreach (ticket_manager::get_scan_dates($optionid) as $date) {
        $date['selected'] = $date['optiondateid'] === $initialdateid;
        $dates[] = $date;
    }
    $templatedata['dates'] = $dates;
    $templatedata['hasdates'] = !empty($dates);

    // Outside the configured window the scanner is not offered at all.
    $window = ticket_manager::get_scan_window($optionid);
    if (!$window['open']) {
        $message = get_string('ticketscannerclosed', 'mod_booking');
        if (!empty($window['nextopen'])) {
            $message .= ' ' . get_string('ticketscannerclosednextopen', 'mod_booking', userdate($window['nextopen']));
        }
        echo html_writer::tag('p', $templatedata['optionname'], ['class' => 'lead']);
        echo $OUTPUT->notification($message, 'warning');
        echo $output->footer();
        die();
    }
}

// Camera requires a secure context (HTTPS or localhost); warn otherwise.
$issecure = is_https() || strpos($CFG->wwwroot, 'http://localhost') === 0 || strpos($CFG->wwwroot, 'http://127.0.0.1') === 0;
if (!$issecure) {
    echo $OUTPUT->notification(get_string('ticketscannerhttpswarning', 'mod_booking'), 'warning');
}

echo $output->render_from_template('mod_booking/scanner', $templatedata);

$PAGE->requires->js_call_amd('mod_booking/scanner', 'init', [[
    'cmid' => $cmid,
    'optionid' => $optionid,
    'optiondateid' => $initialdateid,
    'serialscan' => (bool) get_config('booking', 'bookingticketserialscan'),
    'duplicatewindow' => (int) get_config('booking', 'bookingticketduplicatewindow'),
    'showpicture' => in_array('picture', ticket_manager::get_configured_identity_fields(), true),
]]);

echo $output->footer();
