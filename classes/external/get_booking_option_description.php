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
use external_single_structure;
use external_value;
use external_warnings;
use mod_booking\booking_option;
use mod_booking\output\bookingoption_description;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for get booking option description.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_booking_option_description extends external_api {

    /**
     * Describes the parameters for get_booking_option_description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'optionid' => new external_value(PARAM_INT, 'Option id'),
            'userid' => new external_value(PARAM_INT, 'userid'),
            ]
        );
    }

    /**
     * Webservice for get_booking_option_description.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return array
     */
    public static function execute(int $optionid, int $userid): array {

        $params = self::validate_parameters(self::execute_parameters(),
                ['optionid' => $optionid, 'userid' => $userid]);

        $booking = singleton_service::get_instance_of_booking_by_optionid($optionid);

        if ($userid > 0) {
            $user = singleton_service::get_instance_of_user($userid);
        } else {
            $user = null;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

        // Check if user is booked.
        $forbookeduser = $bookinganswer->user_status($userid) == MOD_BOOKING_STATUSPARAM_BOOKED ? true : false;

        $data = new bookingoption_description($optionid, null, MOD_BOOKING_DESCRIPTION_WEBSITE, true, $forbookeduser, $user);

        // Fix invisible attribute, by converting to bool.
        if (isset($data->invisible) && $data->invisible == 1) {
            $data->invisible = true;
        } else {
            $data->invisible = false;
        }

        return [
            'content' => json_encode($data),
            'template' => 'mod_booking/bookingoption_description',
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'content' => new external_value(PARAM_RAW, 'json object as string'),
            'template' => new external_value(PARAM_TEXT, 'the template to render the content'),
            ]
        );
    }
}
