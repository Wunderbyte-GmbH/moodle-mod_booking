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
 * Webservice: record that entry staff rejected a scanned ticket.
 *
 * Writes no presence status; it only leaves an audit trail (ticket_rejected event) so refused
 * entries can be reviewed and booking rules can react to them.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\event\ticket_rejected;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\singleton_service;

/**
 * External service recording a rejected entry ticket.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reject_ticket extends external_api {
    /**
     * Describes the parameters for reject_ticket.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_ALPHANUM, 'The ticket verification code from the QR code'),
            'optiondateid' => new external_value(
                PARAM_INT,
                'The option date (session) the entry was refused for, 0 if none',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Record the rejection of a ticket.
     *
     * @param string $code
     * @param int $optiondateid
     *
     * @return array
     */
    public static function execute(string $code, int $optiondateid = 0): array {
        $params = external_api::validate_parameters(
            self::execute_parameters(),
            ['code' => $code, 'optiondateid' => $optiondateid]
        );
        $code = $params['code'];
        $optiondateid = $params['optiondateid'];

        $ticket = ticket_manager::find_by_code($code);
        if (empty($ticket)) {
            return ['status' => 'notfound'];
        }
        $optionid = (int) $ticket->optionid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id)) {
            return ['status' => 'notfound'];
        }

        $context = context_module::instance($settings->cmid);
        self::validate_context($context);
        require_capability('mod/booking:scanticket', $context);

        $event = ticket_rejected::create([
            'context' => $context,
            'objectid' => (int) $ticket->id,
            'relateduserid' => (int) $ticket->userid,
            'other' => [
                'code' => $code,
                'optionid' => $optionid,
                'optiondateid' => $optiondateid,
            ],
        ]);
        $event->trigger();

        return ['status' => 'rejected'];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'One of: rejected, notfound'),
        ]);
    }
}
