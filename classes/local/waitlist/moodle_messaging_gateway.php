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
 * Sends waitlist-progression notifications via the existing message_controller
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.4). notify_offer() mirrors the existing
 * send_mail_interval/send_mail_by_rule_adhoc pattern (subject/template read from the rule's own
 * actiondata); notify_autobooked() mirrors the existing waitlist-sync autobook pattern
 * (booking_option::write_user_answer_to_db()'s MOD_BOOKING_MSGPARAM_STATUS_CHANGED call) - the
 * option's own status-change templates, not a rule-specific one.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\message_controller;
use mod_booking\singleton_service;

/**
 * message_controller-backed implementation of messaging_gateway.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodle_messaging_gateway implements messaging_gateway {
    /**
     * Sends the offer notification, subject/template taken from the rule's own actiondata - same
     * source send_mail_interval uses today. Sends nothing if $ruleid no longer resolves to a
     * rule (defensive - avoids an empty-subject mail rather than guessing a fallback template).
     *
     * @param waitlist_offer $offer
     * @param int $ruleid
     * @return void
     */
    public function notify_offer(waitlist_offer $offer, int $ruleid): void {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($offer->optionid);
        if (empty($settings)) {
            return;
        }

        $rulerecord = $DB->get_record('booking_rules', ['id' => $ruleid], '*', IGNORE_MISSING);
        if (empty($rulerecord)) {
            return;
        }
        $rulejson = json_decode($rulerecord->rulejson);
        $subject = $rulejson->actiondata->subject ?? '';
        $template = $rulejson->actiondata->template ?? '';

        $messagecontroller = new message_controller(
            MOD_BOOKING_MSGCONTRPARAM_SEND_NOW,
            MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE,
            $settings->cmid,
            $offer->optionid,
            $offer->userid,
            null,
            null,
            null,
            $subject,
            $template,
            0,
            0,
            0.0,
            '',
            $ruleid
        );
        $messagecontroller->send_or_queue();
    }

    /**
     * Sends the autobooked notification via the option's own status-change templates - the same
     * message type the existing waitlist-sync autobook path uses today.
     *
     * @param booking_waitlist_candidate $candidate
     * @param int $ruleid
     * @return void
     */
    public function notify_autobooked(booking_waitlist_candidate $candidate, int $ruleid): void {
        $settings = singleton_service::get_instance_of_booking_option_settings($candidate->optionid);
        if (empty($settings)) {
            return;
        }

        $messagecontroller = new message_controller(
            MOD_BOOKING_MSGCONTRPARAM_SEND_NOW,
            MOD_BOOKING_MSGPARAM_STATUS_CHANGED,
            $settings->cmid,
            $candidate->optionid,
            $candidate->userid,
            $settings->bookingid ?? null,
            null,
            null,
            '',
            '',
            0,
            0,
            0.0,
            '',
            $ruleid
        );
        $messagecontroller->send_or_queue();
    }
}
