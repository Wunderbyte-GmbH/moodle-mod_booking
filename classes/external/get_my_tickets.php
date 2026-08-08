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
 * Webservice: list the entry tickets of a user.
 *
 * Lets the mobile app show tickets wallet style, without going through the certificate pages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_system;
use context_user;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\singleton_service;

/**
 * External service returning the entry tickets of a user.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_my_tickets extends external_api {
    /**
     * Describes the parameters for get_my_tickets.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(
                PARAM_INT,
                'The user to list tickets for. 0 (default) means the current user.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Return all tickets of a user, newest first.
     *
     * @param int $userid
     *
     * @return array
     */
    public static function execute(int $userid = 0): array {
        global $USER;

        $params = external_api::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $userid = empty($params['userid']) ? (int) $USER->id : (int) $params['userid'];

        if ($userid !== (int) $USER->id) {
            // Foreign tickets may only be listed with the ticket report capability.
            require_capability('mod/booking:viewticketreport', context_system::instance());
        }
        self::validate_context(context_user::instance($userid));

        $result = [];
        foreach (ticket_manager::find_all_for_user($userid) as $ticket) {
            $settings = singleton_service::get_instance_of_booking_option_settings((int) $ticket->optionid);
            $fileurl = ticket_manager::get_file_url($ticket);

            $result[] = [
                'ticketid' => (int) $ticket->id,
                'optionid' => (int) $ticket->optionid,
                'optionname' => (string) ($settings->get_title_with_prefix() ?? ''),
                'cmid' => (int) ($settings->cmid ?? 0),
                'code' => (string) $ticket->code,
                'status' => (string) $ticket->status,
                'personalized' => !empty($ticket->personalized),
                'timecreated' => (int) $ticket->timecreated,
                'coursestarttime' => (int) ($settings->coursestarttime ?? 0),
                'location' => (string) ($settings->location ?? ''),
                'fileurl' => empty($fileurl) ? '' : $fileurl->out(false),
                'verifyurl' => ticket_manager::get_verify_url($ticket)->out(false),
            ];
        }

        return $result;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'ticketid' => new external_value(PARAM_INT, 'Id of the ticket'),
                'optionid' => new external_value(PARAM_INT, 'Id of the booking option'),
                'optionname' => new external_value(PARAM_TEXT, 'Name of the booking option'),
                'cmid' => new external_value(PARAM_INT, 'Course module id of the booking instance'),
                'code' => new external_value(PARAM_ALPHANUM, 'Verification code of the ticket'),
                'status' => new external_value(PARAM_ALPHA, 'One of: valid, cancelled'),
                'personalized' => new external_value(PARAM_BOOL, 'True if the ticket is bound to its holder'),
                'timecreated' => new external_value(PARAM_INT, 'Time the ticket was created'),
                'coursestarttime' => new external_value(PARAM_INT, 'Start of the event (0 if none)'),
                'location' => new external_value(PARAM_TEXT, 'Location of the event'),
                'fileurl' => new external_value(PARAM_URL, 'Download URL of the ticket PDF (empty if not created yet)'),
                'verifyurl' => new external_value(PARAM_URL, 'Public verification URL of the ticket'),
            ])
        );
    }
}
