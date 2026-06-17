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
 * Webservice that releases (cancels) individual booked slots for the participant themselves.
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
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\singleton_service;

/**
 * External service: self-service partial cancellation of booked slots.
 */
class release_slots extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'optionid' => new external_value(PARAM_INT, 'booking option id'),
            'baid' => new external_value(PARAM_INT, 'booking answer id'),
            'releaseslots' => new external_value(PARAM_RAW, 'JSON encoded list of slot keys to release'),
            'reason' => new external_value(PARAM_TEXT, 'cancellation reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Release the selected booked slots.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param string $releaseslots JSON encoded list of slot keys to release
     * @param string $reason cancellation reason
     * @return array{success: bool, released: int, remaining: int, cancelled: bool}
     */
    public static function execute(
        int $optionid,
        int $baid,
        string $releaseslots,
        string $reason = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'optionid' => $optionid,
            'baid' => $baid,
            'releaseslots' => $releaseslots,
            'reason' => $reason,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($params['optionid']);
        self::validate_context(context_module::instance($settings->cmid));

        $keys = json_decode($params['releaseslots'], true);
        if (!is_array($keys)) {
            $keys = array_filter(array_map('trim', explode(',', (string)$params['releaseslots'])));
        }
        $keys = array_map('strval', $keys);

        $result = slot_mover::release_self($params['optionid'], $params['baid'], $keys, $params['reason']);

        return [
            'success' => true,
            'released' => (int)$result['released'],
            'remaining' => (int)$result['remaining'],
            'cancelled' => (bool)$result['cancelled'],
        ];
    }

    /**
     * Return value description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'whether the release succeeded'),
            'released' => new external_value(PARAM_INT, 'number of slots released'),
            'remaining' => new external_value(PARAM_INT, 'number of slots still booked'),
            'cancelled' => new external_value(PARAM_BOOL, 'whether the whole booking was cancelled'),
        ]);
    }
}
