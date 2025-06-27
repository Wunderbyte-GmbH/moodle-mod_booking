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
use mod_booking\booking_bookit;
use mod_booking\price;
use mod_booking\singleton_service;
use mod_booking\subbookings\subbookings_info;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for for booking class to book a booking or subbooking option.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookit extends external_api {

    /**
     * Describes the parameters for bookit.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'area' => new external_value(PARAM_RAW, 'area'),
            'itemid' => new external_value(PARAM_INT, 'itemid'),
            'userid' => new external_value(PARAM_INT, 'userid'),
            'data' => new external_value(PARAM_RAW, 'data'),
            ]
        );
    }

    /**
     * Webservice for booking class to book a booking or subbooking option.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @param string $data
     *
     * @return array
     */
    public static function execute(string $area, int $itemid, int $userid, string $data): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'itemid' => $itemid,
            'area' => $area,
            'userid' => $userid,
            'data' => $data,
        ]);

        require_login();

        $response = booking_bookit::bookit($params['area'], $params['itemid'], $params['userid'], $params['data']);

        $status = $response['status'];
        $message = $response['message'];

        if ($area == 'option') {
            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
        } else if (strpos($area, 'subbooking') === 0) {
            $subbooking = subbookings_info::get_subbooking_by_area_and_id($area, $itemid);
            $settings = singleton_service::get_instance_of_booking_option_settings($subbooking->optionid);
        } else {
            return [
                'status' => 0,
                'message' => 'bookingnotsuccessfull',
            ];
        }

        // To make sure we still render for the right user.
        price::set_bookforuser($userid);

        [$templates, $data] = booking_bookit::render_bookit_template_data($settings, $userid, false);

        return [
            'status' => $status,
            'message' => $message,
            'template' => implode(',', $templates),
            'json' => json_encode($data),
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 for success', VALUE_DEFAULT, 0),
            'message' => new external_value(PARAM_RAW, 'Message if any', VALUE_DEFAULT, ''),
            'template' => new external_value(PARAM_TEXT, 'Button template', VALUE_DEFAULT, ''),
            'json' => new external_value(PARAM_RAW, 'Data as json', VALUE_DEFAULT, ''),
            ]
        );
    }
}
