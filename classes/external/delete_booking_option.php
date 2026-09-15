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
 * This class contains a webservice function related to the Booking Module by Wunderbyte.
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
use mod_booking\singleton_service;
use moodle_exception;

/**
 * External service: delete a booking option (incl. answers, optiondates etc.).
 *
 * Called from the delete confirmation modal (mod_booking/deletebookingoptionmodal),
 * which replaced the old action=deletebookingoption URL flow on report.php.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_booking_option extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'course module id of the booking instance'),
            'optionid' => new external_value(PARAM_INT, 'id of the booking option to delete'),
        ]);
    }

    /**
     * Delete the given booking option.
     *
     * @param int $cmid course module id of the booking instance
     * @param int $optionid id of the booking option to delete
     * @return array{success: bool}
     */
    public static function execute(int $cmid, int $optionid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'optionid' => $optionid,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/booking:updatebooking', $context);

        $settings = singleton_service::get_instance_of_booking_option_settings($params['optionid']);
        if (empty($settings->id)) {
            throw new moodle_exception('nooptionid', 'mod_booking');
        }
        // The capability was checked on the context of the given course module, so
        // the option to delete has to belong to exactly that booking instance.
        if ((int)$settings->cmid !== $params['cmid']) {
            $a = new \stdClass();
            $a->optionid = $params['optionid'];
            $a->cmid = $params['cmid'];
            throw new moodle_exception('error:optionnotinthisinstance', 'mod_booking', '', $a);
        }

        $bookingoption = singleton_service::get_instance_of_booking_option($params['cmid'], $params['optionid']);
        $success = $bookingoption->delete_booking_option();

        return [
            'success' => $success,
        ];
    }

    /**
     * Return value description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'true if the booking option was deleted'),
        ]);
    }
}
