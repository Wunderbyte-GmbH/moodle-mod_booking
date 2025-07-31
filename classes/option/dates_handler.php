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
 * Control and manage booking dates.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option;
use Exception;
use html_writer;
use mod_booking\output\renderer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache_helper;
use lang_string;
use local_entities\entitiesrelation_handler;
use mod_booking\booking_option_settings;
use mod_booking\calendar;
use mod_booking\semester;
use mod_booking\teachers_handler;
use MoodleQuickForm;
use stdClass;
use mod_booking\singleton_service;

/**
 * Control and manage booking dates.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Thomas Winkler, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates_handler {
    /** @var int $optionid */
    public int $optionid = 0;

    /** @var int $bookingid */
    public int $bookingid = 0;

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
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        $semestersarray = semester::get_semesters_id_name_array();

        $mform->addElement(
            'autocomplete',
            'chooseperiod',
            get_string('chooseperiod', 'mod_booking'),
            $semestersarray,
            ['tags' => false]
        );
        // If a semesterid for the booking option was already set, use it.
        if (!empty($bookingoptionsettings->semesterid)) {
            $mform->setDefault('chooseperiod', $bookingoptionsettings->semesterid);
        } else if (!empty($bookingsettings->semesterid)) {
            // If not, use the semesterid from the booking instance.
            $mform->setDefault('chooseperiod', $bookingsettings->semesterid);
        }
        $mform->addHelpButton('chooseperiod', 'chooseperiod', 'mod_booking');

        // Turn off submit on enter (keycode: 13).
        // We will work with the submit button only (as it has some sophisticated JS listeners).
        $mform->addElement(
            'text',
            'reoccurringdatestring',
            get_string('reoccurringdatestring', 'booking'),
            ['onkeypress' => 'return event.keyCode != 13;']
        );
        $mform->setDefault('reoccurringdatestring', $bookingoptionsettings->dayofweektime);
        $mform->addHelpButton('reoccurringdatestring', 'reoccurringdatestring', 'mod_booking');
        $mform->setType('reoccurringdatestring', PARAM_TEXT);

        // Add a button to create specific single dates which are not part of the date series.
        $mform->addElement(
            'button',
            'customdatesbtn',
            get_string('customdatesbtn', 'mod_booking'),
            ['data-action' => 'opendateformmodal']
        );

        if ($loadexistingdates) {
            // Add already existing optiondates to form.
            /** @var renderer $output */
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
     * Delete all option dates associated with the booking option.
     *
     * @return void
     */
    public function delete_all_option_dates(): void {
        global $DB;

        if ($this->optionid && $this->bookingid) {
            // Get the currently saved optiondateids from DB.
            $dates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid]);

            // Now, let's remove every date from booking_optiondates.
            foreach ($dates as $date) {
                optiondate::delete($date->id);
            }
        }
    }

    /**
     * Transform each optiondate and save.
     *
     * @param stdClass $fromform form data
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
                    [$starttime, $endtime] = explode('-', $stillexistingdatestring);

                    // Check if start time or end time has changed.
                    if ($olddate->coursestarttime != $starttime || $olddate->courseendtime != $endtime) {
                        // If so, we update the record accordingly.
                        $olddate->coursestarttime = (int)$starttime;
                        $olddate->courseendtime = (int)$endtime;
                        $DB->update_record('booking_optiondates', $olddate);
                    }
                } else {
                    optiondate::delete($olddate->id);
                }
            }

            // It's important that this happens AFTER deleting the removed dates.
            foreach ($fromform->newoptiondates as $optiondatestring) {
                [$starttime, $endtime] = explode('-', $optiondatestring);

                // Now save the new optiondates.
                optiondate::save(
                    0,
                    $this->optionid,
                    (int) $starttime,
                    (int) $endtime,
                    // We can implement additional params in a later release.
                );
            }
        }
    }

    /**
     * Get date array for a specific weekday and time between two dates.
     *
     * @param int $semesterid
     * @param string $reoccurringdatestring
     * @return array
     */
    public static function get_optiondate_series(int $semesterid, string $reoccurringdatestring): array {

        // Skip this, if it's a blocked event.
        $reoccurringdatestring = strtolower($reoccurringdatestring);
        if (strpos($reoccurringdatestring, 'block') !== false) {
            return [];
        }

        $semester = new semester($semesterid);

        $reoccurringdatestrings = self::split_and_trim_reoccurringdatestring($reoccurringdatestring);

        foreach ($reoccurringdatestrings as $reoccurringdatestring) {
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
                if (self::is_on_a_holiday($date)) {
                    continue;
                }
                $date->date = date('Y-m-d', $i);
                $date->starttime = $dayinfo['starttime'];
                $date->endtime = $dayinfo['endtime'];
                $date->dateid = 'newdate-' . $j;
                $j++;

                $date->string = self::prettify_optiondates_start_end(
                    $date->starttimestamp,
                    $date->endtimestamp,
                    current_language()
                );
                $datearray['dates'][] = $date;
            }
        }

        return $datearray;
    }

    /**
     * Helper function to check if a certain date is during holidays.
     *
     * @param stdClass $dateobj a date object having the attributes starttimestamp and endtimestamp (unix timestamps)
     * @return bool true if on a holiday, else false
     */
    private static function is_on_a_holiday(stdClass $dateobj): bool {
        global $DB;
        if ($holidays = $DB->get_records('booking_holidays')) {
            foreach ($holidays as $holiday) {
                // Add 23:59:59 (in seconds) to the end time.
                $holiday->enddate += 86399;
                if ($holiday->startdate <= $dateobj->starttimestamp && $dateobj->endtimestamp <= $holiday->enddate) {
                    // It's on a holiday.
                    return true;
                }
            }
        }
        // It's not on a holiday.
        return false;
    }

    /**
     * Helper function to split a reoccurring date string into an array.
     * @param string $reoccurringdatestring e.g. "Mo, 10:00-11:00 & Di, 12:00-13:00"
     * @return array array of separate strings
     */
    public static function split_and_trim_reoccurringdatestring(string $reoccurringdatestring = ''): array {
        $pattern = '/\r?\n/';  // Regex pattern for separators.
        if (preg_match($pattern, $reoccurringdatestring)) {
            // Split by the pattern and trim each part.
            $parts = preg_split($pattern, $reoccurringdatestring);
            return array_map('trim', $parts);
        }
        if (empty($reoccurringdatestring)) {
            // If the string is empty, return an empty array.
            return [];
        }
        // If no separator is found, return the trimmed input.
        return [trim($reoccurringdatestring)];
    }

    /**
     * Helper function to render a list of dayofweektimestrings.
     * @param string $reoccurringdatestring full string containing one or multiple dayofweektime strings
     * @param string $separator optional separator, default is ', '
     * @return string rendered string
     */
    public static function render_dayofweektime_strings(string $reoccurringdatestring = '', string $separator = ', '): string {
        if (empty($reoccurringdatestring)) {
            return '';
        }
        $reoccurringdatestrings = self::split_and_trim_reoccurringdatestring($reoccurringdatestring);
        if (!empty($reoccurringdatestrings)) {
            $strings = [];
            $localweekdays = self::get_localized_weekdays(current_language());
            foreach ($reoccurringdatestrings as $reoccurringdatestring) {
                $dayinfo = self::prepare_day_info($reoccurringdatestring);
                if (isset($dayinfo['day']) && $dayinfo['starttime'] && $dayinfo['endtime']) {
                    $strings[] = $localweekdays[$dayinfo['day']] . ', ' . $dayinfo['starttime'] . ' - ' . $dayinfo['endtime'];
                } else if (!empty($reoccurringdatestring)) {
                    $strings[] = $reoccurringdatestring;
                } else {
                    $strings[] = get_string('datenotset', 'mod_booking');
                }
            }
            return implode($separator, $strings);
        }
        return '';
    }

    /**
     * Prepare an array containing the weekday, start time and end time.
     * @param string $reoccurringdatestring
     * @return array
     */
    public static function prepare_day_info(string $reoccurringdatestring): array {
        // Important: If we have multiple day of weektime strings, we have to handle this before.
        // In this case, this function needs to be called for each string separately!
        // If it gets called with a string containing multiple days, it will only handle the first one.
        $reoccurringdatestrings = self::split_and_trim_reoccurringdatestring($reoccurringdatestring);
        $reoccurringdatestring = $reoccurringdatestrings[0] ?? '';

        $reoccurringdatestring = strtolower($reoccurringdatestring);
        $reoccurringdatestring = str_replace('-', ' ', $reoccurringdatestring);
        $reoccurringdatestring = str_replace(',', ' ', $reoccurringdatestring);
        $reoccurringdatestring = preg_replace("/\s+/", " ", $reoccurringdatestring);
        $strings = explode(' ', $reoccurringdatestring);

        $daystring = $strings[0];

        // Add support for German daystrings even if platform is set to English.
        $onlygermandaystrings = [
            'montag', // Note: 'mo' and 'mon' could be English too!
            'di', 'die', 'dienstag',
            'mi', 'mit', 'mittwoch',
            'do', 'don', 'donnerstag',
            'fre', 'freitag', // Note: 'fr' could be English too!
            'sam', 'samstag', // Note: 'sa' could be English too!
            'so', 'son', 'sonntag',
        ];

        if (current_language() != 'de' && in_array($daystring, $onlygermandaystrings)) {
            $weekdays = self::get_localized_weekdays('de');
        } else {
            $weekdays = self::get_localized_weekdays();
        }

        // Initialize the output day string.
        $day = '';

        foreach ($weekdays as $key => $value) {
            // Make sure we have lower characters only.
            $currentweekday = strtolower($value);
            $currentweekday2char = substr($currentweekday, 0, 2);
            $currentweekday3char = substr($currentweekday, 0, 3);

            if (
                $daystring == $currentweekday2char ||
                $daystring == $currentweekday3char ||
                $daystring == $currentweekday
            ) {
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

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (count($settings->sessions) > 0) {
            foreach ($settings->sessions as $session) {
                $date = new stdClass();
                $date->dateid = 'dateid-' . $session->id;
                $date->starttimestamp = $session->coursestarttime;
                $date->endtimestamp = $session->courseendtime;

                // If dates are on the same day, then show date only once.
                $date->string = self::prettify_optiondates_start_end(
                    $date->starttimestamp,
                    $date->endtimestamp,
                    current_language()
                );

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
    public static function prettify_optiondates_start_end(
        int $starttimestamp,
        int $endtimestamp,
        string $lang = 'en',
        bool $showweekdays = true
    ): string {

        $date = self::prettify_datetime($starttimestamp, $endtimestamp, $lang, $showweekdays);

        $prettifiedstring = $date->datestring;

         return $prettifiedstring;
    }

    /**
     * Create an array of localized weekdays.
     * @param ?string $lang optional language identifier, e.g. "de", "en"0
     * @return array
     */
    public static function get_localized_weekdays(?string $lang = null): array {
        $weekdays = [];
        if (empty($lang)) {
            $weekdays['monday'] = get_string('monday', 'mod_booking');
            $weekdays['tuesday'] = get_string('tuesday', 'mod_booking');
            $weekdays['wednesday'] = get_string('wednesday', 'mod_booking');
            $weekdays['thursday'] = get_string('thursday', 'mod_booking');
            $weekdays['friday'] = get_string('friday', 'mod_booking');
            $weekdays['saturday'] = get_string('saturday', 'mod_booking');
            $weekdays['sunday'] = get_string('sunday', 'mod_booking');
        } else {
            $weekdays['monday'] = new lang_string('monday', 'mod_booking', null, $lang);
            $weekdays['tuesday'] = new lang_string('tuesday', 'mod_booking', null, $lang);
            $weekdays['wednesday'] = new lang_string('wednesday', 'mod_booking', null, $lang);
            $weekdays['thursday'] = new lang_string('thursday', 'mod_booking', null, $lang);
            $weekdays['friday'] = new lang_string('friday', 'mod_booking', null, $lang);
            $weekdays['saturday'] = new lang_string('saturday', 'mod_booking', null, $lang);
            $weekdays['sunday'] = new lang_string('sunday', 'mod_booking', null, $lang);
        }
        return $weekdays;
    }

    /**
     * Check if the entered reoccuring date string is in a valid format.
     *
     * @param string $reoccurringdatestring e.g. Monday, 10:00-11:00 or Sun, 12:00-13:00
     *
     * @return bool
     */
    public static function reoccurring_datestring_is_correct(string $reoccurringdatestring): bool {

        $string = strtolower($reoccurringdatestring);
        $string = trim($string);
        if (strpos($string, 'block') !== false) {
            return true;
        }

        if (
            !preg_match(
                '/^[a-zA-Z]+[,\s]+([0-1]?[0-9]|[2][0-3]):([0-5][0-9])\s*-\s*([0-1]?[0-9]|[2][0-3]):([0-5][0-9])$/',
                $reoccurringdatestring
            )
        ) {
            return false;
        }

        $string = str_replace(',', ' ', $string);
        $string = preg_replace("/\s+/", " ", $string);
        $strings = explode(' ', $string);
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


    /**
     * Delete all the optiondates and create them anew.
     *
     * @param int $cmid
     * @param int $semesterid
     * @return void
     */
    public static function change_semester($cmid, $semesterid) {

        global $DB;
        // First we delete all optiondates on this instance.

        // So we can be sure that we use the right dates.
        cache_helper::purge_by_event('setbacksemesters');

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        if (!$bookingid = $booking->id ?? false) {
            return;
        }

        // Lastly, we also need to change the semester for the booking instance itself!
        if (!$bookinginstancerecord = $DB->get_record('booking', ['id' => $bookingid])) {
            return;
        }
        $bookinginstancerecord->semesterid = $semesterid;
        $DB->update_record('booking', $bookinginstancerecord);

        // When updating an instance, we need to invalidate the cache for booking instances.
        cache_helper::invalidate_by_event('setbackbookinginstances', [$cmid]);
        cache_helper::purge_by_event('setbackoptionsettings');

        // Now we run through all the bookingoptions.
        $options = $DB->get_records('booking_options', ["bookingid" => $bookingid]);

        if (empty($options)) {
            return;
        }

        foreach ($options as $option) {
            try {
                $bo = singleton_service::get_instance_of_booking_option($cmid, $option->id);

                if (!empty($bo->settings->dayofweektime)) {
                    $bo->recreate_date_series($semesterid);

                    mtrace('Recreated dates for optionid ' . $option->id);
                }
            } catch (Exception $e) {
                mtrace('Failed to recreated dates for optionid ' . $option->id, json_encode($e));
            }
        }

        // Also purge caches for options table and booking_option_settings.
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackoptionsettings');
    }

    /**
     * Static helper function for mustache templates to return array with optiondates only.
     * It will return only one item containing course start and endtime if no optiondates exist.
     *
     * @param int $optionid
     *
     * @return array array of optiondates objects
     */
    public static function return_array_of_sessions_simple(int $optionid): array {

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $sessions = self::return_dates_with_strings($settings);

        $returnarray = [];

        foreach ($sessions as $session) {
            $returnarray[] = ['datestring' => $session->datestring];
        }

        // If we don't have any sessions, we render the date of the option itself.
        if (
            empty($sessions) && !empty($settings->coursestarttime) && !empty($settings->courseendtime)
            && $settings->coursestarttime != "0" && $settings->courseendtime != "0"
        ) {
            $returnarray[] = [
                    'datestring' => self::prettify_optiondates_start_end(
                        $settings->coursestarttime,
                        $settings->courseendtime,
                        current_language()
                    ),
            ];
        }

        return $returnarray;
    }

    /**
     * Static helper function to return an array of simple date strings.
     * It will return only one item containing course start and endtime if no optiondates exist.
     *
     * @param int $optionid
     * @return array array of optiondates strings
     * @throws \dml_exception
     */
    public static function return_array_of_sessions_datestrings(int $optionid) {

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $sessions = self::return_dates_with_strings($settings);

        $returnarray = [];

        foreach ($sessions as $session) {
            $returnarray[] = $session->datestring;
        }

        // If we don't have any sessions, we render the date of the option itself.
        if (
            empty($sessions) && !empty($settings->coursestarttime) && !empty($settings->courseendtime)
            && $settings->coursestarttime != "0" && $settings->courseendtime != "0"
        ) {
            $returnarray[] = self::prettify_optiondates_start_end(
                $settings->coursestarttime,
                $settings->courseendtime,
                current_language()
            );
        }

        return $returnarray;
    }

    /**
     * Helper function to calculate and render educational units.
     *
     * @param string $dayofweektime e.g. "Mon, 16:00 - 17:30"
     * @return string the rendered educational units (localized)
     */
    public static function calculate_and_render_educational_units(string $dayofweektime): string {

        // Get unit length from config (should be something like 45, 50 or 60 minutes).
        if (!$unitlength = (int) get_config('booking', 'educationalunitinminutes')) {
            $unitlength = 60; // If it's not set, we use an hour as default.
        }

        // For German use "," as comma and " " as thousands separator.
        if (current_language() == "de") {
            $decimalseparator = ",";
            $thousandsseparator = " ";
        } else {
            // Default separators.
            $decimalseparator = ".";
            $thousandsseparator = ",";
        }

        $dayinfo = self::prepare_day_info($dayofweektime);

        if (empty($dayinfo['endtime']) || empty($dayinfo['starttime'])) {
            return '';
        }

        $minutes = (strtotime('today ' . $dayinfo['endtime']) - strtotime('today ' . $dayinfo['starttime'])) / 60;
        $unitstring = number_format($minutes / $unitlength, 1, $decimalseparator, $thousandsseparator);

        if (!empty($unitstring)) {
            return $unitstring;
        } else {
            return '';
        }
    }

    /**
     * This function deals with a hack which allowed us to add optiondates to a form.
     *
     * @param object $fromform
     * @return void
     */
    public static function add_values_from_post_to_form(object &$fromform) {
        // Get all new dynamically loaded dates from $_POST and save them.
        $newoptiondates = [];
        // Also get the remaining existing dates.
        $stillexistingdates = [];

        foreach ($_POST as $key => $value) {
            // New option dates (created with date series function).
            if (substr($key, 0, 18) === 'coursetime-newdate') {
                $newoptiondates[] = $value;
            }

            // Also add custom dates to the new option dates.
            if (substr($key, 0, 21) === 'coursetime-customdate') {
                $newoptiondates[] = $value;
            }

            // Dates loaded from DB which have not been removed.
            if (substr($key, 0, 17) === 'coursetime-dateid') {
                $currentdateid = (int) explode('-', $key)[2];
                $stillexistingdates[$currentdateid] = $value;
            }
        }
        // Store the arrays in $fromform so we can use them later in booking_option::update.
        $fromform->newoptiondates = $newoptiondates;
        $fromform->stillexistingdates = $stillexistingdates;

        // Also, get semesterid and dayofweektime string from the dynamic form and load it into $fromform.
        if (isset($_POST['semesterid'])) {
            $fromform->semesterid = $_POST['semesterid'];
        }
        if (isset($_POST['dayofweektime'])) {
            $fromform->dayofweektime = $_POST['dayofweektime'];
        }
    }

    /**
     * This function returns an array of stdClasses for an option.
     * The objects hold the keys startdate, enddate (as timestamps).
     * Plust startdatestring, enddatestring as readable and localized strings.
     * If there are no optiondates (sessions) we return start & enddate of the option.
     *
     * @param booking_option_settings $settings
     * @param string $lang
     * @param bool $showweekdays
     * @param bool $ashtml
     *
     * @return array
     */
    public static function return_dates_with_strings(
        booking_option_settings $settings,
        string $lang = '',
        bool $showweekdays = false,
        bool $ashtml = false
    ): array {

        $sessions = [];

        if (!empty($settings->sessions)) {
            // If there only is one session, it could be that it's the course start and end time.
            // So check, if it's expanding over more than one day and format accordingly.

            $formattedsession = new stdClass();

            foreach ($settings->sessions as $session) {
                $data = self::prettify_datetime(
                    $session->coursestarttime,
                    $session->courseendtime,
                    $lang,
                    $showweekdays,
                    $ashtml
                );
                $data->id = $session->id;
                $sessions[] = $data;
            }
        } else if (
            isset($settings->coursestarttime) && isset($settings->courseendtime)
            && $settings->coursestarttime != "0" && $settings->courseendtime != "0"
        ) {
            // If we don't have extra sessions, we take the normal coursestart & endtime.

            $data = self::prettify_datetime(
                $settings->coursestarttime,
                $settings->courseendtime,
                $lang,
                $showweekdays
            );
            $data->id = 0;
            $sessions[] = $data;
        }

        return $sessions;
    }

    /**
     * Prettify datetime function
     *
     * @param int $starttime
     * @param int $endtime
     * @param string $lang
     * @param bool $showweekdays
     * @param bool $ashtml
     *
     * @return stdClass
     */
    public static function prettify_datetime(
        int $starttime,
        int $endtime = 0,
        $lang = '',
        $showweekdays = false,
        bool $ashtml = false
    ) {

        if (empty($lang)) {
            $lang = current_language();
        }

        $date = new stdClass();

        // Time only.
        $strftimetime = new lang_string('strftimetime', 'langconfig', null, $lang); // 10:30.

        // Dates only.
        $strftimedate = new lang_string('strftimedate', 'langconfig', null, $lang); // 3. February 2023.
        $strftimedaydate = new lang_string('strftimedaydate', 'langconfig', null, $lang); // Friday, 3. February 2023".

        // Times & Dates.
        $strftimedatetime = new lang_string('strftimedatetime', 'langconfig', null, $lang); // 3. February 2023, 11:45.
        $strftimedaydatetime = new lang_string('strftimedaydatetime', 'langconfig', null, $lang);
        // Friday, 3. February 2023, 11:45.

        $date->starttimestamp = $starttime; // Unix timestamps.
        $date->starttime = userdate($starttime, $strftimetime); // 10:30.

        if (!empty($endtime)) {
            $date->endtimestamp = $endtime; // Unix timestamps.
            $date->endtime = userdate($endtime, $strftimetime); // 10:30.
        }

        if ($ashtml) {
            $date->startdate = userdate($starttime, $strftimedaydate); // Friday, 3. February 2023.
            $date->startdatetime = userdate($starttime, $strftimedaydatetime); // Friday, 3. February 2023, 11:45.
            $datespan = html_writer::span($date->startdate, 'date');
            $timespan = html_writer::span($date->starttime, 'time');

            if (!empty($endtime)) {
                $date->enddatetime = userdate($endtime, $strftimedaydatetime); // Friday, 3. February 2023, 12:45.
                $date->enddate = userdate($endtime, $strftimedaydate); // Friday, 3. February 2023.
                $timespan = html_writer::span($date->starttime . ' - ' . $date->endtime . get_string('h', 'mod_booking'), 'time');
                if ($date->startdate !== $date->enddate) {
                    $datespan = html_writer::span($date->startdate . ' - ' . $date->enddate, 'date');
                }
            }

            $date->htmlstring = $datespan . $timespan;
        }
        if ($showweekdays) {
            $date->startdate = userdate($starttime, $strftimedaydate); // Friday, 3. February 2023.
            $date->startdatetime = userdate($starttime, $strftimedaydatetime) . get_string('h', 'mod_booking');
            // Friday, 3. February 2023, 11:45.
            $date->datestring = $date->startdatetime;

            if (!empty($endtime)) {
                $date->enddatetime = userdate($endtime, $strftimedaydatetime);
                // Friday, 3. February 2023, 12:45.
                $date->enddate = userdate($endtime, $strftimedaydate); // Friday, 3. February 2023.
                $date->datestring .= " - ";
                $date->datestring .= $date->startdate != $date->enddate ?
                    $date->enddatetime . get_string('h', 'mod_booking') :
                    // Friday, 3. February 2023, 11:45 - Saturday, 4. February 2023, 12:45.
                    $date->endtime . get_string('h', 'mod_booking');
                    // Friday, 3. February 2023, 11:45 - 12:45.
            }
        } else {
            // Without weekdays.
            $date->startdate = userdate($starttime, $strftimedate); // 3. February 2023.
            $date->startdatetime = userdate($starttime, $strftimedatetime); // 3. February 2023, 11:45.
            $date->datestring = $date->startdatetime;

            if (!empty($endtime)) {
                $date->enddatetime = userdate($endtime, $strftimedatetime);
                $date->enddate = userdate($endtime, $strftimedate); // 3. February 2023.
                $date->enddatetime = userdate($endtime, $strftimedatetime);
                // Friday, 3. February 2023, 12:45.
                $date->datestring .= " - ";
                $date->datestring .= $date->startdate != $date->enddate ?
                    $date->enddatetime . get_string('h', 'mod_booking') : // 3. February 2023, 11:45 - 4. February 2023, 12:45.
                    $date->endtime . get_string('h', 'mod_booking'); // 3. February 2023, 11:45 - 12:45.
            }
        }

        return $date;
    }


    /**
     * This function creates timessots between two timestamps depending on the duration.
     * All the time is filled with entire slots. If the remaining time is not enough for a slot, it's skipped.
     * The slots will be created with the prettify_datetime function and contain the localized strings.
     *
     * @param int $starttime unix timestamp
     * @param int $endtime unix timestamp
     * @param int $duration in seconds
     * @return array
     */
    public static function create_slots($starttime, $endtime, $duration) {

        $slots = [];
        $slotendtime = $starttime; // This is just to jump into the while loop.

        while ($slotendtime < $endtime) {
            $slotstarttime = $starttime;
            $slotendtime = strtotime("+ $duration minutes ", $starttime);
            $starttime = $slotendtime; // New starttime previous slotendtime.
            $slots[] = self::prettify_datetime($slotstarttime, $slotendtime);
        }

        return $slots;
    }
}
