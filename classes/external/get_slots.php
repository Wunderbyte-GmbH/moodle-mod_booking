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
 * Webservice returning the selectable slots and picker meta for a booking option.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\singleton_service;

/**
 * External service: load the picker slots and meta for the slot booking calendar.
 */
class get_slots extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'optionid' => new external_value(PARAM_INT, 'booking option id'),
            'userid' => new external_value(PARAM_INT, 'user id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return the picker slots and meta as JSON.
     *
     * @param int $optionid booking option id
     * @param int $userid user id (0 = current user)
     * @return array{slots: string, meta: string}
     */
    public static function execute(int $optionid, int $userid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'optionid' => $optionid,
            'userid' => $userid,
        ]);

        $userid = $params['userid'] ?: (int)$USER->id;

        $settings = singleton_service::get_instance_of_booking_option_settings($params['optionid']);
        self::validate_context(context_module::instance($settings->cmid));
        require_capability('mod/booking:conditionforms', context_system::instance());

        return [
            'slots' => json_encode(slot_dto::build_picker_slots($params['optionid'], $userid)),
            'meta' => json_encode(slot_dto::build_meta($params['optionid'], $userid)),
        ];
    }

    /**
     * Return value description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'slots' => new external_value(PARAM_RAW, 'JSON encoded list of selectable slot DTOs'),
            'meta' => new external_value(PARAM_RAW, 'JSON encoded picker configuration meta'),
        ]);
    }
}
