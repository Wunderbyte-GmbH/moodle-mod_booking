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
 * Public verification page for entry tickets. This is what the QR code on a ticket points to.
 *
 * It is deliberately reachable without a login: the point is to let anyone who is offered a ticket
 * check that it exists, is still valid, and whether it may legally change hands at all. No personal
 * data is shown to anonymous visitors — only entry staff (mod/booking:scanticket) and the ticket
 * holder see the name and picture needed for an identity check at the door.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This page is intentionally public, so a doorkeeper or a potential buyer can check a ticket
// without an account. Personal data is gated on capabilities further down.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require_once(__DIR__ . '/../../config.php');

use mod_booking\local\ticket\ticket_manager;
use mod_booking\singleton_service;

$code = required_param('code', PARAM_ALPHANUM);

$pageurl = new moodle_url('/mod/booking/verifyticket.php', ['code' => $code]);

$PAGE->set_context(context_system::instance());
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('ticketverifyheading', 'mod_booking'));
$PAGE->set_heading(get_string('ticketverifyheading', 'mod_booking'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ticketverifyheading', 'mod_booking'));

$ticket = ticket_manager::find_by_code($code);
$settings = empty($ticket)
    ? null
    : singleton_service::get_instance_of_booking_option_settings((int) $ticket->optionid);

if (empty($ticket) || empty($settings->id)) {
    // Never distinguish "unknown code" from "deleted option" — that would leak whether a code exists.
    echo $OUTPUT->notification(get_string('ticketverifynotfound', 'mod_booking'), 'error');
    echo $OUTPUT->footer();
    die();
}

$context = context_module::instance($settings->cmid);
$isstaff = isloggedin() && !isguestuser() && has_capability('mod/booking:scanticket', $context);
$isholder = isloggedin() && !isguestuser() && (int) $USER->id === (int) $ticket->userid;

// 1. Validity.
if (ticket_manager::is_cancelled($ticket)) {
    echo $OUTPUT->notification(get_string('ticketverifycancelled', 'mod_booking'), 'error');
} else {
    echo $OUTPUT->notification(get_string('ticketverifyvalid', 'mod_booking'), 'success');
}

// 2. What the ticket is for. Safe for everyone — this is the information a buyer needs.
$rows = [];
$rows[] = [get_string('bookingoptionname', 'mod_booking'), format_string($settings->get_title_with_prefix())];
if (!empty($settings->coursestarttime)) {
    $rows[] = [
        get_string('coursestarttime', 'mod_booking'),
        userdate($settings->coursestarttime, get_string('strftimedatetime', 'langconfig')),
    ];
}
if (!empty($settings->location)) {
    $rows[] = [get_string('location', 'mod_booking'), format_string($settings->location)];
}
$rows[] = [
    get_string('status'),
    ticket_manager::is_cancelled($ticket)
        ? get_string('ticketstatuscancelled', 'mod_booking')
        : get_string('ticketstatusvalid', 'mod_booking'),
];

// 3. Personal data, only for entry staff and the holder.
if ($isstaff || $isholder) {
    $rows[] = [get_string('ticketholder', 'mod_booking'), fullname(core_user::get_user((int) $ticket->userid))];
    $rows[] = [
        get_string('ticketissuedon', 'mod_booking'),
        userdate($ticket->timecreated, get_string('strftimedatetime', 'langconfig')),
    ];
}

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->data = $rows;
echo html_writer::table($table);

if ($isstaff) {
    // Entry staff compares this picture with the person at the door (exam use case).
    $user = core_user::get_user((int) $ticket->userid);
    if (!empty($user)) {
        echo $OUTPUT->user_picture($user, ['size' => 200, 'link' => false, 'class' => 'rounded']);
    }
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/booking/scan.php', ['id' => $settings->cmid]),
            get_string('ticketscanner', 'mod_booking'),
            ['class' => 'btn btn-primary']
        ),
        'mt-3'
    );
}

// 4. The binding hint. This is the whole point of the public page: it tells a potential buyer
// whether the ticket can legally be passed on at all.
if (!empty($ticket->personalized)) {
    echo $OUTPUT->notification(get_string('ticketverifypersonalizedhint', 'mod_booking'), 'warning');
} else {
    echo $OUTPUT->notification(get_string('ticketverifytransferablehint', 'mod_booking'), 'info');
}

if (!$isstaff && !$isholder) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/login/index.php'),
            get_string('ticketverifystafflogin', 'mod_booking')
        ),
        'small text-muted mt-3'
    );
}

echo $OUTPUT->footer();
