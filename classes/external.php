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
 * booking module external API
 *
 * @package    mod_booking
 * @category   external
 * @copyright  2018 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_warnings;
use stdClass;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/externallib.php');

/**
 * booking module external functions
 *
 * @package    mod_booking
 * @category   external
 * @copyright  2018 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends external_api {

    /**
     * Describes the parameters for update_bookingnotes.
     *
     * @return external_function_parameters
     */
    public static function update_bookingnotes_parameters() {
        return new external_function_parameters(
                array(
                    'baid' => new external_value(PARAM_INT, 'booking_answer id',
                            'ID of the booking answer', VALUE_REQUIRED, 0),
                    'note' => new external_value(PARAM_TEXT, 'booking_answer note',
                            'Note added to the booking answer', VALUE_DEFAULT, '')));
    }


    /**
     * Update the notes in booking_answers table
     *
     * @param integer $baid
     * @param string $note
     * @return string[][]|boolean[]
     */
    public static function update_bookingnotes($baid, $note) {
        global $DB;
        $params = self::validate_parameters(self::update_bookingnotes_parameters(),
                array('baid' => $baid, 'note' => $note));

        $dataobject = new stdClass();
        $dataobject->id = $baid;
        $dataobject->notes = $note;
        $warnings = array();
        // Check if entry exists in DB.
        if (!$DB->record_exists('booking_answers', array('id' => $dataobject->id))) {
            $warnings[] = 'Invalid booking';
        }

        $success = $DB->update_record('booking_answers', $dataobject);
        $return = array('note' => $note,
                        'baid' => $baid,
                        'warnings' => $warnings,
                        'status' => $success
        );
        return $return;
    }

    /**
     * Expose to AJAX
     *
     * @return boolean
     */
    public static function update_bookingnotes_is_allowed_from_ajax() {
        return true;
    }

    /**
     * Returns description of method result value
     *
     * @return \external_single_structure
     * @since Moodle 3.0
     */
    public static function update_bookingnotes_returns() {
        return new external_single_structure(
                array(
                                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                                'warnings' => new external_warnings(),
                                'note' => new \external_value(PARAM_TEXT, 'the updated note'),
                                'baid' => new \external_value(PARAM_INT)
                )
                );
    }
}
