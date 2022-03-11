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
     * @return void
     */
    public function add_optiondates_for_semesters_to_mform(MoodleQuickForm &$mform) {
        global $PAGE;

        $semestersarray = semester::get_semesters_identifier_name_array();

        $mform->addElement('select', 'chooseperiod', get_string('chooseperiod', 'mod_booking'), $semestersarray);
        $mform->addHelpButton('chooseperiod', 'chooseperiod', 'mod_booking');

        $mform->addElement('text', 'reoccurringdatestring', get_string('reoccurringdatestring', 'booking'));
        $mform->setType('reoccurringdatestring', PARAM_TEXT);

        // Add already existing optiondates to form.
        $output = $PAGE->get_renderer('mod_booking');
        $data = new \mod_booking\output\bookingoption_dates($this->optionid);
        $mform->addElement('html', '<div id="optiondates-list">');
        $mform->addElement('html', $output->render_bookingoption_dates($data));
        $mform->addElement('html', '</div>');
    }

    /**
     * Transform each optiondate and save.
     *
     * @param stdClass $fromform form data
     * @param array $optiondates array of optiondates as strings (e.g. "11646647200-1646650800")
     */
    public function save_from_form(stdClass $fromform, array $optiondates, array $stillexistingdateids) {
        global $DB;

        if ($this->optionid && $this->bookingid) {

            // Get the currently saved optiondateids from DB.
            $olddates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid], '', 'id');

            // Now, let's check, if they have not been removed by the dynamic form.
            foreach ($olddates as $olddate) {
                if (in_array((int) $olddate->id, $stillexistingdateids)) {
                    continue;
                } else {
                    // An existing optiondate has been removed by the dynamic form, so delete it from DB.
                    $DB->delete_records('booking_optiondates', ['id' => (int) $olddate->id]);
                }
            }

            // It's important that this happens AFTER deleting the removed dates.
            foreach ($optiondates as $optiondatestring) {
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
     * @param int $startdate
     * @param int $enddate
     * @param string $daystring
     * @return array
     */
    public function get_optiondate_series(int $startdate, int $enddate, array $dayinfo): array {
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
            $date->dateid = 'newdate-' . $j;
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
    public function prepare_day_info(string $string): array {
        $string = strtolower($string);
        $string = str_replace('-', ' ', $string);
        $string = str_replace(',', ' ', $string);
        $string = preg_replace("/\s+/", " ", $string);
        $strings = explode(' ',  $string);

        $shortday = $strings[0];

        switch ($shortday) {
            case 'mo':
            case 'mon':
            case 'monday':
            case 'montag':
                $day = "Monday";
                break;
            case 'tu':
            case 'tue':
            case 'di':
            case 'die':
            case 'tuesday':
            case 'dienstag':
                $day = "Tuesday";
                break;
            case 'mi':
            case 'mit':
            case 'we':
            case 'wed':
            case 'mittwoch':
            case 'wednesday':
                $day = "Wednesday";
                break;
            case 'th':
            case 'thu':
            case 'do':
            case 'don':
            case 'thursday':
            case 'donnerstag':
                $day = "Thursday";
                break;
            case 'fr':
            case 'fre':
            case 'fri':
            case 'freitag':
            case 'friday':
                $day = "Friday";
                break;
            case 'sa':
            case 'sam':
            case 'sat':
            case 'samstag':
            case 'saturday':
                $day = "Saturday";
                break;
            case 'so':
            case 'su':
            case 'son':
            case 'sun':
            case 'sonntag':
            case 'sunday':
                $day = "Sunday";
                break;
            default:
                // Invalid day identifier, so return empty array.
                return [];
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
}
