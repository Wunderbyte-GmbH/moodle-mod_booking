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
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;
use moodle_exception;
use core_plugin_manager;
use mod_booking\enrollink;
use mod_booking\event\bookinganswer_confirmed;
use mod_booking\event\bookinganswer_denied;
use mod_booking\local\bookingstracker\bookingstracker_helper;
use mod_booking\local\confirmationworkflow\confirmation;
use mod_booking\price;

defined('MOODLE_INTERNAL') || die();

use context_system;
use context_module;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use moodle_url;
use stdClass;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\output\col_teacher;
use mod_booking\singleton_service;
use mod_booking\output\renderer;

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Table to manage users (used in report.php).
 *
 * @package mod_booking
 * @author Georg Maißer, Bernhard Fischer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manageusers_table extends wunderbyte_table {
    /**
     * Checkbox column.
     * @param stdClass $values
     * @return string
     */
    public function col_checkbox(stdClass $values) {
        if ($this->is_downloading()) {
            return '';
        }
        return '<input id="manageuserstable-check-' . $values->id .
            '" type="checkbox" class="usercheckbox" name="user[][' . $values->userid .
            ']" value="' . $values->userid . '" />';
    }

    /**
     * Return dragable column.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_dragable(stdClass $values) {

        global $OUTPUT;

        return $OUTPUT->render_from_template('local_wunderbyte/col_sortableitem', []);
    }

    /**
     * Return column timemodified.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_timemodified(stdClass $values): string {
        if (empty($values->timemodified)) {
            return '';
        }
        return date('d.m.Y', $values->timemodified);
    }

    /**
     * Return column timebooked.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_timebooked(stdClass $values): string {
        if (empty($values->timebooked)) {
            return '';
        }
        return date('d.m.Y', $values->timemodified);
    }

     /**
      * Returns lable of the booking status.
      * @param \stdClass $values
      * @return string
      */
    public function col_bookingstatus(stdClass $values): string {
        switch ($values->waitinglist) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                return get_string('bookingstatusbooked', 'mod_booking');
            case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                return get_string('bookingstatusonwaitinglist', 'mod_booking');
            case MOD_BOOKING_STATUSPARAM_RESERVED:
                return get_string('bookingstatusreserved', 'mod_booking');
            case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                return get_string('bookingstatusonnotificationlist', 'mod_booking');
            case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                return get_string('notbooked', 'mod_booking');
            case MOD_BOOKING_STATUSPARAM_DELETED:
                return get_string('bookingstatusdeleted', 'mod_booking');
            case MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED:
                return get_string('bookingstatuspreviouslybooked', 'mod_booking');
            default:
                return get_string('notbooked', 'booking');
        }
    }

    /**
     * Return titleprefix.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_titleprefix(stdClass $values) {
        return $values->titleprefix ?? '';
    }

    /**
     * Return option column.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_text(stdClass $values) {
        if ($this->is_downloading()) {
            return $values->text ?? '';
        }

        $helper = new bookingstracker_helper($values);
        if ($values->scope === 'optionstoconfirm') {
            // We don’t need to show the report page link, so we replace it with the option
            // view page in the approvers table where they confirm an answer.
            $helper->set_texticon('');
            $helper->set_reportoptionlink($helper->get_optionviewlink());
        }
        return $helper->render_col_text();
    }

    /**
     * Return name column.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_name(stdClass $values) {

        global $OUTPUT;

        $url = new moodle_url('/user/profile.php', ['id' => $values->userid]);

        $data = [
            'id' => $values->id,
            'firstname' => $values->firstname,
            'lastname' => $values->lastname,
            'status' => get_string('waitinglist', 'mod_booking'),
            'userprofilelink' => $url->out(),
        ];

        return $OUTPUT->render_from_template('mod_booking/booked_user', $data);
    }

    /**
     * Return presence column.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_status(stdClass $values) {
        if (!isset($values->status) || $values->status === null) {
            $values->status = MOD_BOOKING_PRESENCE_STATUS_UNKNOWN;
        }
        $possiblepresences = booking::get_array_of_possible_presence_statuses();
        if (isset($possiblepresences[$values->status])) {
            return $possiblepresences[$values->status];
        } else {
            return '';
        }
    }

    /**
     * Return presence counter.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_presencecount(stdClass $values) {
        if ($this->is_downloading()) {
            return $values->presencecount ?? 0;
        }
        if (empty($values->optionid)) {
            return '';
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid);
        $numberofoptiondates = count($settings->sessions);
        if ($values->scope == 'option') {
            return "<b>" . ($values->presencecount ?? '0') . "</b>/" . $numberofoptiondates;
        } else {
            $answers = singleton_service::get_instance_of_booking_answers($settings);
            $numberofbookedusers = count($answers->get_usersonlist());
            $numberofpossiblepresences = $numberofbookedusers * $numberofoptiondates;
            return "<b>" . ($values->presencecount ?? 0) . "</b>/" . $numberofpossiblepresences;
        }
    }

    /**
     * Return count of booking answers.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_answerscount(stdClass $values) {
        if ($this->is_downloading()) {
            return $values->answerscount ?? 0;
        }
        if (empty($values->optionid)) {
            return '';
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid);
        $maxanswers = empty($settings->maxanswers) ? get_string('unlimitedplaces', 'mod_booking') : $settings->maxanswers;
        $maxoverbooking = $settings->maxoverbooking ?? 0;

        if ($values->waitinglist == 0) {
            return "<b>" . ($values->answerscount ?? 0) . "</b>/" . $maxanswers;
        } else if ($values->waitinglist == 1) {
            return "<b>" . ($values->answerscount ?? 0) . "</b>/" . $maxoverbooking;
        }

        return $values->answerscount ?? '';
    }

    /**
     * Change number of rows. Uses the transmitaction pattern (actionbutton).
     * @param int $id
     * @param string $data // Data of the bookinganswer.
     * @return array
     */
    public function action_reorderrows(int $id, string $data): array {

        global $DB;

        $jsonobject = json_decode($data);
        $ids = $jsonobject->ids;

        $this->setup();
        // First we fetch the rawdata.
        $this->query_db_cached($this->pagesize, true);

        // We know that we already ordered for timemodified. The lastitem will have the highest time modified...
        // The first item the lowest.

        $newtimemodified = 0;

        foreach ($ids as $id) {
            // The first item is our reference.
            if (empty($newtimemodified)) {
                $newtimemodified = $this->rawdata[$id]->timemodified;
            } else {
                $newtimemodified++;
            }

            $DB->update_record('booking_answers', [
                'id' => $id,
                'timemodified' => $newtimemodified,
            ]);
        }

        $record = reset($this->rawdata);
        $optionid = $record->optionid;
        booking_option::purge_cache_for_answers($optionid);

        return [
            'success' => 1,
            'message' => get_string('successfullysorted', 'mod_booking'),
        ];
    }

    /**
     * Change number of rows. Uses the transmitaction pattern (actionbutton).
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_confirmbooking(int $id, string $data): array {

        global $DB, $USER;

        $jsonobject = json_decode($data);
        $baid = $jsonobject->id;

        $record = $DB->get_record('booking_answers', ['id' => $baid]);

        $userid = $record->userid;
        $optionid = $record->optionid;

        // Check all bookingextension subplugins for confirm capability.
        [$allowedtoconfirm, $returnmessage, $reload] =
            confirmation::check_confirm_capability($optionid, $USER->id, $userid);

        if (!$allowedtoconfirm) {
            return [
                'success' => 0,
                'message' => $returnmessage ?? get_string('notallowedtoconfirm', 'mod_booking'),
                'reload' => ($reload ?? false) ? 1 : 0,
            ];
        }

        // Check number of required confirmation.
        $requiredconfirmationscount = confirmation::get_required_confirmation_count($optionid);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
        $user = singleton_service::get_instance_of_user($userid);

        // Inserting into History Table.
        booking_option::booking_history_insert(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED,
            $baid,
            $optionid,
            $settings->bookingid,
            $userid
        );

        // Get the price for the user.
        // Sometimes the option is free for the user even when the option has a price (userprice = 1).
        // In this case, the option should be booked immediately for the user.
        $userprice = price::get_price('option', $option->id, $user);

        // If booking option is booked with a price, we don't book directly but just allow to book.
        // Exeption: The booking is autoenrol and needs to be booked directly...
        // In this case price can be given for bookingoption, but was already payed before.
        if (
            !empty($settings->jsonobject->useprice)
            && (isset($userprice['price']) && $userprice['price'] != 0)
            && empty(get_config('booking', 'turnoffwaitinglist'))
            && (
                $erwaitinglist = enrollink::enrolmentstatus_waitinglist($settings) === false
                || enrollink::is_initial_answer($record) === true
            ) // Only the initial answer of enrollink needs to be bought.
        ) {
            $option->user_submit_response(
                $user,
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION,
                MOD_BOOKING_VERIFIED
            );
        } else {
            // Check if it's an autoenrollment. If so, we need to change the status.
            if (!empty($erwaitinglist)) {
                $status = MOD_BOOKING_BO_SUBMIT_STATUS_AUTOENROL;
            } else if ($requiredconfirmationscount >= 2) {
                // If needs more confirmations shoudl still wait on waiting list. otherwise it can be booked.
                // We need the current confirmation with the required confirmation.
                $currentconfirmationcount = 0;
                $answerjson = !empty($record->json) ? json_decode($record->json) : new stdClass();
                if (property_exists($answerjson, 'confirmationcount')) {
                    $currentconfirmationcount = (int) $answerjson->confirmationcount;
                }

                if (($requiredconfirmationscount - 1) === $currentconfirmationcount) {
                    // So it's the last confirm. No more confirmation is required.
                    $status = MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT;
                } else {
                    // Need more confirms.
                    $status = MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION;
                }
            } else {
                $status = MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT;
            }
            $option->user_submit_response($user, 0, 0, $status, MOD_BOOKING_VERIFIED);
        }

        // Event is triggered no matter if a bookinganswer with or without price was confirmed.
        $event = bookinganswer_confirmed::create(
            [
                'objectid' => $option->id,
                'context' => context_system::instance(),
                'userid' => $USER->id,
                'relateduserid' => $user->id,
            ]
        );
        $event->trigger();

        return [
            'success' => 1,
            'message' => get_string('successfullybooked', 'mod_booking'),
            'reload' => 1,
        ];
    }

    /**
     * Change number of rows. Uses the transmitaction pattern (actionbutton).
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_unconfirmbooking(int $id, string $data): array {

        global $DB, $USER;

        $jsonobject = json_decode($data);
        $baid = $jsonobject->id;

        $record = $DB->get_record('booking_answers', ['id' => $baid]);

        $userid = $record->userid;
        $optionid = $record->optionid;
        $allowedtoconfirm = false;

        // Booking extions can break this execution to check if the current user has actually the right.
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $class = "\\bookingextension_{$plugin->name}\\local\\confirmbooking";

            if (class_exists($class)) {
                [$allowed, $message, $reload] = $class::has_capability_to_confirm_booking($optionid, $USER->id, $userid);
                if ($allowed) {
                    // If only one subplugin allows it, we can continue.
                    $allowedtoconfirm = true;
                    continue;
                } else {
                    $returnmessage = $message;
                }
            }
        }
        if (!$allowedtoconfirm) {
            return [
                'success' => 0,
                'message' => $returnmessage,
            ];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
        $user = singleton_service::get_instance_of_user($userid);

        $option->user_submit_response($user, 0, 0, 3, MOD_BOOKING_VERIFIED);

        return [
            'success' => 1,
            'message' => get_string('successfullybooked', 'mod_booking'),
            'reload' => 1,
        ];
    }

    /**
     * Change number of rows. Uses the transmitaction pattern (actionbutton).
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_deletebooking(int $id, string $data): array {

        global $DB, $USER;

        $jsonobject = json_decode($data);

        $userid = $jsonobject->userid;
        $optionid = $jsonobject->optionid;
        $allowedtoconfirm = false;

        // Booking extions can break this execution to check if the current user has actually the right.
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $class = "\\bookingextension_{$plugin->name}\\local\\confirmbooking";

            if (class_exists($class)) {
                [$allowed, $message, $reload] = $class::has_capability_to_confirm_booking($optionid, $USER->id, $userid);
                if ($allowed) {
                    // If only one subplugin allows it, we can continue.
                    $allowedtoconfirm = true;
                    continue;
                } else {
                    $returnmessage = $message;
                }
            }
        }
        if (!$allowedtoconfirm) {
            return [
                'success' => 0,
                'message' => $returnmessage,
            ];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);

        if (
            $DB->record_exists(
                'booking_answers',
                ['userid' => $userid, 'optionid' => $optionid, 'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED]
            )
        ) {
            $option->user_delete_response($userid, true, false, false);
        } else {
            $option->user_delete_response($userid, false, false, false);
        }

        return [
            'success' => 1,
            'message' => get_string('successfullybooked', 'mod_booking'),
            'reload' => 1,
        ];
    }

    /**
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_denybooking(int $id, string $data): array {

        global $DB, $USER;

        $jsonobject = json_decode($data);

        $userid = $jsonobject->userid;
        $optionid = $jsonobject->optionid;
        $allowedtoconfirm = false;

        // Booking extions can break this execution to check if the current user has actually the right.
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $class = "\\bookingextension_{$plugin->name}\\local\\confirmbooking";

            if (class_exists($class)) {
                [$allowed, $message, $reload] = $class::has_capability_to_confirm_booking($optionid, $USER->id, $userid);
                if ($allowed) {
                    // If only one subplugin allows it, we can continue.
                    $allowedtoconfirm = true;
                    continue;
                } else {
                    $returnmessage = $message;
                }
            }
        }
        if (!$allowedtoconfirm) {
            return [
                'success' => 0,
                'message' => $returnmessage,
            ];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);

        if (
            $DB->record_exists(
                'booking_answers',
                ['userid' => $userid, 'optionid' => $optionid, 'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED]
            )
        ) {
            $option->user_delete_response($userid, true, false, false);
        } else {
            $option->user_delete_response($userid, false, false, false);
        }

        $user = singleton_service::get_instance_of_user($userid);

        // Trigger event.
        $event = bookinganswer_denied::create(
            [
                'objectid' => $option->id,
                'context' => context_system::instance(),
                'userid' => $USER->id,
                'relateduserid' => $user->id,
            ]
        );
        $event->trigger();

        return [
            'success' => 1,
            'message' => get_string('successfullybooked', 'mod_booking'),
            'reload' => 1,
        ];
    }

    /**
     * Change number of rows. Uses the transmitaction pattern (actionbutton).
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_delete_checked_booking_answers(int $id, string $data): array {

        global $DB;

        $jsonobject = json_decode($data);

        $bookinganswerids = $jsonobject->checkedids;

        foreach ($bookinganswerids as $bookinganswerid) {
            if ($answerrecord = $DB->get_record('booking_answers', ['id' => $bookinganswerid])) {
                $userid = $answerrecord->userid;
                $optionid = $answerrecord->optionid;

                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                $context = context_module::instance($settings->cmid);

                if (!has_capability('mod/booking:bookforothers', $context)) {
                    throw new moodle_exception('Missing capability: mod/booking:bookforothers', 'mod_booking');
                }

                $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);

                if ($answerrecord->waitinglist == MOD_BOOKING_STATUSPARAM_RESERVED) {
                    $option->user_delete_response($userid, true, false, false);
                } else {
                    $option->user_delete_response($userid, false, false, false);
                }
            } else {
                throw new moodle_exception(
                    'invalidanswerid',
                    'mod_booking',
                    '',
                    null,
                    'Answer ID: ' . $bookinganswerid . ' not found in table booking_answers.'
                );
            }
        }

        return [
            'success' => 1,
            'message' => get_string('checkedanswersdeleted', 'mod_booking'),
            'reload' => 1,
        ];
    }

    /**
     * This handles the action column with buttons, icons, checkboxes.
     *
     * @param stdClass $values
     * @return bool|string
     */
    public function col_action_confirm_delete($values) {

        global $OUTPUT, $USER;

        $optionid = $values->optionid;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $ba = singleton_service::get_instance_of_booking_answers($settings);

        if (!empty($values->json)) {
            $jsonobject = json_decode($values->json);
            if (!empty($jsonobject->confirmwaitinglist)) {
                $data[] = [
                    'label' => get_string('unconfirm', 'mod_booking'), // Name of your action button.
                    'class' => "btn btn-nolabel unconfirmbooking-username-{$values->username} ",
                    'href' => '#', // You can either use the link, or JS, or both.
                    'iclass' => 'fa fa-ban', // Add an icon before the label.
                    'id' => $values->id,
                    'name' => $values->id,
                    'methodname' => 'unconfirmbooking', // The method needs to be added to your child of wunderbyte_table class.
                    // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'data' => [
                        'id' => $values->id,
                        'labelcolumn' => 'username',
                        'titlestring' => 'unconfirmbooking',
                        'bodystring' => 'unconfirmbookinglong',
                        'submitbuttonstring' => 'delete',
                        'component' => 'mod_booking',
                        'optionid' => $values->optionid,
                        'userid' => $values->userid,
                    ],
                ];
            }
        }

        [$allowedtoconfirm, $returnmessage, $reload] =
            confirmation::check_confirm_capability($optionid, $USER->id, $values->userid);

        if (!$allowedtoconfirm) {
            $data[] = [
                'label' => $returnmessage ?? '', // Name of your action button.
                'class' => "badge bg-secondary p-2",
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => '', // Add an icon before the label.
                'id' => $values->id,
                'name' => $values->id,
                'methodname' => '', // The method needs to be added to your child of wunderbyte_table class.
                'data' => [], // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
            ];
        }

        if (
                $allowedtoconfirm
        ) {
            $data[] = [
                'label' => '', // Name of your action button.
                'class' => "btn btn-nolabel confirmbooking-username-{$values->username} ",
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => 'fa fa-check', // Add an icon before the label.
                'id' => $values->id,
                'name' => $values->id,
                'methodname' => 'confirmbooking', // The method needs to be added to your child of wunderbyte_table class.
                'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'id' => $values->id,
                    'labelcolumn' => 'username',
                    'titlestring' => 'confirmbooking',
                    'bodystring' => 'confirmbookinglong',
                    'submitbuttonstring' => 'booking:choose',
                    'component' => 'mod_booking',
                    'optionid' => $values->optionid,
                    'userid' => $values->userid,
                ],
            ];

            // Deny booking Button.
            $data[] = [
                'label' => '', // Name of your action button.
                'class' => '',
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => 'fa fa-thumbs-down', // Add an icon before the label.
                'id' => $values->id,
                'name' => $values->id,
                'methodname' => 'denybooking', // The method needs to be added to your child of wunderbyte_table class.
                'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'id' => $values->id,
                    'labelcolumn' => 'username',
                    'titlestring' => 'deny',
                    'bodystring' => 'denybookinglong',
                    'submitbuttonstring' => 'deny',
                    'component' => 'mod_booking',
                    'optionid' => $values->optionid,
                    'userid' => $values->userid,
                ],
            ];
        }

        // Trash booking button.
        $data[] = [
            'label' => '', // Name of your action button.
            'class' => '',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-trash', // Add an icon before the label.
            'id' => $values->id,
            'name' => $values->id,
            'methodname' => 'deletebooking', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->id,
                'labelcolumn' => 'username',
                'titlestring' => 'delete',
                'bodystring' => 'deletebookinglong',
                'submitbuttonstring' => 'delete',
                'component' => 'mod_booking',
                'optionid' => $values->optionid,
                'userid' => $values->userid,
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        return $OUTPUT->render_from_template(
            'local_wunderbyte_table/component_actionbutton',
            ['showactionbuttons' => $data]
        );
    }

    /**
     * This handles the delete action column.
     *
     * @param stdClass $values
     * @return bool|string
     */
    public function col_action_delete($values) {

        global $OUTPUT;

        $data[] = [
            'label' => '', // Name of your action button.
            'class' => '',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-trash', // Add an icon before the label.
            'id' => $values->id,
            'name' => $values->id,
            'methodname' => 'deletebooking', // The method needs to be added to your child of wunderbyte_table class.
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->id,
                'labelcolumn' => 'username',
                'titlestring' => 'delete',
                'bodystring' => 'deletebookinglong',
                'submitbuttonstring' => 'delete',
                'component' => 'mod_booking',
                'optionid' => $values->optionid,
                'userid' => $values->userid,
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        return $OUTPUT->render_from_template(
            'local_wunderbyte_table/component_actionbutton',
            ['showactionbuttons' => $data]
        );
    }

    /**
     * This handles the presence status action column.
     *
     * @param stdClass $values
     * @return bool|string
     */
    public function col_actions($values) {

        global $OUTPUT;

        $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid);
        $cmid = $settings->cmid ?? 0;

        if (!empty($cmid)) {
            $data[] = [
                'label' => get_string('presence', 'mod_booking'), // Name of your action button.
                'class' => 'btn btn-light btn-sm',
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => 'fa fa-user-o', // Add an icon before the label.
                // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                /* 'methodname' => 'mymethod', // The method needs to be added to your child of wunderbyte_table class. */
                'formname' => 'mod_booking\\form\\optiondates\\modal_change_status',
                'nomodal' => false,
                'id' => $values->id,
                'selectionmandatory' => false,
                'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'scope' => 'optiondate',
                    'titlestring' => 'changepresencestatus',
                    'submitbuttonstring' => 'save',
                    'component' => 'mod_booking',
                    'cmid' => $cmid,
                    'optionid' => $values->optionid ?? 0,
                    'optiondateid' => $values->optiondateid ?? 0,
                    'userid' => $values->userid ?? 0,
                    'status' => $values->status ?? 0,
                ],
            ];
        }

        $data[] = [
            'label' => get_string('notes', 'mod_booking'), // Name of your action button.
            'class' => 'btn btn-light btn-sm',
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => 'fa fa-pencil', // Add an icon before the label.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* 'methodname' => 'mymethod', // The method needs to be added to your child of wunderbyte_table class. */
            'formname' => 'mod_booking\\form\\optiondates\\modal_change_notes',
            'nomodal' => false,
            'id' => $values->id,
            'selectionmandatory' => false,
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'scope' => 'optiondate',
                'titlestring' => 'notes',
                'submitbuttonstring' => 'save',
                'component' => 'mod_booking',
                'cmid' => $cmid,
                'optionid' => $values->optionid ?? 0,
                'optiondateid' => $values->optiondateid ?? 0,
                'userid' => $values->userid ?? 0,
                'notes' => $values->notes ?? '',
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        return $OUTPUT->render_from_template(
            'local_wunderbyte_table/component_actionbutton',
            ['showactionbuttons' => $data]
        );
    }
}
