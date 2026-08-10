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
 * Webservice: verify an entry ticket and (optionally) check the participant in.
 *
 * Single source of truth for the SofaTicket entry control — used by the browser scanner and the
 * Moodle mobile app alike. See mod/booking/classes/local/ticket/ticket_manager.php.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\event\ticket_scanned;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\singleton_service;

/**
 * External service verifying an entry ticket and checking the participant in.
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verify_ticket extends external_api {
    /**
     * Describes the parameters for verify_ticket.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_ALPHANUM, 'The ticket verification code from the QR code'),
            'checkin' => new external_value(
                PARAM_BOOL,
                'Whether to set the check-in presence status when the ticket is valid',
                VALUE_DEFAULT,
                true
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'Entry staff confirmed the holder identity. Required to check in when the option demands it.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Verify a ticket by its code and, for valid tickets, check the participant in.
     *
     * @param string $code
     * @param bool $checkin
     * @param bool $confirmed
     *
     * @return array
     */
    public static function execute(string $code, bool $checkin = true, bool $confirmed = false): array {
        global $DB, $PAGE;

        $params = external_api::validate_parameters(
            self::execute_parameters(),
            ['code' => $code, 'checkin' => $checkin, 'confirmed' => $confirmed]
        );
        $code = $params['code'];
        $checkin = $params['checkin'];
        $confirmed = $params['confirmed'];

        $result = self::empty_result();

        $ticket = ticket_manager::find_by_code($code);
        if (empty($ticket)) {
            $result['status'] = 'notfound';
            return $result;
        }

        $optionid = (int) $ticket->optionid;
        $userid = (int) $ticket->userid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id)) {
            $result['status'] = 'notfound';
            return $result;
        }

        // Capability gate: only entry staff may resolve tickets. validate_context sets up the page context.
        $context = context_module::instance($settings->cmid);
        self::validate_context($context);
        require_capability('mod/booking:scanticket', $context);

        // Descriptive fields (always returned for display, valid or not).
        $user = $DB->get_record('user', ['id' => $userid]);
        $data = json_decode($ticket->json ?? '{}');
        $result['userid'] = $userid;
        $result['fullname'] = (string) ($data->userfullname ?? ($user ? fullname($user) : ''));
        $result['eventname'] = (string) $settings->get_title_with_prefix();
        $result['eventdate'] = (int) ($settings->coursestarttime ?? 0);
        $result['issuedate'] = (int) $ticket->timecreated;
        $result['bookedcount'] = self::count_booked($optionid);
        $result['personalized'] = !empty($ticket->personalized);
        $result['requiresconfirmation'] = ticket_manager::requires_identity_confirmation($optionid);
        if (!empty($user)) {
            // Lets entry staff compare the holder against the person at the door (exam use case).
            $userpicture = new \user_picture($user);
            $userpicture->size = 200;
            $result['userpictureurl'] = $userpicture->get_url($PAGE)->out(false);
        }

        // Cancelled tickets never set a presence status.
        if (ticket_manager::is_cancelled($ticket)) {
            $result['status'] = 'revoked';
            $result['revokedtime'] = (int) $ticket->timerevoked;
            $result['presentcount'] = self::count_present($optionid);
            return $result;
        }

        $target = ticket_manager::get_checkin_status();
        $answer = self::get_active_answer($optionid, $userid);

        $result['status'] = 'valid';

        // Options demanding an identity check are only checked in once staff confirmed the holder.
        if ($result['requiresconfirmation'] && !$confirmed) {
            $checkin = false;
            $result['pendingconfirmation'] = true;
        }

        if ($answer && (int) $answer->status === $target) {
            // Already checked in earlier — do not write again, report the first check-in time.
            $result['alreadypresent'] = true;
            $result['presenttime'] = (int) $answer->timemodified;
            $result['pendingconfirmation'] = false;
        } else if ($checkin && $answer) {
            // Serialize parallel scans of the same answer: without the lock, two staff
            // devices scanning simultaneously both pass the already-present guard above
            // and both write + fire the event (double log entries).
            $lockfactory = \core\lock\lock_config::get_lock_factory('mod_booking_ticket_scan');
            $lock = $lockfactory->get_lock('answer' . $answer->id, 10);
            try {
                $current = self::get_active_answer($optionid, $userid);
                if ($current && (int) $current->status === $target) {
                    // The parallel scan won the race - report it as already present.
                    $result['alreadypresent'] = true;
                    $result['presenttime'] = (int) $current->timemodified;
                    $result['pendingconfirmation'] = false;
                } else {
                    $option = singleton_service::get_instance_of_booking_option((int) $settings->cmid, $optionid);
                    $option->changepresencestatus([$userid], $target);

                    $result['presenttime'] = time();

                    $event = ticket_scanned::create([
                        'context' => $context,
                        'objectid' => (int) $ticket->id,
                        'relateduserid' => $userid,
                        'other' => [
                            'code' => $code,
                            'optionid' => $optionid,
                            'presencestatus' => $target,
                        ],
                    ]);
                    $event->trigger();
                }
            } finally {
                if ($lock) {
                    $lock->release();
                }
            }
        }

        $result['presentcount'] = self::count_present($optionid);
        return $result;
    }

    /**
     * Default result skeleton.
     *
     * @return array
     */
    private static function empty_result(): array {
        return [
            'status' => 'notfound',
            'userid' => 0,
            'fullname' => '',
            'userpictureurl' => '',
            'eventname' => '',
            'eventdate' => 0,
            'issuedate' => 0,
            'revokedtime' => 0,
            'personalized' => false,
            'requiresconfirmation' => false,
            'pendingconfirmation' => false,
            'alreadypresent' => false,
            'presenttime' => 0,
            'presentcount' => 0,
            'bookedcount' => 0,
        ];
    }

    /**
     * The active (non-waitinglist) booking answer for a user + option, or null.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return \stdClass|null
     */
    private static function get_active_answer(int $optionid, int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record_select(
            'booking_answers',
            'optionid = :optionid AND userid = :userid AND waitinglist < 2',
            ['optionid' => $optionid, 'userid' => $userid],
            '*',
            IGNORE_MULTIPLE
        );
        return $record ?: null;
    }

    /**
     * Number of booked (non-waitinglist) participants for an option.
     *
     * @param int $optionid
     *
     * @return int
     */
    private static function count_booked(int $optionid): int {
        global $DB;
        return $DB->count_records_select('booking_answers', 'optionid = :optionid AND waitinglist < 2', ['optionid' => $optionid]);
    }

    /**
     * Number of checked-in participants for an option.
     *
     * @param int $optionid
     *
     * @return int
     */
    private static function count_present(int $optionid): int {
        global $DB;
        return $DB->count_records_select(
            'booking_answers',
            'optionid = :optionid AND waitinglist < 2 AND status = :status',
            ['optionid' => $optionid, 'status' => ticket_manager::get_checkin_status()]
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'One of: valid, revoked, notfound'),
            'userid' => new external_value(PARAM_INT, 'Id of the ticket holder (0 if the ticket was not found)'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name of the ticket holder'),
            'userpictureurl' => new external_value(PARAM_URL, 'Profile picture of the ticket holder for identity checks'),
            'eventname' => new external_value(PARAM_TEXT, 'Name of the booked option/event'),
            'eventdate' => new external_value(PARAM_INT, 'Start timestamp of the event (0 if none)'),
            'issuedate' => new external_value(PARAM_INT, 'Timestamp the ticket was issued'),
            'revokedtime' => new external_value(PARAM_INT, 'Cancellation timestamp for revoked tickets (0 otherwise)'),
            'personalized' => new external_value(PARAM_BOOL, 'True if the ticket is bound to its holder'),
            'requiresconfirmation' => new external_value(PARAM_BOOL, 'True if staff must confirm the holder identity'),
            'pendingconfirmation' => new external_value(PARAM_BOOL, 'True if the check-in is waiting for that confirmation'),
            'alreadypresent' => new external_value(PARAM_BOOL, 'True if the participant was already checked in'),
            'presenttime' => new external_value(PARAM_INT, 'Timestamp of the check-in (0 if not checked in)'),
            'presentcount' => new external_value(PARAM_INT, 'Number of checked-in participants for the option'),
            'bookedcount' => new external_value(PARAM_INT, 'Number of booked participants for the option'),
        ]);
    }
}
