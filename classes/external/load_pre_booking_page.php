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

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use mod_booking\bo_availability\bo_info;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

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
            ]);
    }

    /**
     * Webservice for load_pre_booking_page.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $pagenumber
     *
     * @return array
     */
    public static function execute(int $optionid, int $userid, int $pagenumber): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'optionid' => $optionid,
                'userid' => $userid,
                'pagenumber' => $pagenumber,
            ]
        );

        $result = bo_info::load_pre_booking_page($params['optionid'], $params['pagenumber'], $params['userid']);

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
                    PARAM_RAW,
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
