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
 * Handling send message page
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");
require_once("sendmessageform.class.php");

use mod_booking\event\custom_bulk_message_sent;
use mod_booking\event\custom_message_sent;
use mod_booking\message_controller;
use mod_booking\singleton_service;

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$uids = required_param('uids', PARAM_RAW);

$url = new moodle_url('/mod/booking/sendmessage.php',
        ['id' => $id, 'optionid' => $optionid, 'uids' => $uids]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

$strbooking = get_string('modulename', 'booking');

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

require_capability('mod/booking:communicate', $context);

$defaultvalues = new stdClass();
$defaultvalues->optionid = $optionid;
$defaultvalues->id = $id;
$defaultvalues->uids = $uids;

$redirecturl = new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]);

$mform = new mod_booking_sendmessage_form();

$PAGE->set_pagelayout('standard');

$PAGE->set_title(get_string('sendcustommsg', 'booking'));

if ($mform->is_cancelled()) {
    redirect($redirecturl, '', 0);
} else if ($data = $mform->get_data()) {
    // Clean form data.
    $cleanuids = clean_param_array(json_decode($uids), PARAM_INT);

    // Now, let's send the custom message.
    send_custom_message($optionid, $data->subject, $data->message['text'], $cleanuids);

    redirect($redirecturl, get_string('messagesend', 'booking'), 5);
}

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

echo $OUTPUT->heading(get_string("sendcustommsg", "booking"), 2);

$mform->set_data($defaultvalues);
$mform->display();

echo $OUTPUT->footer();

/**
 * Send a custom message to one or more users.
 *
 * @package mod_booking
 * @param int $optionid
 * @param string $subject
 * @param string $message
 * @param array $selecteduserids
 */
function send_custom_message(int $optionid, string $subject, string $message, array $selecteduserids) {
    global $DB, $USER;

    $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $cmid = $settings->cmid;
    $bookingid = $settings->bookingid;

    foreach ($selecteduserids as $currentuserid) {

        $messagecontroller = new message_controller(
            MOD_BOOKING_MSGCONTRPARAM_SEND_NOW, MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE, $cmid, $optionid, $currentuserid,
            $bookingid, null, null, $subject, $message
        );
        $messagecontroller->send_or_queue();

        // Also trigger an event, so we can react with booking rules for example.
        $event = custom_message_sent::create([
            'context' => context_system::instance(),
            'objectid' => $optionid,
            'userid' => $USER->id,
            'relateduserid' => $currentuserid,
            'other' => [
                'cmid' => $cmid,
                'optionid' => $optionid,
                'bookingid' => $bookingid,
                'subject' => $subject,
                'message' => $message,
                'objectid' => $optionid,
            ],
        ]);
        $event->trigger();
    }

    // Check, if a bulk message has been sent.
    $answers = singleton_service::get_instance_of_booking_answers($settings);
    $bookedusers = $answers->usersonlist;
    if (!empty($selecteduserids) && !empty($bookedusers)) {
        $countselected = count($selecteduserids);
        $countbooked = count($bookedusers);
        // It's been considered as a bulk message, if it goes to at least 75% of booked users (and more than 3 users).
        if ($countselected >= 3 && ($countselected / $countbooked) >= 0.75) {
            $event = custom_bulk_message_sent::create([
                'context' => context_system::instance(),
                'objectid' => $optionid,
                'userid' => $USER->id,
                'relateduserid' => 0, // Bulk message, so no related single user.
                'other' => [
                    'cmid' => $cmid,
                    'optionid' => $optionid,
                    'bookingid' => $bookingid,
                    'subject' => $subject,
                    'message' => $message,
                    'objectid' => $optionid,
                ],
            ]);
            $event->trigger();
        }
    }

}
