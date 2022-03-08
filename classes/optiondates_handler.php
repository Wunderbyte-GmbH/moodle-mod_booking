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
use MoodleQuickForm;
use stdClass;

/**
 * Add price categories form.
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
     * @return void
     */
    public function add_optiondates_for_semesters_to_mform(MoodleQuickForm &$mform) {

        $mform->addElement('select', 'semester', 'semester', array('WS22', 'WS23', 'SS22'));
        $mform->addElement('text', 'reocurringdatestring', get_string('reocurringdatestring', 'booking'));
        $mform->setType('reocurringdatestring', PARAM_TEXT);
    }


    /**
     * Transform each optiondate and save.
     *
     * @param array $optiondates array of optiondates as strings (e.g. "11646647200-1646650800")
     */
    public function save_from_form(array $optiondates) {
        global $DB;

        if ($this->optionid && $this->bookingid) {
            foreach ($optiondates as $optiondatestring) {
                list($starttime, $endtime) = explode('-', $optiondatestring);

                $optiondate = new stdClass();
                $optiondate->bookingid = $this->bookingid;
                $optiondate->optionid = $this->optionid;
                $optiondate->eventid = 0; // TODO: We will implement this later.
                $optiondate->coursestarttime = (int) $starttime;
                $optiondate->courseendtime = (int) $endtime;
                $optiondate->daystonotify = 0; // TODO: We will implement this later.

                $DB->insert_record("booking_optiondates", $optiondate);
            }

            // After updating, we invalidate caches.
            cache_helper::purge_by_event('setbackoptionstable');
            cache_helper::invalidate_by_event('setbackoptionsettings', [$this->optionid]);

            booking_updatestartenddate($this->optionid);
        }
    }

    /**
     * Get date array for a specific weekday and time between two dates.
     * @param int $startdate
     * @param int $enddate
     * @param string $daystring
     * @return array
     */
    public function get_date_for_specific_day_between_dates(int $startdate, int $enddate, array $dayinfo): array {
        $j = 1;
        sscanf($dayinfo['starttime'], "%d:%d", $hours, $minutes);
        $startseconds = ($hours * 60 * 60) + ($minutes * 60);
        sscanf($dayinfo['endtime'], "%d:%d", $hours, $minutes);
        $endseconds = $hours * 60 * 60 + $minutes * 60;
        for ($i = strtotime($dayinfo['day'], $startdate); $i <= $enddate; $i = strtotime('+1 week', $i)) {
            $date = new stdClass();
            $date->date = date('Y-m-d', $i);
            $date->starttime = $dayinfo['starttime'];
            $date->endtime = $dayinfo['endtime'];
            $date->dateid = 'dateid-' . $j;
            $date->starttimestamp = $i + $startseconds;
            $date->endtimestamp = $i + $endseconds;
            $j++;
            $date->string = $date->date . " " .$date->starttime. "-" .$date->endtime;
            $datearray['dates'][] = $date;
        }
        return $datearray;
    }



    /**
     * TODO: will be replaced by a regex function.
     * @param string $string
     * @return array
     */
    public function translate_string_to_day(string $string): array {
        $string = strtolower($string);
        $string = str_replace('-', ' ', $string);
        $string = preg_replace("/[[:blank:]]+/", " ", $string);
        $strings = explode(' ',  $string);
        if ($strings[0] == 'mo') {
            $day = "Monday";
        }
        if ($strings[0] == 'di') {
            $day = "Tuesday";
        }
        if ($strings[0] == 'mi') {
            $day = "Wednesday";
        }
        if ($strings[0] == 'do') {
            $day = "Thursday";
        }
        if ($strings[0] == 'fr') {
            $day = "Friday";
        }
        if ($strings[0] == 'sa') {
            $day = "Saturday";
        }
        if ($strings[0] == 'so') {
            $day = "Sunday";
        }
        $dayinfo['day'] = $day;
        $dayinfo['starttime'] = $strings[1];
        $dayinfo['endtime'] = $strings[2];
        return $dayinfo;
    }

    /**
     * TODO: replace with DB call.
     *
     * @param int $timestamp
     * @return bool
     */
    public function is_holiday(int $timestamp): bool {
        // DB id date timestamp.
        $holidayarray['2022-12-24'] = 1;
        $holidayarray['2022-12-31'] = 1;
        $date = date('Y-m-d', $timestamp);
        if (isset($holidayarray[$date])) {
            return true;
        }
        return false;
    }

    /**
     * TODO: delete this function and replace it with semester class.
     * @param int $semesterid
     * @return stdClass
     */
    public function get_semester(int $semesterid): stdClass {
        $semester = new stdClass();
        $semester->startdate = 1646598962;
        $semester->enddate = 1654505170;
        return $semester;
    }
}
