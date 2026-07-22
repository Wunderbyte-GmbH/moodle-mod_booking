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
use html_writer;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\certificateclass;
use mod_booking\local\certificate_conditions\certificate_conditions;
use mod_booking\local\slotbooking\slot_answer;
use user_picture;
use moodle_exception;
use core_plugin_manager;
use mod_booking\enrollink;
use mod_booking\event\bookingoption_completed;
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
        return date('d.m.Y H:i:s', $values->timemodified);
    }

    /**
     * Return column coursestarttime.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_coursestarttime(stdClass $values): string {
        if (empty($values->coursestarttime)) {
            return '';
        }
        return date('d.m.Y', $values->coursestarttime);
    }

    /**
     * Return column courseendtime.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_courseendtime(stdClass $values): string {
        if (empty($values->courseendtime)) {
            return '';
        }
        return date('d.m.Y', $values->courseendtime);
    }

    /**
     * Return column timecreated (booking date).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_timecreated(stdClass $values): string {
        if (empty($values->timecreated)) {
            return '';
        }
        return date('d.m.Y', $values->timecreated);
    }

    /**
     * Return column completed (activity completion).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_completed(stdClass $values): string {
        if ($this->is_downloading()) {
            return empty($values->completed) ? get_string('no') : get_string('yes');
        }
        if (empty($values->completed)) {
            return '';
        }
        return '<i class="fa fa-xl fa-check-square text-success" title="' .
            get_string('completed', 'mod_booking') . '" aria-label="' .
            get_string('completed', 'mod_booking') . '"></i>';
    }

    /**
     * Return column waitinglist (booking status).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_waitinglist(stdClass $values): string {
        return $this->col_bookingstatus($values);
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
        return date('d.m.Y H:i:s', $values->timebooked);
    }
    /**
     * Return column completeddate.
     *
     * @param stdClass $values
     *
     * @return string
     *
     */
    public function col_completeddate(stdClass $values): string {
        if (empty($values->completeddate)) {
            return '';
        }
        return date('d.m.Y', $values->completeddate);
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
     * Return booking instance column: the instance name, linked to the view.php
     * of the instance (plain name in downloads).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_instancename(stdClass $values) {
        $name = format_string($values->instancename ?? '');
        if ($this->is_downloading() || empty($values->cmid)) {
            return $name;
        }
        $url = new moodle_url('/mod/booking/view.php', ['id' => $values->cmid]);
        return html_writer::link($url, $name);
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
     * Returns the enrollink the user has created (customform enrolusersaction field) or used for this option.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_enrollink(stdClass $values): string {
        $erlid = '';
        // In option scope, the row id is the booking answer id. Other scopes provide "baid".
        $baid = $values->baid ?? $values->id ?? 0;
        if (!empty($baid) && is_numeric($baid)) {
            $erlid = enrollink::get_erlid_from_baid((int)$baid) ?? '';
        }
        if (empty($erlid) && !empty($values->userid) && !empty($values->optionid)) {
            // The user did not create a bundle - check if the user created or used an enrollink for this option.
            $erlid = enrollink::get_erlid_for_user((int)$values->userid, (int)$values->optionid);
        }
        if (empty($erlid)) {
            return '';
        }
        if ($this->is_downloading()) {
            $url = new moodle_url('/mod/booking/enrollink.php', ['erlid' => $erlid]);
            return $url->out(false);
        }
        return enrollink::create_enrollink($erlid);
    }

    /**
     * Returns the user from whom the enrollink was received (with link to the user profile).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_enrollinkreceivedfrom(stdClass $values): string {
        if (empty($values->userid) || empty($values->optionid)) {
            return '';
        }
        return enrollink::render_enrollink_received_from(
            (int)$values->userid,
            (int)$values->optionid,
            !$this->is_downloading()
        );
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
        $erwaitinglist = enrollink::enrolmentstatus_waitinglist($settings);
        if (
            !empty($settings->jsonobject->useprice)
            && (isset($userprice['price']) && $userprice['price'] != 0)
            && empty(get_config('booking', 'turnoffwaitinglist'))
            && (
                $erwaitinglist === false
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

                // Deleting responses requires the same capability as on the old
                // report.php (and as the delete button itself is gated with).
                // Note: since this is a write capability, it is not part of the
                // default role of non-editing teachers on new installations.
                if (!has_capability('mod/booking:deleteresponses', $context)) {
                    throw new moodle_exception('Missing capability: mod/booking:deleteresponses', 'mod_booking');
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
     * Enrol the users of the checked booking answers into the course connected
     * to their booking option. Uses the transmitaction pattern (actionbutton).
     * Migrated from the old report.php bulk action subscribetocourse: enrols
     * manually (enrol_user with $manual = true), so it also works when the
     * instance has auto-enrolment disabled.
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_enrol_checked_booking_answers(int $id, string $data): array {

        global $DB;

        $jsonobject = json_decode($data);

        $bookinganswerids = $jsonobject->checkedids;

        foreach ($bookinganswerids as $bookinganswerid) {
            if (!$answerrecord = $DB->get_record('booking_answers', ['id' => $bookinganswerid])) {
                throw new moodle_exception(
                    'invalidanswerid',
                    'mod_booking',
                    '',
                    null,
                    'Answer ID: ' . $bookinganswerid . ' not found in table booking_answers.'
                );
            }

            $optionid = $answerrecord->optionid;
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $context = context_module::instance($settings->cmid);

            // Subscribeusers authorizes putting other users into bookings and
            // courses. Since this is a write capability, it is not part of the
            // default role of non-editing teachers on new installations.
            if (!has_capability('mod/booking:subscribeusers', $context)) {
                throw new moodle_exception('Missing capability: mod/booking:subscribeusers', 'mod_booking');
            }

            if (empty($settings->courseid)) {
                return [
                    'success' => 0,
                    'message' => get_string('nocourse', 'mod_booking'),
                ];
            }

            $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
            $option->enrol_user((int)$answerrecord->userid, true);
        }

        return [
            'success' => 1,
            'message' => get_string('userssuccessfullenrolled', 'mod_booking'),
            'reload' => 1,
        ];
    }

    /**
     * Toggle the completion status of the checked booking answers.
     * Uses the transmitaction pattern (actionbutton).
     * Same behaviour as the "Toggle completion status" button on report.php.
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_toggle_completion_booking_answers(int $id, string $data): array {

        global $DB;

        $jsonobject = json_decode($data);

        $bookinganswerids = $jsonobject->checkedids;

        foreach ($bookinganswerids as $bookinganswerid) {
            if ($answerrecord = $DB->get_record('booking_answers', ['id' => $bookinganswerid])) {
                $optionid = $answerrecord->optionid;

                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                $context = context_module::instance($settings->cmid);

                // Managebookedusers is the general edit gate of the tracker:
                // read-only roles (e.g. non-editing teachers) must not change
                // the completion status.
                if (!has_capability('mod/booking:managebookedusers', $context)) {
                    throw new moodle_exception('Missing capability: mod/booking:managebookedusers', 'mod_booking');
                }

                $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
                $option->toggle_user_completion($answerrecord->userid);
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
            'message' => get_string('activitycompletionsuccess', 'mod_booking'),
            'reload' => 1,
        ];
    }

    /**
     * Trigger the check for the given users in the given options if the are allowed to recieve a certificate and if so,
     * issue the one that is stored in the settings.
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_trigger_certificate_booking_answers(int $id, string $data): array {
        global $DB, $USER;

        $failure = [
            'success' => 0,
            'message' => get_string('certificatenotactive', 'mod_booking'),
            'reload' => 1,
        ];

        if (
            !class_exists('tool_certificate\certificate')
            || !get_config('booking', 'certificateon')
        ) {
            return $failure;
        }

        // Server-side recheck of the button gate: report2 is also readable by
        // users without any certificate rights.
        if (!has_capability('tool/certificate:manage', context_system::instance())) {
            throw new moodle_exception('Missing capability: tool/certificate:manage', 'mod_booking');
        }

        $jsonobject = json_decode($data);

        $bookinganswerids = $jsonobject->checkedids;
        $triggered = false;
        foreach ($bookinganswerids as $bookinganswerid) {
            if ($answerrecord = $DB->get_record('booking_answers', ['id' => $bookinganswerid])) {
                $optionid = $answerrecord->optionid;

                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

                $certificateid = booking_option::get_value_of_json_by_key((int) $settings->id, 'certificate') ?? 0;
                $presenceconfig = get_config('booking', 'presencestatustoissuecertificate');

                // Manually trigger condition-based certificate actions with a synthetic completion event.
                // This action runs outside the observer flow where bookingoption_completed is usually dispatched.
                $manualevent = bookingoption_completed::create([
                    'objectid' => (int)$optionid,
                    'context' => context_module::instance((int)$settings->cmid),
                    'userid' => (int)$USER->id,
                    'relateduserid' => (int)$answerrecord->userid,
                    'other' => ['cmid' => (int)$settings->cmid],
                ]);
                if (
                    certificate_conditions::evaluate_certificate_conditions_with_result(
                        $manualevent,
                        (int)$answerrecord->userid,
                        (int)$optionid
                    )
                ) {
                    $triggered = true;
                }

                // Keep legacy trigger behaviour for option-level certificate configuration.
                if (
                    !empty($certificateid)
                    && (empty($presenceconfig) || $answerrecord->status == $presenceconfig)
                    && (!empty($presenceconfig) || $answerrecord->completed != 0)
                ) {
                    $triggered = true;
                    certificateclass::issue_certificate($optionid, $answerrecord->userid, 0, (int)$certificateid);
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

        if (!$triggered) {
            $failure['message'] = get_string('certificatenotapplyforusers', 'booking');
            return $failure;
        }
        return [
            'success' => 1,
            'message' => get_string('certificatestriggered', 'mod_booking'),
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
        $cmid = $settings->cmid ?? 0;
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $jsonobject = (!empty($values->json)) ? json_decode($values->json) : null;

        if (!empty($jsonobject)) {
            if (!empty($jsonobject->confirmwaitinglist)) {
                $data[] = [
                    'label' => get_string('unconfirm', 'mod_booking'), // Name of your action button.
                    'title' => get_string('unconfirm', 'mod_booking'), // Name of your action button.
                    'arialabel' => get_string('unconfirm', 'mod_booking'), // Name of your action button.
                    'class' => "btn btn-nolabel unconfirmbooking-username-{$values->username}",
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

        // We rely on the number of required confirmations to decide whether to show the confirmation,
        // because if we only check whether the user has already confirmed, we may run into problems.
        // For example, when more than one confirmation is required and the user is both the first confirmer
        // and the second confirmer’s deputy, checking only the previous confirmation could fail.
        // By relying on the number of confirmations instead, we avoid this issue.
        // You might worry about the case where more than one confirmation is required
        // and the first confirmer has already confirmed. In that situation,
        // the logic inside check_confirm_capability ensures the correct turn is checked.

        // Get number of required confirmations.
        $requiredconfirmations = confirmation::get_required_confirmation_count($optionid);
        if (!empty($jsonobject) && !empty($jsonobject->confirmationcount)) {
            $currentconfirmations = (int) $jsonobject->confirmationcount;
        } else {
            $currentconfirmations = 0;
        }
        $bookingoptionjsonobject = !empty($settings->json) ? json_decode($settings->json) : null;
        $waitforconfirmation = property_exists($bookingoptionjsonobject, 'waitforconfirmation')
                                ? $bookingoptionjsonobject->waitforconfirmation : 0;
        if (
                $allowedtoconfirm
                && $requiredconfirmations > $currentconfirmations
                && $waitforconfirmation
                && $ba->user_status($values->userid) != MOD_BOOKING_STATUSPARAM_BOOKED
        ) {
            $data[] = [
                'arialabel' => get_string('actionbuttonconfirm', 'mod_booking'), // Name of your action button.
                'title' => get_string('actionbuttonconfirm', 'mod_booking'), // Name of your action button.
                'class' => "btn btn-nolabel confirmbooking-username-{$values->username} ",
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => 'fa fa-thumbs-up', // Add an icon before the label.
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
                'title' => get_string('actionbuttondeny', 'mod_booking'), // Name of your action button.
                'arialabel' => get_string('actionbuttondeny', 'mod_booking'), // Name of your action button.
                'class' => 'btn btn-nolabel',
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

        // Trash booking button - only add if the user has the capability to delete booking answers.
        if (
            !empty($cmid) && has_capability('mod/booking:deleteresponses', context_module::instance($cmid))
            || has_capability('mod/booking:deleteresponses', context_system::instance())
        ) {
            $data[] = [
                'title' => get_string('actionbuttondelete', 'mod_booking'), // Name of your action button.
                'arialabel' => get_string('actionbuttondelete', 'mod_booking'), // Name of your action button.
                'class' => 'btn btn-nolabel',
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
        }

        // This transforms the array to make it easier to use in mustache template.
        if (!empty($data)) {
            table::transform_actionbuttons_array($data);

            return $OUTPUT->render_from_template(
                'local_wunderbyte_table/component_actionbutton',
                ['showactionbuttons' => $data]
            );
        }
        return '';
    }

    /**
     * This handles the delete action column.
     *
     * @param stdClass $values
     * @return bool|string
     */
    public function col_action_delete($values) {

        global $OUTPUT;

        $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid);
        $cmid = $settings->cmid ?? 0;

        if (!empty($cmid) && has_capability('mod/booking:deleteresponses', context_module::instance($cmid))) {
            $data[] = [
                'label' => get_string('actionbuttondelete', 'mod_booking'), // Name of your action button.
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
        // If user has no capability to delete, we return an empty string to not show the button.
        return '';
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

    /**
     * Renders the image of the user.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_userpic(stdClass $values): string {
        global $PAGE;

        if (empty($values->userid)) {
            return '';
        }
        $user = singleton_service::get_instance_of_user((int)$values->userid);
        $userpic = new user_picture($user);
        $userpic->size = 200;
        $userpictureurl = $userpic->get_url($PAGE);
        return html_writer::img(
            $userpictureurl,
            "link",
            ['height' => 100]
        );
    }

    /**
     * Renders the index number of the row.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_indexnumber(stdClass $values): string {
        $optionid = $values->optionid ?? 0;
        return (string)singleton_service::get_index_number($this->uniqueid . $optionid, (string)$values->id);
    }

    /**
     * Renders the aggregated rating of the booking answer (read-only).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_rating(stdClass $values): string {
        global $DB;

        if (!isset($values->rating) || $values->rating === null || $values->rating === '') {
            return '';
        }

        $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)($values->optionid ?? 0));
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid ?? 0);
        $value = (float)$values->rating;

        // See RATING_AGGREGATE_COUNT in rating/lib.php.
        if ((int)($bookingsettings->assessed ?? 0) === 2) {
            return (string)(int)$value;
        }

        // For custom scales, map the value back to the scale item.
        $scaleid = (int)($bookingsettings->scale ?? 0);
        if ($scaleid < 0 && ($scale = $DB->get_record('scale', ['id' => -$scaleid]))) {
            $scaleitems = explode(',', $scale->scale);
            $index = max(1, min(count($scaleitems), (int)round($value)));
            return format_string(trim($scaleitems[$index - 1]));
        }

        return format_float($value, 2);
    }

    /**
     * Renders the group(s) of the user in the course of the booking instance.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_groups(stdClass $values): string {
        global $DB;

        $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)($values->optionid ?? 0));
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid ?? 0);
        $courseid = (int)($bookingsettings->course ?? 0);
        if (empty($courseid) || empty($values->userid)) {
            return '';
        }

        $groups = groups_get_user_groups($courseid, (int)$values->userid);
        if (empty($groups[0])) {
            return '';
        }
        [$insql, $inparams] = $DB->get_in_or_equal($groups[0]);
        $groupnames = $DB->get_fieldset_select('groups', 'name', 'id ' . $insql, $inparams);
        return implode(', ', array_map('format_string', $groupnames));
    }

    /**
     * Renders the latest certificate of the user for the booking option.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_certificate(stdClass $values): string {
        $certificates = certificateclass::get_certificates_for_user_option(
            (int)($values->userid ?? 0),
            (int)($values->optionid ?? 0)
        );
        if (empty($certificates)) {
            return '';
        }

        $lastcertificate = end($certificates);
        if (empty($lastcertificate->timecreated) || empty($lastcertificate->code)) {
            return '';
        }

        if (empty($lastcertificate->expires)) {
            $text = get_string('certificatewithoutexpiration', 'mod_booking');
        } else {
            $dateformatted = userdate($lastcertificate->expires);
            $text = get_string('certificatewithexpiration', 'mod_booking', $dateformatted);
        }
        $statusicon = (time() < $lastcertificate->expires) ? '&#x2705; ' : '&#x274C; ';
        $url = new moodle_url(
            "/pluginfile.php/1/tool_certificate/issues/{$lastcertificate->timecreated}/{$lastcertificate->code}.pdf"
        );
        return $statusicon . html_writer::link($url, $text, ['target' => '_blank']);
    }

    /**
     * Renders all certificates of the user for the booking option.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_allusercertificates(stdClass $values): string {
        global $OUTPUT;
        static $id = 1;

        $certificates = certificateclass::get_certificates_for_user_option(
            (int)($values->userid ?? 0),
            (int)($values->optionid ?? 0)
        );
        if (empty($certificates)) {
            return '';
        }

        $certdata = [];
        foreach ($certificates as $certificate) {
            $url = new moodle_url(
                "/pluginfile.php/1/tool_certificate/issues/{$certificate->timecreated}/{$certificate->code}.pdf"
            );
            $certdata[] = [
                'code' => $certificate->code,
                'timecreated' => userdate($certificate->timecreated),
                'expires' => !empty($certificate->expires) ? userdate($certificate->expires)
                    : get_string('certificatewithoutexpiration', 'mod_booking'),
                'url' => $url,
            ];
        }
        $fullname = "{$values->firstname} {$values->lastname}";
        $data = [
            'title' => get_string('certificatemodalheader', 'mod_booking', $fullname),
            'certificates' => $certdata,
            'id' => $id,
        ];
        $id++;
        return $OUTPUT->render_from_template('mod_booking/report/allusercertificate_modal', $data);
    }

    /**
     * Renders the number of booked slots (slotbooking options).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_slotnumslots(stdClass $values): string {
        return slot_answer::render_numslots($values);
    }

    /**
     * Renders the start time of the first booked slot (slotbooking options).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_slotstarttime(stdClass $values): string {
        return slot_answer::render_starttime($values);
    }

    /**
     * Renders the end time of the last booked slot (slotbooking options).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_slotendtime(stdClass $values): string {
        return slot_answer::render_endtime($values);
    }

    /**
     * Renders the assigned teachers from the slot JSON (slotbooking options).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_slotteachers(stdClass $values): string {
        return slot_answer::render_teachers($values);
    }

    /**
     * Renders the slot price paid from the slot JSON (slotbooking options).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_slotprice(stdClass $values): string {
        return slot_answer::render_price($values);
    }

    /**
     * Renders the move slot action link (slotbooking options).
     *
     * @param stdClass $values
     * @return string
     */
    public function col_moveslot(stdClass $values): string {
        $settings = singleton_service::get_instance_of_booking_option_settings((int)($values->optionid ?? 0));
        $cmid = $settings->cmid ?? 0;
        if (empty($cmid)) {
            return '';
        }

        $context = context_module::instance($cmid);
        $canmoveslots = has_capability('mod/booking:moveslots', $context)
            || has_capability('mod/booking:updatebooking', $context);
        if (!$canmoveslots) {
            return '';
        }

        $slotdata = slot_answer::get_slot_data($values);
        if (empty($slotdata)) {
            return '';
        }

        // In option scope, the row id is the booking answer id. Other scopes provide "baid".
        $url = new moodle_url('/mod/booking/moveslot.php', [
            'id' => $cmid,
            'optionid' => $values->optionid,
            'baid' => (int)($values->baid ?? $values->id ?? 0),
        ]);

        return html_writer::link($url, get_string('slot_move_action', 'mod_booking'));
    }

    /**
     * This function is called for each data row to allow processing of columns which do not have a *_cols function.
     *
     * @param mixed $colname
     * @param mixed $values
     *
     * @return string
     *
     */
    public function other_cols($colname, $values) {
        // Custom user profile fields configured in responsesfields/reportfields
        // are selected as "cust<shortname>" holding "datatype|data".
        // If the value does not match that pattern, fall through: the column
        // might be a booking option customfield with a shortname starting with "cust".
        if (substr($colname, 0, 4) === 'cust') {
            $tmp = explode('|', $values->{$colname} ?? '', 2);
            if (count($tmp) == 2) {
                return $tmp[0] == 'datetime'
                    ? userdate($tmp[1], get_string('strftimedate', 'langconfig'))
                    : format_string($tmp[1]);
            }
        }
        // Answers to the fields of the customform availability condition, like on report.php.
        // They are stored in the json of the booking answer (in optiondate scope selected as
        // "bajson", because there "json" holds the json of the optiondate answer).
        if (substr($colname, 0, 10) === 'formfield_') {
            $optionid = (int)($values->optionid ?? 0);
            $json = $values->bajson ?? $values->json ?? '';
            if (empty($optionid) || empty($json)) {
                return '';
            }
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            [, $counter] = explode('_', $colname);
            $customformvalue = customform::get_customform_field_value(
                $settings,
                (object)['json' => $json],
                (int)$counter
            );
            return $customformvalue === null ? '' : format_string($customformvalue);
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid ?? 0);
        if ($settings->customfields[$colname] ?? false) {
            if (!isset($values->$colname)) {
                return '';
            }
            return $settings->customfieldsfortemplates[$colname]["value"];
        } else {
            return $values->$colname;
        }
    }
}
