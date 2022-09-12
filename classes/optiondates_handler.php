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

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use stdClass;

/**
 * Control and manage option dates.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Thomas Winkler, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondates_handler {

    /** @var int $optionid */
    public $optionid = 0;

    /** @var int $bookingid */
    public $bookingid = 0;

    /**
     * Constructor.
     * @param int $optionid
     * @param int $bookingid
     */
    public function __construct(int $optionid = 0, int $bookingid = 0) {
        $this->optionid = $optionid;
        $this->bookingid = $bookingid;
    }

    /**
     * Returns an array of optiondates as stdClasses for a specific option id.
     *
     * @param int $optionid
     *
     * @return array
     */
    public static function get_existing_optiondates(int $optionid): array {
        global $DB;

        $records = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);

        if (count($records) > 0) {

            foreach ($records as $record) {
                $date = new stdClass();
                $date->dateid = 'dateid-' . $record->id;
                $date->starttimestamp = $record->coursestarttime;
                $date->endtimestamp = $record->courseendtime;

                // If dates are on the same day, then show date only once.
                $date->string = self::prettify_optiondates_start_end($date->starttimestamp,
                    $date->endtimestamp, current_language());

                $datearray[] = $date;
            }

            return $datearray;
        } else {
            return [];
        }
    }

    /**
     * Helper function to format option dates. If they are on the same day, show date only once.
     * Else show both dates.
     * @param int $starttimestamp
     * @param int $endtimestamp
     * @param string $lang optional language parameter
     * @param bool $showweekdays if true, weekdays will be shown
     * @return string the prettified string from start to end date
     */
    public static function prettify_optiondates_start_end(int $starttimestamp, int $endtimestamp,
        string $lang = 'en', bool $showweekdays = true): string {

        $prettifiedstring = '';

        // Only show weekdays, if they haven't been turned off.
        if ($showweekdays) {
            $weekdayformat = 'D, ';
        } else {
            $weekdayformat = '';
        }

        switch($lang) {
            case 'de':
                $stringstartdate = date($weekdayformat . 'd.m.Y', $starttimestamp);
                $stringenddate = date($weekdayformat . 'd.m.Y', $endtimestamp);
                break;
            case 'en':
            default:
                $stringstartdate = date($weekdayformat . 'Y-m-d', $starttimestamp);
                $stringenddate = date($weekdayformat . 'Y-m-d', $endtimestamp);
                break;
        }

        $stringstarttime = date('H:i', $starttimestamp);
        $stringendtime = date('H:i', $endtimestamp);

        if ($stringstartdate === $stringenddate) {
            // If they are one the same day, show date only once.
            $prettifiedstring = $stringstartdate . ' | ' . $stringstarttime . '-' . $stringendtime;
        } else {
            // Else show both dates.
            $prettifiedstring = $stringstartdate . ' | ' . $stringstarttime . ' - ' . $stringenddate . ' | ' . $stringendtime;
        }

        // Little hack that is necessary because date does not support appropriate internationalization.
        if ($showweekdays) {
            if ($lang == 'de') {
                // Note: If we want to support further languages, this should be moved to a separate function...
                // ...and be implemented with switch.
                $weekdaysenglishpatterns = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                $weekdaysreplacements = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
                for ($i = 0; $i < 7; $i++) {
                    $prettifiedstring = str_replace($weekdaysenglishpatterns[$i], $weekdaysreplacements[$i], $prettifiedstring);
                }
            }
        }

        return $prettifiedstring;
    }

    /**
     * Static helper function for mustache templates to return array with optiondates only.
     * It will return only one item containing course start and endtime if no optiondates exist.
     *
     * @param int $optionid
     * @return array an array of optiondates objects
     * @throws \dml_exception
     */
    public static function return_array_of_sessions_simple(int $optionid) {

        global $DB;

        if (!$option = $DB->get_record('booking_options', ['id' => $optionid], 'id, coursestarttime, courseendtime')) {
            return [];
        }

        // Get all currently existing optiondates of the option.
        if (!$sessions = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '',
            'id, coursestarttime, courseendtime')) {
            $session = new stdClass();
            $session->id = 1;
            $session->coursestarttime = $option->coursestarttime;
            $session->courseendtime = $option->courseendtime;
            $sessions = [$session];
        }

        $returnitem = [];

        if (count($sessions) > 0) {
            foreach ($sessions as $session) {

                $returnsession = [];

                // We show this only if timevalues are not 0.
                if ($session->coursestarttime != 0 && $session->courseendtime != 0) {
                    /* Important: Last param needs to be false, as the weekdays conversion can cause
                    problems ("Allowed memory size exhausted...")if too many options are loaded. */
                    $returnsession['datestring'] = self::prettify_optiondates_start_end($session->coursestarttime,
                        $session->courseendtime, current_language());
                }
                if ($returnsession) {
                    $returnitem[] = $returnsession;
                }
            }
        } else {
            $returnitem[] = [
                    'datestring' => self::prettify_optiondates_start_end(
                            $option->coursestarttime,
                            $option->courseendtime,
                            current_language())
            ];
        }

        return $returnitem;
    }
}
