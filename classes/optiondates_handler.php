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

use cache_helper;
use mod_booking\semester;
use MoodleQuickForm;
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
     * Add form fields to be passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @param bool $loadexistingdates Only if this param is set to true, we'll load the already existing dates.
     * @return void
     */
    public function add_optiondates_for_semesters_to_mform(MoodleQuickForm &$mform, bool $loadexistingdates) {
        global $PAGE;

        $bookingoptionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        $semestersarray = semester::get_semesters_id_name_array();

        $mform->addElement('autocomplete', 'chooseperiod', get_string('chooseperiod', 'mod_booking'),
            $semestersarray, ['tags' => false]);
        $mform->setDefault('chooseperiod', $bookingoptionsettings->semesterid);
        $mform->addHelpButton('chooseperiod', 'chooseperiod', 'mod_booking');

        $mform->addElement('text', 'reoccurringdatestring', get_string('reoccurringdatestring', 'booking'));
        $mform->setDefault('reoccurringdatestring', $bookingoptionsettings->dayofweektime);
        $mform->addHelpButton('reoccurringdatestring', 'reoccurringdatestring', 'mod_booking');
        $mform->setType('reoccurringdatestring', PARAM_TEXT);

        if ($loadexistingdates) {
            // Add already existing optiondates to form.
            $output = $PAGE->get_renderer('mod_booking');
            $data = new \mod_booking\output\bookingoption_dates($this->optionid);
            $mform->addElement('html', '<div class="optiondates-list">');
            $mform->addElement('html', $output->render_bookingoption_dates($data));
            $mform->addElement('html', '</div>');
        } else {
            $mform->addElement('html', '<div class="optiondates-list"></div>');
        }
    }

    /**
     * Transform each optiondate and save.
     *
     * @param stdClass $fromform form data
     * @param array $optiondates array of optiondates as strings (e.g. "11646647200-1646650800")
     */
    public function save_from_form(stdClass $fromform) {
        global $DB;

        if ($this->optionid && $this->bookingid) {

            // Get the currently saved optiondateids from DB.
            $olddates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid]);

            // Now, let's check, if they have not been removed by the dynamic form.
            foreach ($olddates as $olddate) {

                if (isset($fromform->stillexistingdates[(int) $olddate->id])) {

                    $stillexistingdatestring = $fromform->stillexistingdates[(int) $olddate->id];
                    list($starttime, $endtime) = explode('-', $stillexistingdatestring);

                    // Check if start time or end time has changed.
                    if ($olddate->coursestarttime != $starttime || $olddate->courseendtime != $endtime) {
                        // If so, we update the record accordingly.
                        $olddate->coursestarttime = (int)$starttime;
                        $olddate->courseendtime = (int)$endtime;
                        $DB->update_record('booking_optiondates', $olddate);
                    }

                } else {
                    // An existing optiondate has been removed by the dynamic form, so delete it from DB.
                    $DB->delete_records('booking_optiondates', ['id' => (int) $olddate->id]);
                }
            }

            // It's important that this happens AFTER deleting the removed dates.
            foreach ($fromform->newoptiondates as $optiondatestring) {
                list($starttime, $endtime) = explode('-', $optiondatestring);

                $optiondate = new stdClass();
                $optiondate->bookingid = $this->bookingid;
                $optiondate->optionid = $this->optionid;
                $optiondate->eventid = 0; // TODO: We will implement this in a later release.
                $optiondate->coursestarttime = (int) $starttime;
                $optiondate->courseendtime = (int) $endtime;
                $optiondate->daystonotify = 0; // TODO: We will implement this in a later release..

                $DB->insert_record('booking_optiondates', $optiondate);
            }

            // After updating, we invalidate caches.
            cache_helper::purge_by_event('setbackoptionstable');
            cache_helper::invalidate_by_event('setbackoptionsettings', [$this->optionid]);

            booking_updatestartenddate($this->optionid);
        }
    }

    /**
     * Get date array for a specific weekday and time between two dates.
     *
     * @param int $semesterid
     * @param string $reoccuringdatestring
     * @return array
     */
    public static function get_optiondate_series(int $semesterid, string $reoccurringdatestring): array {

        $semester = new semester($semesterid);
        $dayinfo = self::prepare_day_info($reoccurringdatestring);

        // If an invalid day string was entered, we'll have an empty $dayinfo array.
        if (empty($dayinfo)) {
            return [];
        }

        $j = 1;
        sscanf($dayinfo['starttime'], "%d:%d", $hours, $minutes);
        $startseconds = ($hours * 60 * 60) + ($minutes * 60);
        sscanf($dayinfo['endtime'], "%d:%d", $hours, $minutes);
        $endseconds = $hours * 60 * 60 + $minutes * 60;
        for ($i = strtotime($dayinfo['day'], $semester->startdate); $i <= $semester->enddate; $i = strtotime('+1 week', $i)) {
            $date = new stdClass();
            $date->starttimestamp = $i + $startseconds;
            $date->endtimestamp = $i + $endseconds;

            // Check if the date is on a holiday and only add if it isn't.
            if (self::is_on_a_holiday($semester->identifier, $date)) {
                continue;
            }

            $date->date = date('Y-m-d', $i);
            $date->starttime = $dayinfo['starttime'];
            $date->endtime = $dayinfo['endtime'];
            $date->dateid = 'newdate-' . $j;
            $j++;

            $date->string = $date->date . " " .$date->starttime. "-" .$date->endtime;
            $datearray['dates'][] = $date;
        }
        return $datearray;
    }

    /**
     * Helper function to check if a certain date is during holidays.
     *
     * @param string $semesteridentifier the semester identifier string, e.g. "ws22"
     * @param stdClass $dateobj a date object having the attributes starttimestamp and endtimestamp (unix timestamps)
     * @return bool true if on a holiday, else false
     */
    private static function is_on_a_holiday(string $semesteridentifier, stdClass $dateobj): bool {
        global $DB;
        if ($holidays = $DB->get_records('booking_holidays', ['semesteridentifier' => $semesteridentifier])) {
            foreach ($holidays as $holiday) {
                // Add 23:59:59 (in seconds) to the end time.
                $holiday->enddate += 86399;
                if ($holiday->startdate <= $dateobj->starttimestamp && $dateobj->endtimestamp <= $holiday->enddate) {
                    // It's on a holiday.
                    return true;
                }
            }
            // It's not on a holiday.
            return false;
        }
    }

    /**
     * Prepare an array containing the weekday, start time and end time.
     * @param string $reoccurringdatestring
     * @return array
     */
    private static function prepare_day_info(string $reoccurringdatestring): array {
        $reoccurringdatestring = strtolower($reoccurringdatestring);
        $reoccurringdatestring = str_replace('-', ' ', $reoccurringdatestring);
        $reoccurringdatestring = str_replace(',', ' ', $reoccurringdatestring);
        $reoccurringdatestring = preg_replace("/\s+/", " ", $reoccurringdatestring);
        $strings = explode(' ',  $reoccurringdatestring);

        $daystring = $strings[0];

        $weekdays = self::get_localized_weekdays();

        // Initialize the output day string.
        $day = '';

        foreach ($weekdays as $key => $value) {
            // Make sure we have lower characters only.
            $currentweekday = strtolower($value);
            $currentweekday2char = substr($currentweekday, 0, 2);
            $currentweekday3char = substr($currentweekday, 0, 3);

            if ($daystring == $currentweekday2char ||
                $daystring == $currentweekday3char ||
                $daystring == $currentweekday) {

                $day = $key;
                break;
            }
        }

        // Invalid day identifier, so return empty array.
        if ($day === '') {
            return [];
        }

        $dayinfo['day'] = $day;
        $dayinfo['starttime'] = $strings[1];
        $dayinfo['endtime'] = $strings[2];

        return $dayinfo;
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
                $date->string = date('Y-m-d H:i', $record->coursestarttime) . '-' . date('H:i', $record->courseendtime);
                $datearray[] = $date;
            }

            return $datearray;
        } else {
            return [];
        }
    }

    /**
     * Create an array of localized weekdays.
     *
     * @return array
     */
    public static function get_localized_weekdays(): array {
        $weekdays = [];
        $weekdays['monday'] = get_string('monday', 'core_calendar');
        $weekdays['tuesday'] = get_string('tuesday', 'core_calendar');
        $weekdays['wednesday'] = get_string('wednesday', 'core_calendar');
        $weekdays['thursday'] = get_string('thursday', 'core_calendar');
        $weekdays['friday'] = get_string('friday', 'core_calendar');
        $weekdays['saturday'] = get_string('saturday', 'core_calendar');
        $weekdays['sunday'] = get_string('sunday', 'core_calendar');

        return $weekdays;
    }

    /**
     * Check if the entered reoccuring date string is in a valid format.
     *
     * @param string $reoccuringdatestring e.g. Monday, 10:00-11:00 or Sun, 12:00-13:00
     *
     * @return bool
     */
    public static function reoccurring_datestring_is_correct(string $reoccuringdatestring): bool {

        if (!preg_match('/^[a-zA-Z]+[,\s]+([0-1]?[0-9]|[2][0-3]):([0-5][0-9])\s*-\s*([0-1]?[0-9]|[2][0-3]):([0-5][0-9])$/',
            $reoccuringdatestring)) {
            return false;
        }

        $string = strtolower($reoccuringdatestring);
        $string = str_replace(',', ' ', $string);
        $string = preg_replace("/\s+/", " ", $string);
        $strings = explode(' ',  $string);
        $daystring = $strings[0]; // Lower case weekday part of the string, e.g. "mo", "mon" or "monday".

        $weekdays = self::get_localized_weekdays();
        $allowedstrings = [];

        foreach ($weekdays as $key => $value) {
            // Make sure we have lower characters only.
            $currentweekday = strtolower($value);
            $allowedstrings[] = $currentweekday;
            $allowedstrings[] = substr($currentweekday, 0, 2);
            $allowedstrings[] = substr($currentweekday, 0, 3);
        }

        if (!in_array($daystring, $allowedstrings)) {
            return false;
        }

        return true;
    }
}
