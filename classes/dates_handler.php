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
use context_module;
use lang_string;
use local_entities\entitiesrelation_handler;
use mod_booking\semester;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Control and manage booking dates.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Thomas Winkler, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates_handler {

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
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        $semestersarray = semester::get_semesters_id_name_array();

        $mform->addElement('autocomplete', 'chooseperiod', get_string('chooseperiod', 'mod_booking'),
            $semestersarray, ['tags' => false]);
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
        $mform->addElement('text', 'reoccurringdatestring', get_string('reoccurringdatestring', 'booking'),
            ['onkeypress' => 'return event.keyCode != 13;']);
        $mform->setDefault('reoccurringdatestring', $bookingoptionsettings->dayofweektime);
        $mform->addHelpButton('reoccurringdatestring', 'reoccurringdatestring', 'mod_booking');
        $mform->setType('reoccurringdatestring', PARAM_TEXT);

        // Add a button to create specific single dates which are not part of the date series.
        $mform->addElement('button', 'customdatesbtn', get_string('customdatesbtn', 'mod_booking'),
            ['data-action' => 'opendateformmodal']);

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
     * If no dates are set in form delete olddates if they exist
     *
     * @param stdClass $fromform
     * @return void
     */
    public function delete_option_dates(stdClass $fromform): void {
        global $DB;

        if ($this->optionid && $this->bookingid) {

            // Get the currently saved optiondateids from DB.
            $olddates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid]);

            // Now, let's remove every date from bookink_optiondates
            foreach ($olddates as $olddate) {
                $olddateid = (int) $olddate->id;

                // An existing optiondate has been removed by the dynamic form, so delete it from DB.
                $DB->delete_records('booking_optiondates', ['id' => $olddateid]);

                // We also need to delete the associated records in booking_optiondates_teachers.
                self::remove_teachers_from_deleted_optiondate($olddateid);

                // We also need to delete associated custom fields.
                self::optiondate_deletecustomfields($olddateid);

                // We also need to delete any associated entities.
                // If there is an associated entity, delete it too.
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
                    $erhandler->delete_relation($olddateid);
                }
            }
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
                    $olddateid = (int) $olddate->id;

                    // An existing optiondate has been removed by the dynamic form, so delete it from DB.
                    $DB->delete_records('booking_optiondates', ['id' => $olddateid]);

                    // We also need to delete the associated records in booking_optiondates_teachers.
                    self::remove_teachers_from_deleted_optiondate($olddateid);

                    // We also need to delete associated custom fields.
                    self::optiondate_deletecustomfields($olddateid);

                    // We also need to delete any associated entities.
                    // If there is an associated entity, delete it too.
                    if (class_exists('local_entities\entitiesrelation_handler')) {
                        $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
                        $erhandler->delete_relation($olddateid);
                    }
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

                $optiondateid = $DB->insert_record('booking_optiondates', $optiondate);

                // Add teachers of the booking option to newly created optiondate.
                self::subscribe_existing_teachers_to_new_optiondate($optiondateid);

                // If a new optiondate is inserted, we add the entity of the parent option as default.
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    $erhandleroption = new entitiesrelation_handler('mod_booking', 'option');
                    $entityid = $erhandleroption->get_entityid_by_instanceid($this->optionid);
                    $erhandleroptiondate = new entitiesrelation_handler('mod_booking', 'optiondate');
                    $erhandleroptiondate->save_entity_relation($optiondateid, $entityid);
                }
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

        // Skip this, if it's a blocked event.
        $reoccurringdatestring = strtolower($reoccurringdatestring);
        if (strpos($reoccurringdatestring, 'block') !== false) {
            return [];
        }

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
            if (self::is_on_a_holiday($date)) {
                continue;
            }

            $date->date = date('Y-m-d', $i);
            $date->starttime = $dayinfo['starttime'];
            $date->endtime = $dayinfo['endtime'];
            $date->dateid = 'newdate-' . $j;
            $j++;

            $date->string = self::prettify_optiondates_start_end($date->starttimestamp, $date->endtimestamp, current_language());
            $datearray['dates'][] = $date;
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
     * Prepare an array containing the weekday, start time and end time.
     * @param string $reoccurringdatestring
     * @return array
     */
    public static function prepare_day_info(string $reoccurringdatestring): array {
        $reoccurringdatestring = strtolower($reoccurringdatestring);
        $reoccurringdatestring = str_replace('-', ' ', $reoccurringdatestring);
        $reoccurringdatestring = str_replace(',', ' ', $reoccurringdatestring);
        $reoccurringdatestring = preg_replace("/\s+/", " ", $reoccurringdatestring);
        $strings = explode(' ',  $reoccurringdatestring);

        $daystring = $strings[0];

        // Add support for German daystrings even if platform is set to English.
        $onlygermandaystrings = [
            'montag', // Note: 'mo' and 'mon' could be English too!
            'di', 'die', 'dienstag',
            'mi', 'mit', 'mittwoch',
            'do', 'don', 'donnerstag',
            'fre', 'freitag', // Note: 'fr' could be English too!
            'sam', 'samstag', // Note: 'sa' could be English too!
            'so', 'son', 'sonntag'];

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

        $records = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (count($records->settings) > 0) {

            foreach ($records->settings as $record) {
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

        $date = self::prettify_datetime($starttimestamp, $endtimestamp, $lang, $showweekdays);

        $prettifiedstring = $date->datestring;

         return $prettifiedstring;
    }

    /**
     * Create an array of localized weekdays.
     * @param string $lang optional language identifier, e.g. "de", "en"0
     * @return array
     */
    public static function get_localized_weekdays(string $lang = null): array {
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
     * @param string $reoccuringdatestring e.g. Monday, 10:00-11:00 or Sun, 12:00-13:00
     *
     * @return bool
     */
    public static function reoccurring_datestring_is_correct(string $reoccurringdatestring): bool {

        $string = strtolower($reoccurringdatestring);
        $string = trim($string);
        if (strpos($string, 'block') !== false) {
            return true;
        }

        if (!preg_match('/^[a-zA-Z]+[,\s]+([0-1]?[0-9]|[2][0-3]):([0-5][0-9])\s*-\s*([0-1]?[0-9]|[2][0-3]):([0-5][0-9])$/',
            $reoccurringdatestring)) {
            return false;
        }

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

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $bookingid = $booking->id;

        $DB->delete_records('booking_optiondates', ['bookingid' => $bookingid]);
        // If optiondates are deleted we also have to delete the associated entries in booking_optiondates_teachers.
        self::delete_booking_optiondates_teachers_by_bookingid($bookingid);

        // Now we run through all the bookingoptions.
        $options = $DB->get_records('booking_options', ["bookingid" => $bookingid]);

        foreach ($options as $optionvalues) {

            // Set the id of the option correctly, so that update will work.
            $optionvalues->optionid = $optionvalues->id;

            // Save the semesterid within every option.
            $optionvalues->semesterid = $semesterid;

            if (empty($optionvalues->dayofweektime)) {
                continue;
            }

            $msdates = self::get_optiondate_series($semesterid, $optionvalues->dayofweektime);
            $counter = 1;
            if (isset($msdates['dates'])) {
                foreach ($msdates['dates'] as $msdate) {
                    $startkey = 'ms' . $counter . 'starttime';
                    $endkey = 'ms' . $counter . 'endtime';
                    $optionvalues->$startkey = $msdate->starttimestamp;
                    $optionvalues->$endkey = $msdate->endtimestamp;
                    $counter++;
                }
            }
            $context = context_module::instance($cmid);
            booking_update_options($optionvalues, $context);
        }

        // Lastly, we also need to change the semester for the booking instance itself!
        if ($bookinginstancerecord = $DB->get_record('booking', ['id' => $bookingid])) {
            $bookinginstancerecord->semesterid = $semesterid;
            $DB->update_record('booking', $bookinginstancerecord);
        }

        // When updating an instance, we need to invalidate the cache for booking instances.
        cache_helper::invalidate_by_event('setbackbookinginstances', [$cmid]);
        // Also purge caches for options table and booking_option_settings.
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackoptionsettings');
    }

    /**
     * Helper function to delete custom fields belonging to an option date.
     * @param int $optiondateid id of the option date for which all custom fields will be deleted.
     */
    public static function optiondate_deletecustomfields($optiondateid) {
        global $DB;
        // Delete all custom fields which belong to this optiondate.
        $DB->delete_records("booking_customfields", array('optiondateid' => $optiondateid));
    }

    /**
     * Helper function to add a new teacher to every (currently existing)
     * optiondate of an option.
     * @param int $optionid the booking option id
     * @param int $userid the user id of the teacher
     */
    public static function subscribe_teacher_to_all_optiondates(int $optionid, int $userid) {
        global $DB;

        if (empty($optionid) || empty ($userid)) {
            throw new moodle_exception('Could not connect teacher to optiondates because of missing userid or optionid.');
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                $newentry = new stdClass;
                $newentry->optiondateid = $existingoptiondate->id;
                $newentry->userid = $userid;
                // 2. Insert the teacher into booking_optiondates_teachers for every optiondate.
                $DB->insert_record('booking_optiondates_teachers', $newentry);
            }
        }
    }

    /**
     * Helper function to add the option's teacher(s) to a newly created optiondate.
     * @param int $optiondateid the id of the newly created optiondate
     */
    public static function subscribe_existing_teachers_to_new_optiondate(int $optiondateid) {
        global $DB;

        if (empty($optiondateid)) {
            throw new moodle_exception(
                'Could not subscribe existing teacher(s) to the new optiondate because of missing optiondateid.'
            );
        }

        if ($optiondate = $DB->get_record('booking_optiondates', ['id' => $optiondateid])) {
            // Get all currently set teachers of the option.
            $teachers = $DB->get_records('booking_teachers', ['optionid' => $optiondate->optionid]);
            if (!empty($teachers)) {
                foreach ($teachers as $teacher) {
                    $newentry = new stdClass;
                    $newentry->optiondateid = $optiondate->id;
                    $newentry->userid = $teacher->userid;
                    // Insert the newly created optiondate with each teacher.
                    $DB->insert_record('booking_optiondates_teachers', $newentry);
                }
            }
        }
    }

    /**
     * Helper function to remove a teacher from every (currently existing)
     * optiondate of an option.
     * @param int $optionid the booking option id
     * @param int $userid the user id of the teacher
     */
    public static function remove_teacher_from_all_optiondates(int $optionid, int $userid) {
        global $DB;

        if (empty($optionid) || empty ($userid)) {
            throw new moodle_exception('Could not remove teacher from optiondates because of missing userid or optionid.');
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                // 2. Delete the teacher from every optiondate.
                $DB->delete_records('booking_optiondates_teachers', [
                    'optiondateid' => $existingoptiondate->id,
                    'userid' => $userid
                ]);
            }
        }
    }

    /**
     * Helper function to remove the option's teacher(s) from a deleted optiondate.
     * @param int $optiondateid the id of the deleted optiondate
     */
    public static function remove_teachers_from_deleted_optiondate(int $optiondateid) {
        global $DB;

        if (empty($optiondateid)) {
            throw new moodle_exception(
                'Could not delete teacher(s) from the deleted optiondate because of missing optiondateid.'
            );
        }

        // Delete all entries in booking_optiondates_teachers associated with the optiondate.
        $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $optiondateid]);
    }

    /**
     * Helper function to remove all entries in booking_optiondates_teachers
     * for a specific booking instance (by bookingid).
     * @param int $bookingid the id of the booking instance
     * @param int $userid (optional) teacher id - if set only entries for this teacher will be deleted
     */
    public static function delete_booking_optiondates_teachers_by_bookingid(int $bookingid, int $userid = null) {
        global $DB;

        if (empty($bookingid)) {
            throw new moodle_exception('Could not clear entries from booking_optiondates_teachers because of missing booking id.');
        }

        // Get all currently existing optiondates of the booking instance.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['bookingid' => $bookingid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                if (empty($userid)) {
                    $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $existingoptiondate->id]);
                } else {
                    $DB->delete_records('booking_optiondates_teachers', [
                        'optiondateid' => $existingoptiondate->id,
                        'userid' => $userid
                    ]);
                }
            }
        }
    }

    /**
     * Helper function to remove all entries in booking_optiondates_teachers
     * for a specific booking option (by optionid).
     * @param int $optionid the id of the booking option
     */
    public static function delete_booking_optiondates_teachers_by_optionid(int $optionid) {
        global $DB;

        if (empty($optionid)) {
            throw new moodle_exception('Could not clear entries from booking_optiondates_teachers because of missing option id.');
        }

        // Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $existingoptiondate->id]);
            }
        }
    }

    /**
     * Static helper function for mustache templates to return array with optiondates only.
     * It will return only one item containing course start and endtime if no optiondates exist.
     *
     * @param int $optionid
     * @return array array of optiondates objects
     * @throws \dml_exception
     */
    public static function return_array_of_sessions_simple(int $optionid) {

        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $sessions = self::return_dates_with_strings($settings);

        $returnarray = [];

        foreach ($sessions as $session) {
            $returnarray[] = ['datestring' => $session->datestring];
        }

        if (count($sessions) > 0) {
            foreach ($sessions as $session) {

                $returnsession = [];

                // We show this only if timevalues are not 0.
                // if ($session->coursestarttime != 0 && $session->courseendtime != 0) {
                    /* Important: Last param needs to be false, as the weekdays conversion can cause
                    problems ("Allowed memory size exhausted...")if too many options are loaded. */

                // }
                if ($returnsession) {
                    $returnitem[] = $returnsession;
                }
            }
        } else {
            $returnitem[] = [
                    'datestring' => self::prettify_optiondates_start_end(
                            $settings->coursestarttime,
                            $settings->courseendtime,
                            current_language())
            ];
        }

        return $returnitem;
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
        $units = number_format($minutes / $unitlength, 1, $decimalseparator, $thousandsseparator);
        $unitstring = get_string('units', 'mod_booking') . ": $units";

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
        // Store the arrays in $fromform so we can use them later in booking_update_options.
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
     * @param boolean $showweekdays
     * @return array
     */
    public static function return_dates_with_strings(booking_option_settings $settings, string $lang = '', bool $showweekdays = false) {

        $sessions = [];

        if (!empty($settings->sessions)) {
            // If there only is one session, it could be that it's the course start and end time.
            // So check, if it's expanding over more than one day and format accordingly.

            $formattedsession = new stdClass;

            foreach ($settings->sessions as $session) {

                $data = self::prettify_datetime($session->coursestarttime,
                    $session->courseendtime,
                    $lang,
                    $showweekdays);
                $data->id = $session->id;
                $sessions[] = $data;
            }
        } else if (isset($settings->coursestarttime) && isset($settings->courseendtime)) {
            // If we don't have extra sessions, we take the normal coursestart & endtime.

            $data = self::prettify_datetime($settings->coursestarttime,
                    $settings->courseendtime,
                    $lang,
                    $showweekdays);
            $data->id = 0;
            $sessions[] = $data;
        }

        return $sessions;

    }

    /**
     * Undocumented function
     *
     * @param integer $starttime
     * @param integer $endtime
     * @param string $lang
     * @param bool $showweekdays
     * @return stdClass
     */
    public static function prettify_datetime(int $starttime, int $endtime = 0, $lang = '', $showweekdays = false) {

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
        $strftimedaydatetime = new lang_string('strftimedaydatetime', 'langconfig', null, $lang); // Friday, 3. February 2023, 11:45.

        $date->starttimestamp = $starttime; // Unix timestamps.
        $date->starttime = userdate($starttime, $strftimetime); // 10:30.

        if (!empty($endtime)) {
            $date->endtimestamp = $endtime; // Unix timestamps.
            $date->endtime = userdate($endtime, $strftimetime); // 10:30.
        }

        if ($showweekdays) {
            $date->startdate = userdate($endtime, $strftimedaydate); // Friday, 3. February 2023.
            $date->startdatetime = userdate($starttime, $strftimedaydatetime); // Friday, 3. February 2023, 11:45.
            $date->datestring = $date->startdatetime;

            if (!empty($endtime)) {
                $date->enddatetime = userdate($starttime, $strftimedaydatetime); // Friday, 3. February 2023, 12:45.
                $date->enddate = userdate($endtime, $strftimedaydate); // Friday, 3. February 2023.
                $date->datestring .= " - ";
                $date->datestring .= $date->startdate != $date->enddate ?
                    $date->enddatetime : // Friday, 3. February 2023, 11:45 - Saturday, 4. February 2023, 12:45.
                    $date->endtime; // Friday, 3. February 2023, 11:45 - 12:45.
            }

        } else {
            $date->startdate = userdate($endtime, $strftimedate); // 3. February 2023.
            $date->datestring = userdate($starttime, $strftimedatetime); // 3. February 2023, 11:45.
            $date->startdatetime = userdate($starttime, $strftimedatetime); // 3. February 2023, 11:45.

            if (!empty($endtime)) {
                $date->enddate = userdate($endtime, $strftimedate); // 3. February 2023.
                $date->datestring .= " - ";
                $date->datestring .= $date->startdate != $date->enddate ?
                    $date->enddatetime : // 3. February 2023, 11:45 - 4. February 2023, 12:45.
                    $date->endtime; // 3. February 2023, 11:45 - 12:45.
            }
        }

        return $date;
    }

}
