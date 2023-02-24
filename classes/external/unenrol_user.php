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
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for unenrol user.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unenrol_user extends external_api {

    /**
     * Describes the parameters for unenrol user.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'CM ID'),
            'optionid' => new external_value(PARAM_INT, 'Option id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            ]
        );
    }

    /**
     * Webservice for unenrol user.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $courseid
     *
     * @return array
     */
    public static function execute(int $cmid, int $optionid, int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(),
                ['cmid' => $cmid, 'optionid' => $optionid, 'courseid' => $courseid]);

        $bookingdata = new booking_option($cmid, $optionid, [], 0, 0, false);
        $bookingdata->apply_tags();

        if ($bookingdata->user_delete_response($USER->id)) {
            $contents = get_string('bookingdeleted', 'booking');
        } else {
            $contents = get_string('cannotremovesubscriber', 'booking');
        }

        return [
            'status' => true,
            'cmid' => $cmid,
            'message' => htmlentities($contents),
            'optionid' => $optionid,
            'courseid' => $courseid
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'status: true if success'),
            'warnings' => new external_warnings(),
            'message' => new external_value(PARAM_TEXT, 'the updated note'),
            'cmid' => new external_value(PARAM_INT),
            'optionid' => new external_value(PARAM_INT),
            'courseid' => new external_value(PARAM_INT),
            ]
        );
    }
}
