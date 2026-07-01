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
 * Webservice returning the booked-slot report data for a booking option.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\local\slotbooking\slot_dto;

/**
 * External service: load the booked-slot calendar report data.
 */
class get_booked_slots extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'course module id'),
            'optionid' => new external_value(PARAM_INT, 'booking option id'),
        ]);
    }

    /**
     * Return the booked slots and per-slot details as JSON.
     *
     * @param int $cmid course module id
     * @param int $optionid booking option id
     * @return array{slots: string, details: string}
     */
    public static function execute(int $cmid, int $optionid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'optionid' => $optionid,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/booking:view', $context);

        $report = slot_dto::build_report_slots($params['optionid'], $params['cmid']);

        return [
            'slots' => json_encode($report['slots']),
            'details' => json_encode($report['details']),
        ];
    }

    /**
     * Return value description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'slots' => new external_value(PARAM_RAW, 'JSON encoded list of booked slot summaries'),
            'details' => new external_value(PARAM_RAW, 'JSON encoded map of per-slot details'),
        ]);
    }
}
