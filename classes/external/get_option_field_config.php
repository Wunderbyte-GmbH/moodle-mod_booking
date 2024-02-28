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

use dml_exception;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use mod_booking\settings\optionformconfig\optionformconfig_info;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service to create a booking option.
 *
 * @package   mod_booking
 * @copyright 2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_option_field_config extends external_api {

    /**
     * Describes the parameters for unenrol user.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT,
                'Id of context', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Returns the available capabilities to configure
     *
     * @param int $contextid
     * @return array
     * @throws dml_exception
     */
    public static function execute(
                        int $contextid = 0): array {

        $params = external_api::validate_parameters(self::execute_parameters(),
            [
                'contextid' => $contextid,
            ]
        );
        return optionformconfig_info::return_configured_fields($contextid);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure(
            [
                'id' => new external_value(PARAM_INT, 'Context ID'),
                'capability' => new external_value(PARAM_TEXT, 'Capability'),
                'json' => new external_value(PARAM_RAW, 'Payload as json'),
            ]
        ));
    }
}
