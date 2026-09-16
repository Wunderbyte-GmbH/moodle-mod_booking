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
 * Options with several dates (sessions) are checked in per date: the presence is stored in
 * booking_optiondates_answers for the selected date, and additionally on the booking answer the first
 * time the participant is admitted. Without an explicit optiondateid the nearest date is chosen.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use cache_helper;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\event\ticket_scanned;
use mod_booking\local\optiondates\optiondate_answer;
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
                'Whether to set the check-in presence status when the ticket is valid (false = lookup only)',
                VALUE_DEFAULT,
                true
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'Entry staff confirmed the holder identity. Required to check in when the option demands it.',
                VALUE_DEFAULT,
                false
            ),
            'optiondateid' => new external_value(
                PARAM_INT,
                'The option date (session) to check in for. 0 = the nearest date of the option.',
                VALUE_DEFAULT,
                0
            ),
            'expectedoptionid' => new external_value(
                PARAM_INT,
                'Scanner started for this option: tickets of other options are refused (wrongoption). 0 = any option.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Verify a ticket by its code and, for valid tickets, check the participant in.
     *
     * @param string $code
     * @param bool $checkin
     * @param bool $confirmed
     * @param int $optiondateid
     * @param int $expectedoptionid
     *
     * @return array
     */
    public static function execute(
        string $code,
        bool $checkin = true,
        bool $confirmed = false,
        int $optiondateid = 0,
        int $expectedoptionid = 0
    ): array {
        global $DB, $PAGE;

        $params = external_api::validate_parameters(
            self::execute_parameters(),
            [
                'code' => $code,
                'checkin' => $checkin,
                'confirmed' => $confirmed,
                'optiondateid' => $optiondateid,
                'expectedoptionid' => $expectedoptionid,
            ]
        );
        $code = $params['code'];
        $checkin = $params['checkin'];
        $confirmed = $params['confirmed'];
        $optiondateid = $params['optiondateid'];
        $expectedoptionid = $params['expectedoptionid'];

        $result = self::empty_result();

        // Scanner started for one option: the staff must be allowed to scan THAT option, and the
        // permission is settled before anything about the ticket is revealed.
        $expected = null;
        if ($expectedoptionid) {
            $expected = singleton_service::get_instance_of_booking_option_settings($expectedoptionid);
            if (empty($expected->id)) {
                throw new \moodle_exception('invalidrecord', 'error', '', 'booking_options');
            }
            self::validate_context(context_module::instance($expected->cmid));
            ticket_manager::require_can_scan((int) $expected->cmid, $expectedoptionid);
        }

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

        if ($expected !== null && $optionid !== $expectedoptionid) {
            // A ticket of another event: say which one, but nothing about its holder.
            $result['status'] = 'wrongoption';
            $result['optionid'] = $optionid;
            $result['eventname'] = (string) $settings->get_title_with_prefix();
            $result['expectedeventname'] = (string) $expected->get_title_with_prefix();
            return $result;
        }

        // Instance-wide scanner: the permission is decided on the ticket's booking instance
        // (capability only, see ticket_manager::can_scan()). validate_context sets up the page context.
        $context = context_module::instance($settings->cmid);
        if ($expected === null) {
            self::validate_context($context);
            ticket_manager::require_can_scan((int) $settings->cmid, 0);
        }

        // Availability window of the ticket's option (thresholds around its dates).
        $window = ticket_manager::get_scan_window($optionid);
        if (!$window['open']) {
            $result['status'] = 'closed';
            $result['optionid'] = $optionid;
            $result['eventname'] = (string) $settings->get_title_with_prefix();
            $result['nextopen'] = (int) $window['nextopen'];
            return $result;
        }

        $target = ticket_manager::get_checkin_status();

        // Dates of the option and the date this scan refers to.
        $sessions = ticket_manager::real_sessions($settings->sessions ?? []);
        if (!empty($sessions) && !isset($sessions[$optiondateid])) {
            $optiondateid = ticket_manager::pick_nearest_optiondate($sessions);
        } else if (empty($sessions)) {
            $optiondateid = 0;
        }
        $result['optionid'] = $optionid;
        $result['optiondateid'] = $optiondateid;
        $result['dates'] = ticket_manager::get_scan_dates($optionid, $userid);
        $result['eventdatelabel'] = $optiondateid
            ? ticket_manager::date_label($sessions[$optiondateid])
            : '';

        // Descriptive fields (always returned for display, valid or not).
        $user = $DB->get_record('user', ['id' => $userid]);
        $data = json_decode($ticket->json ?? '{}');
        $result['userid'] = $userid;
        $result['fullname'] = (string) ($data->userfullname ?? ($user ? fullname($user) : ''));
        $result['eventname'] = (string) $settings->get_title_with_prefix();
        $result['eventdate'] = (int) ($optiondateid
            ? $sessions[$optiondateid]->coursestarttime
            : ($settings->coursestarttime ?? 0));
        $result['issuedate'] = (int) $ticket->timecreated;
        $result['bookedcount'] = self::count_booked($optionid);
        $result['personalized'] = !empty($ticket->personalized);
        $result['requiresconfirmation'] = ticket_manager::requires_identity_confirmation($optionid);
        if (!empty($user)) {
            // Lets entry staff compare the holder against the person at the door.
            $userpicture = new \user_picture($user);
            $userpicture->size = 200;
            $result['userpictureurl'] = $userpicture->get_url($PAGE)->out(false);
            if ($result['personalized'] || $result['requiresconfirmation']) {
                $result['identityfields'] = ticket_manager::get_identity_fields($userid);
            }
        }

        // Cancelled tickets never set a presence status.
        if (ticket_manager::is_cancelled($ticket)) {
            $result['status'] = 'revoked';
            $result['revokedtime'] = (int) $ticket->timerevoked;
            $result['presentcount'] = self::count_present($optionid, $optiondateid);
            return $result;
        }

        $answer = self::get_active_answer($optionid, $userid);

        $result['status'] = 'valid';

        // Options demanding an identity check are only checked in once staff confirmed the holder.
        if ($checkin && $result['requiresconfirmation'] && !$confirmed) {
            $checkin = false;
            $result['pendingconfirmation'] = true;
        }

        $presenttime = self::present_time($answer, $optionid, $optiondateid, $userid, $target);
        if ($presenttime !== null) {
            // Already checked in earlier — do not write again, report the first check-in time.
            $result['alreadypresent'] = true;
            $result['presenttime'] = $presenttime;
            $result['pendingconfirmation'] = false;
        } else if ($checkin && $answer) {
            // Serialize parallel scans of the same answer: without the lock, two staff
            // devices scanning simultaneously both pass the already-present guard above
            // and both write + fire the event (double log entries).
            $lockfactory = \core\lock\lock_config::get_lock_factory('mod_booking_ticket_scan');
            $lock = $lockfactory->get_lock('answer' . $answer->id, 10);
            try {
                $current = self::get_active_answer($optionid, $userid);
                $currenttime = self::present_time($current, $optionid, $optiondateid, $userid, $target);
                if ($currenttime !== null) {
                    // The parallel scan won the race - report it as already present.
                    $result['alreadypresent'] = true;
                    $result['presenttime'] = $currenttime;
                    $result['pendingconfirmation'] = false;
                } else {
                    if ($optiondateid) {
                        $dateanswer = new optiondate_answer($userid, $optiondateid, $optionid);
                        $dateanswer->add_or_update_status($target);
                    }
                    if ($current && (int) $current->status !== $target) {
                        // First admission: the booking answer itself becomes "checked in" too, so
                        // single-date options and answer-level reports keep working as before.
                        $option = singleton_service::get_instance_of_booking_option((int) $settings->cmid, $optionid);
                        $option->changepresencestatus([$userid], $target);
                    }
                    cache_helper::purge_by_event('setbackbookedusertable');

                    $result['presenttime'] = time();

                    $event = ticket_scanned::create([
                        'context' => $context,
                        'objectid' => (int) $ticket->id,
                        'relateduserid' => $userid,
                        'other' => [
                            'code' => $code,
                            'optionid' => $optionid,
                            'optiondateid' => $optiondateid,
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

        $result['presentcount'] = self::count_present($optionid, $optiondateid);
        if ($optiondateid) {
            // Refresh the per-date presence flags after a write.
            $result['dates'] = ticket_manager::get_scan_dates($optionid, $userid);
        }
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
            'identityfields' => [],
            'optionid' => 0,
            'optiondateid' => 0,
            'dates' => [],
            'eventname' => '',
            'expectedeventname' => '',
            'eventdate' => 0,
            'eventdatelabel' => '',
            'nextopen' => 0,
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
     * Time the participant was checked in for this scan's scope, or null if not checked in.
     *
     * With a selected date the per-date presence decides; without dates the answer status does.
     *
     * @param \stdClass|null $answer
     * @param int $optionid
     * @param int $optiondateid
     * @param int $userid
     * @param int $target
     *
     * @return int|null
     */
    private static function present_time(
        ?\stdClass $answer,
        int $optionid,
        int $optiondateid,
        int $userid,
        int $target
    ): ?int {
        if ($optiondateid) {
            return ticket_manager::is_present_on_date($optionid, $optiondateid, $userid);
        }
        if ($answer && (int) $answer->status === $target) {
            return (int) $answer->timemodified;
        }
        return null;
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
     * Number of checked-in participants: for the given date if there is one, else for the option.
     *
     * @param int $optionid
     * @param int $optiondateid
     *
     * @return int
     */
    private static function count_present(int $optionid, int $optiondateid = 0): int {
        global $DB;
        if ($optiondateid) {
            return ticket_manager::count_present_on_date($optionid, $optiondateid);
        }
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
            'status' => new external_value(
                PARAM_ALPHA,
                'One of: valid, revoked, notfound, wrongoption (ticket of another option), closed (outside the scan window)'
            ),
            'userid' => new external_value(PARAM_INT, 'Id of the ticket holder (0 if the ticket was not found)'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name of the ticket holder'),
            'userpictureurl' => new external_value(PARAM_URL, 'Profile picture of the ticket holder for identity checks'),
            'identityfields' => new external_multiple_structure(
                new external_single_structure([
                    'shortname' => new external_value(PARAM_TEXT, 'Field key as configured in the identity fields setting'),
                    'name' => new external_value(PARAM_TEXT, 'Field label'),
                    'value' => new external_value(PARAM_TEXT, 'Field value of the ticket holder'),
                ]),
                'Identity data to compare against the person at the door (personalised tickets only)'
            ),
            'optionid' => new external_value(PARAM_INT, 'Id of the booked option (0 if the ticket was not found)'),
            'optiondateid' => new external_value(PARAM_INT, 'The option date this scan refers to (0 if the option has no dates)'),
            'dates' => new external_multiple_structure(
                new external_single_structure([
                    'optiondateid' => new external_value(PARAM_INT, 'Option date id'),
                    'starttime' => new external_value(PARAM_INT, 'Start timestamp'),
                    'endtime' => new external_value(PARAM_INT, 'End timestamp'),
                    'label' => new external_value(PARAM_TEXT, 'Localized date label'),
                    'present' => new external_value(PARAM_BOOL, 'True if the holder is checked in for this date'),
                ]),
                'All dates of the option, for the date selection'
            ),
            'eventname' => new external_value(PARAM_TEXT, 'Name of the booked option/event'),
            'expectedeventname' => new external_value(PARAM_TEXT, 'Name of the option the scanner was started for (wrongoption)'),
            'nextopen' => new external_value(PARAM_INT, 'Timestamp the scanner opens next (closed), 0 if unknown'),
            'eventdate' => new external_value(PARAM_INT, 'Start timestamp of the selected date, or of the event (0 if none)'),
            'eventdatelabel' => new external_value(PARAM_TEXT, 'Localized label of the selected date (empty if none)'),
            'issuedate' => new external_value(PARAM_INT, 'Timestamp the ticket was issued'),
            'revokedtime' => new external_value(PARAM_INT, 'Cancellation timestamp for revoked tickets (0 otherwise)'),
            'personalized' => new external_value(PARAM_BOOL, 'True if the ticket is bound to its holder'),
            'requiresconfirmation' => new external_value(PARAM_BOOL, 'True if staff must confirm the holder identity'),
            'pendingconfirmation' => new external_value(PARAM_BOOL, 'True if the check-in is waiting for that confirmation'),
            'alreadypresent' => new external_value(PARAM_BOOL, 'True if the participant was already checked in (for the date)'),
            'presenttime' => new external_value(PARAM_INT, 'Timestamp of the check-in (0 if not checked in)'),
            'presentcount' => new external_value(PARAM_INT, 'Number of checked-in participants for the date, or the option'),
            'bookedcount' => new external_value(PARAM_INT, 'Number of booked participants for the option'),
        ]);
    }
}
