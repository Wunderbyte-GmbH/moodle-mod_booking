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
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for getting instance template.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_bookingnotes extends external_api {

    /**
     * Describes the parameters for update_bookingnotes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'baid' => new external_value(PARAM_INT, 'ID of the booking answer'),
            'note' => new external_value(PARAM_TEXT, 'Note added to the booking answer', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Webservice for update the notes in booking_answers table.
     *
     * @param int $baid
     * @param string $note
     *
     * @return array
     */
    public static function execute(int $baid, string $note = ''): array {
        global $DB;

        $params = external_api::validate_parameters(self::execute_parameters(), ['baid' => $baid, 'note' => $note]);

        $dataobject = new stdClass();
        $dataobject->id = $baid;
        $dataobject->notes = $note;
        $warnings = [];
        // Check if entry exists in DB.
        if (!$DB->record_exists('booking_answers', ['id' => $dataobject->id])) {
            $warnings[] = 'Invalid booking';
        }

        $success = $DB->update_record('booking_answers', $dataobject);

        return [
            'note' => $note,
            'baid' => $baid,
            'warnings' => $warnings,
            'status' => $success,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Status: true if success'),
            'warnings' => new external_warnings(),
            'note' => new external_value(PARAM_TEXT, 'The updated note'),
            'baid' => new external_value(PARAM_INT, 'ID of the booking answer'),
            ]
        );
    }
}
