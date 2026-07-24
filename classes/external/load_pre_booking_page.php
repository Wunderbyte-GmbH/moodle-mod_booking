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
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use mod_booking\bo_availability\bo_info;

/**
 * External Service for load pre_booking page.
 *
 * @package   mod_booking
 * @copyright 2023 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class load_pre_booking_page extends external_api {
    /**
     * Describes the parameters for load_pre_booking_page.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'optionid' => new external_value(PARAM_INT, 'option id'),
            'userid' => new external_value(PARAM_INT, 'user id', VALUE_DEFAULT, 0),
            'pagenumber' => new external_value(PARAM_INT, 'number of page we want to load'),
            'skipcondition' => new external_value(
                PARAM_ALPHANUMEXT,
                'condition shortname to skip (e.g. slotbooking)',
                VALUE_DEFAULT,
                ''
            ),
            ]);
    }

    /**
     * Webservice for load_pre_booking_page.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $pagenumber
     * @param string $skipcondition optional condition shortname to exclude from the sorted pages
     *
     * @return array
     */
    public static function execute(int $optionid, int $userid, int $pagenumber, string $skipcondition = ''): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'optionid' => $optionid,
                'userid' => $userid,
                'pagenumber' => $pagenumber,
                'skipcondition' => $skipcondition,
            ]
        );

        // The user needs access to the booking instance the option belongs to.
        $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($params['optionid']);
        self::validate_context(
            empty($settings->cmid)
                ? \context_system::instance()
                : \context_module::instance($settings->cmid)
        );
        // Loading the pre booking pages of another user needs the book for others (or cashier) rights.
        \mod_booking\form\condition\customform_form::require_userid_access($params['userid'], $params['optionid']);

        $result = bo_info::load_pre_booking_page(
            $params['optionid'],
            $params['pagenumber'],
            $params['userid'],
            $params['skipcondition']
        );

        return $result;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_function_parameters
     */
    public static function execute_returns(): external_function_parameters {
        return new external_function_parameters(
            [
                'json' => new external_value(
                    PARAM_RAW,
                    'The data object in jsonformat to render the content.',
                    VALUE_REQUIRED
                ),
                'template' => new external_value(
                    PARAM_TEXT,
                    'The name of the template which is needed to render the content.',
                    VALUE_REQUIRED
                ),
                'buttontype' => new external_value(
                    PARAM_INT,
                    '0 for no button, 1 for continue, 2 for last button.',
                    VALUE_REQUIRED
                ),
            ]
        );
    }
}
