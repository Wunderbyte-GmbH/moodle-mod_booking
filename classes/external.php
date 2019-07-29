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
 * @package mod_booking
 * @category external
 * @copyright 2018 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
 * @package mod_booking
 * @category external
 * @copyright 2018 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        $return = array('note' => $note, 'baid' => $baid, 'warnings' => $warnings,
            'status' => $success);
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
                array('status' => new external_value(PARAM_BOOL, 'status: true if success'),
                    'warnings' => new external_warnings(),
                    'note' => new \external_value(PARAM_TEXT, 'the updated note'),
                    'baid' => new \external_value(PARAM_INT)));
    }

    /**
     * Describes the parameters for enrol_user.
     *
     * @return external_function_parameters
     */
    public static function enrol_user_parameters() {
        return new external_function_parameters(
                array('id' => new external_value(PARAM_TEXT, 'cmid', 'CM ID', VALUE_REQUIRED, 0),
                    'answer' => new external_value(PARAM_INT, 'Answer id', 'Answer id',
                            VALUE_REQUIRED, 0),
                    'courseid' => new external_value(PARAM_TEXT, 'course id', 'Course id',
                            VALUE_REQUIRED, 0)));
    }

    /**
     * Enrol user
     *
     * @param integer $id
     * @param integer $answer
     * @param integer $courseid
     * @return string[][]|boolean[]
     */
    public static function enrol_user($id, $answer, $courseid) {
        global $DB, $USER;
        $params = self::validate_parameters(self::enrol_user_parameters(),
                array('id' => $id, 'answer' => $answer, 'courseid' => $courseid));

        $bookingdata = new \mod_booking\booking_option($id, $answer, array(), 0, 0, false);
        $bookingdata->apply_tags();
        if ($bookingdata->user_submit_response($USER)) {
            $contents = get_string('bookingsaved', 'booking');
            if ($bookingdata->booking->settings->sendmail) {
                $contents .= "<br />" . get_string('mailconfirmationsent', 'booking') . ".";
            }
        } else if (is_numeric($answer)) {
            $contents = get_string('bookingmeanwhilefull', 'booking') . " " . $bookingdata->option->text;
        }

        return array('status' => true, 'id' => $id, 'message' => htmlentities($contents), 'answer' => $answer,
                        'courseid' => $courseid);
    }

    /**
     * Returns description of method result value
     *
     * @return \external_single_structure
     * @since Moodle 3.0
     */
    public static function enrol_user_returns() {
        return new external_single_structure(
                array('status' => new external_value(PARAM_BOOL, 'status: true if success'),
                    'warnings' => new external_warnings(),
                                'message' => new \external_value(PARAM_TEXT, 'the updated note'),
                    'id' => new \external_value(PARAM_INT),
                    'answer' => new \external_value(PARAM_INT),
                    'courseid' => new \external_value(PARAM_INT)
                ));
    }

    public static function unenrol_user_parameters() {
        return new external_function_parameters(
                array('cmid' => new external_value(PARAM_TEXT, 'cmid', 'CM ID', VALUE_REQUIRED, 0),
                    'optionid' => new external_value(PARAM_INT, 'Option id', 'Option id',
                            VALUE_REQUIRED, 0),
                    'courseid' => new external_value(PARAM_TEXT, 'course id', 'Course id',
                            VALUE_REQUIRED, 0)));
    }

    public static function unenrol_user($cmid, $optionid, $courseid) {
        global $USER;

        $params = self::validate_parameters(self::unenrol_user_parameters(),
                array('cmid' => $cmid, 'optionid' => $optionid, 'courseid' => $courseid));

        $bookingdata = new \mod_booking\booking_option($cmid, $optionid, array(), 0, 0, false);
        $bookingdata->apply_tags();

        if ($bookingdata->user_delete_response($USER->id)) {
            $contents = get_string('bookingdeleted', 'booking');
        } else {
            $contents = get_string('cannotremovesubscriber', 'booking');
        }

        return array('status' => true, 'cmid' => $cmid, 'message' => htmlentities($contents),
                        'optionid' => $optionid, 'courseid' => $courseid);
    }

    public static function unenrol_user_returns() {
        return new external_single_structure(
                array('status' => new external_value(PARAM_BOOL, 'status: true if success'),
                    'warnings' => new external_warnings(),
                    'message' => new \external_value(PARAM_TEXT, 'the updated note'),
                    'cmid' => new \external_value(PARAM_INT),
                    'optionid' => new \external_value(PARAM_INT),
                                'courseid' => new \external_value(PARAM_INT)));
    }
}
