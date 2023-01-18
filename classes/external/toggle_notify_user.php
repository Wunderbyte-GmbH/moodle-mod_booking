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
 * This class contains a list of webservice functions related to the Shopping Cart Module by Wunderbyte.
 *
 * @package    mod_booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_system;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use mod_booking\subbookings\subbookings_info;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for shopping cart.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_notify_user extends external_api {

    /**
     * Describes the parameters for add_item_to_cart.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            array('userid' => new external_value(PARAM_INT, 'user id', VALUE_REQUIRED, 0),
                  'optionid' => new external_value(PARAM_TEXT, 'option id', VALUE_REQUIRED, 0)
            )
        );
    }

    /**
     * Webservice for booking class to toggle notification for user.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     *
     * @return array
     */
    public static function execute(int $userid, int $optionid): array {
        $params = self::validate_parameters(self::execute_parameters(),
                array('optionid' => $optionid, 'userid' => $userid));

        $result = booking_option::toggle_notify_user($params['userid'], $params['optionid']);

        return $result;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_function_parameters(
            [
                'status' => new external_value(PARAM_INT, 'Status 1 for user is now on list, 0 for not on list.',
                    VALUE_REQUIRED),
                'optionid' => new external_value(PARAM_INT, 'option id', VALUE_REQUIRED),
                'error' => new external_value(PARAM_RAW, 'error', VALUE_REQUIRED),
            ]
        );
    }
}
