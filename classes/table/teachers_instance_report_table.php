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

namespace mod_booking\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../../lib.php');
require_once($CFG->libdir.'/tablelib.php');

use mod_booking\optiondates_handler;
use table_sql;

defined('MOODLE_INTERNAL') || die();

/**
 * Report table to show an overall report
 * of teachers for a specific booking instance.
 */
class teachers_instance_report_table extends table_sql {

    /**
     * Constructor
     * @param string $uniqueid all tables have to have a unique id, this is used
     * @param int $bookingid id of a booking instance (not cmid!)
     */
    public function __construct(string $uniqueid, int $bookingid = 0) {
        parent::__construct($uniqueid);

        global $PAGE;
        $this->baseurl = $PAGE->url;
        $this->bookingid = $bookingid;

        // Get unit length from config (should be something like 45, 50 or 60 minutes).
        if (!$this->unitlength = (int) get_config('booking', 'educationalunitinminutes')) {
            $this->unitlength = 60;
        }

        // Columns and headers are not defined in constructor, in order to keep things as generic as possible.
    }

    /**
     * This function is called for each data row to allow processing of the
     * userid value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_userid($values) {

        return "$values->firstname $values->lastname";
    }

    /**
     * This function is called for each data row to allow processing of the
     * courses value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_courses($values) {
        global $DB;

        $sql = "SELECT bo.id, bo.titleprefix, bo.text, bo.dayofweektime
            FROM {booking_teachers} bt
            JOIN {booking_options} bo
            ON bo.id = bt.optionid
            WHERE bt.userid = :teacherid
            AND bt.bookingid = :bookingid";

        $params = [
            'teacherid' => $values->teacherid,
            'bookingid' => $this->bookingid
        ];

        $optionswithdurations = '';
        if ($records = $DB->get_records_sql($sql, $params)) {
            foreach ($records as $record) {

                if (!empty($record->dayofweektime)) {
                    $dayinfo = optiondates_handler::prepare_day_info($record->dayofweektime);
                    $minutes = (strtotime('today ' . $dayinfo['endtime']) - strtotime('today ' . $dayinfo['starttime'])) / 60;
                    $units = number_format($minutes / $this->unitlength, 2);
                    $unitstringpart = "$record->dayofweektime, $units " . get_string('units', 'mod_booking');
                } else {
                    $unitstringpart = get_string('units_unknown', 'mod_booking');
                }

                if (!empty($record->titleprefix)) {
                    $optionswithdurations .= $record->titleprefix . " - ";
                }
                $optionswithdurations .= $record->text; // Option name.
                $optionswithdurations .= " ($unitstringpart)<br/>";
            }
        }

        $retstring = '<a data-toggle="collapse" href="#optionsforteacher-' . $values->teacherid .
            '" role="button" aria-expanded="false" aria-controls="coursesforteacher">
            <i class="fa fa-graduation-cap"></i>' . get_string('courses') .
            '</a><div class="collapse" id="optionsforteacher-' . $values->teacherid . '">' .
            $optionswithdurations . '</div>';

        return $retstring;
    }
}
