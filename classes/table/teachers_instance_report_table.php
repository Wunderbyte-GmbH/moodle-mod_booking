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
 * Report table to show an overall report of teachers for a specific booking instance.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../../lib.php');
require_once($CFG->libdir . '/tablelib.php');

use mod_booking\option\dates_handler;
use moodle_url;
use table_sql;

/**
 * Class to handle report table to show an overall report of teachers for a specific booking instance.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teachers_instance_report_table extends table_sql {
    /** @var int $bookingid */
    public $bookingid;

    /** @var int $cmid */
    public $cmid;

    /** @var int $unitlength */
    public $unitlength;

    /** @var string $decimalseparator */
    public $decimalseparator;

    /** @var string $thousandsseparator */
    public $thousandsseparator;

    /** @var string $missinghourssql */
    private $missinghourssql;

    /**
     * Constructor
     * @param string $uniqueid all tables have to have a unique id, this is used
     * @param int $bookingid id of a booking instance (do not confuse with cmid!)
     * @param int $cmid course module id of a booking instance
     */
    public function __construct(string $uniqueid, int $bookingid = 0, int $cmid = 0) {
        parent::__construct($uniqueid . $bookingid . $cmid);

        global $DB, $PAGE;
        $this->baseurl = $PAGE->url;
        $this->bookingid = $bookingid;
        $this->cmid = $cmid;

        $this->missinghourssql = "SELECT DISTINCT bod.id optiondateid, bod.bookingid, bod.optionid,
            bo.titleprefix, bo.text,
            bod.coursestarttime, bod.courseendtime,
            bt.userid teacherid, bod.reason
            FROM {booking_optiondates} bod
            JOIN {booking_options} bo
            ON bo.id = bod.optionid
            JOIN {booking_teachers} bt
            ON bod.optionid = bt.optionid
            LEFT JOIN {booking_optiondates_teachers} bodt
            ON bodt.optiondateid = bod.id
            WHERE bod.bookingid = :bookingid
            AND bt.userid = :teacherid
            GROUP BY bod.id, bod.bookingid, bod.optionid,
                bo.titleprefix, bo.text,
                bod.coursestarttime, bod.courseendtime,
                bt.userid, bod.reason
            HAVING " . $DB->sql_concat("'-'", $DB->sql_group_concat("bodt.userid", "-"), "'-'")
                . " NOT LIKE " . $DB->sql_concat("'%-'", ":teacherid2", "'-%'");

        // Get unit length from config (should be something like 45, 50 or 60 minutes).
        if (!$this->unitlength = (int) get_config('booking', 'educationalunitinminutes')) {
            $this->unitlength = 60;
        }

        // For German use "," as comma and " " as thousands separator.
        if (current_language() == "de") {
            $this->decimalseparator = ",";
            $this->thousandsseparator = " ";
        } else {
            // In all other cases, we use the default separators.
            $this->decimalseparator = ".";
            $this->thousandsseparator = ",";
        }

        // Columns and headers are not defined in constructor, in order to keep things as generic as possible.
    }

    /**
     * This function is called for each data row to allow processing of the
     * lastname value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws \moodle_exception
     * @throws \coding_exception
     */
    public function col_lastname($values) {

        $namestring = '';
        if (!$this->is_downloading()) {
            $namestring .= "<sup><span class='badge badge-info'>" .
                substr($values->lastname, 0, 1) . "</span></sup> ";
            $namestring .= "<a href='/mod/booking/teacher_performed_units_report.php?teacherid="
                . $values->teacherid . "' target='_blank'>";
            $namestring .= "$values->firstname $values->lastname";
            $namestring .= "</a>";
        } else {
            $namestring = $values->lastname;
        }
        return $namestring;
    }

    /**
     * This function is called for each data row to allow processing of the
     * firstname value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_firstname($values) {

        return $values->firstname;
    }

    /**
     * Helper function to improve performance,
     * so that SQL for col_units_courses and for col_sum_units
     * gets called only once.
     *
     * @param object $values reference to values object.
     */
    private function set_units_courses_records(&$values) {
        global $DB;
        if (empty($values->unitsrecords)) {
            $sql = "SELECT bo.id optionid, bo.titleprefix, bo.text, bo.dayofweektime
            FROM {booking_teachers} bt
            JOIN {booking_options} bo
            ON bo.id = bt.optionid
            WHERE bt.userid = :teacherid
            AND bt.bookingid = :bookingid";

            $params = [
                'teacherid' => $values->teacherid,
                'bookingid' => $this->bookingid,
            ];
            $values->unitsrecords = $DB->get_records_sql($sql, $params);
        }
    }

    /**
     * This function is called for each data row to allow processing of the
     * units_courses value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_units_courses($values) {
        // This will execute the SQL and save the records in $values->unitsrecords.
        $this->set_units_courses_records($values);

        $optionswithdurations = '';
        if (!empty($values->unitsrecords)) {
            foreach ($values->unitsrecords as $record) {
                // We do not format export data with HTML!
                if (!$this->is_downloading()) {
                    $optionswithdurations .= '<hr/>';
                }
                if (!empty($record->dayofweektime)) {
                    $dayinfo = dates_handler::prepare_day_info($record->dayofweektime);
                    if (!empty($dayinfo['starttime']) && !empty($dayinfo['endtime'])) {
                        $minutes = (strtotime('today ' . $dayinfo['endtime']) - strtotime('today ' . $dayinfo['starttime'])) / 60;
                        $units = number_format(
                            $minutes / $this->unitlength,
                            1,
                            $this->decimalseparator,
                            $this->thousandsseparator
                        );
                        $unitstringpart = get_string('units', 'mod_booking') . ": $units";
                    } else {
                        $unitstringpart = get_string('unitsunknown', 'mod_booking');
                    }
                } else {
                    $unitstringpart = get_string('unitsunknown', 'mod_booking');
                }
                if (!$this->is_downloading()) {
                    $optionurl = new moodle_url(
                        '/mod/booking/optiondates_teachers_report.php',
                        ['cmid' => $this->cmid, 'optionid' => $record->optionid]
                    );
                    $optionswithdurations .= "<b><a href='$optionurl' target='_blank'>";
                }
                if (!empty($record->titleprefix)) {
                    $optionswithdurations .= $record->titleprefix . " - ";
                }
                $optionswithdurations .= $record->text; // Option name.
                if (!$this->is_downloading()) {
                    $optionswithdurations .= '</a></b>';
                }
                if (!empty($record->dayofweektime)) {
                    $optionswithdurations .= " ($record->dayofweektime)";
                }
                $optionswithdurations .= " | $unitstringpart";
                if (!$this->is_downloading()) {
                    $optionswithdurations .= "<br/>";
                } else {
                    $optionswithdurations .= PHP_EOL;
                }
            }
        }

        if (!$this->is_downloading()) {
            $retstring = '<a data-toggle="collapse" href="#optionsforteacher-' . $values->teacherid .
                '" role="button" aria-expanded="false" aria-controls="coursesforteacher">
                <i class="fa fa-graduation-cap"></i> ' . get_string('courses') .
                '</a><div class="collapse" id="optionsforteacher-' . $values->teacherid . '">' .
                $optionswithdurations . '</div>';
        } else {
            $retstring = trim($optionswithdurations);
        }

        return $retstring;
    }

    /**
     * This function is called for each data row to allow processing of the
     * sum_units value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_sum_units($values) {

        // This will execute the SQL and save the records in $values->unitsrecords.
        $this->set_units_courses_records($values);

        $sumunits = 0;
        if (!empty($values->unitsrecords)) {
            foreach ($values->unitsrecords as $record) {
                if (!empty($record->dayofweektime)) {
                    $dayinfo = dates_handler::prepare_day_info($record->dayofweektime);
                    if (empty($dayinfo['starttime']) || empty($dayinfo['endtime'])) {
                        continue;
                    }
                    $minutes = (strtotime('today ' . $dayinfo['endtime']) - strtotime('today ' . $dayinfo['starttime'])) / 60;
                    $units = round($minutes / $this->unitlength, 1);
                    $sumunits += $units;
                }
            }
        }

        if (!$this->is_downloading()) {
            $retstring = number_format($sumunits, 1, $this->decimalseparator, $this->thousandsseparator) .
                ' ' . get_string('units', 'mod_booking');
        } else {
            // For download, we do not show the units so it's easier to use with sheet applications like Excel.
            $retstring = number_format($sumunits, 1, $this->decimalseparator, $this->thousandsseparator);
        }

        return $retstring;
    }

    /**
     * This function is called for each data row to allow processing of the
     * missinghours value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_missinghours($values) {
        global $DB;

        $sql = $this->missinghourssql;

        $params = [
            'teacherid' => $values->teacherid,
            'teacherid2' => $values->teacherid,
            'bookingid' => $this->bookingid,
        ];

        $missinghoursstring = '';
        if ($records = $DB->get_records_sql($sql, $params)) {
            foreach ($records as $record) {
                if (!$this->is_downloading()) {
                    $missinghoursstring .= '<hr/>';
                    $optionurl = new moodle_url(
                        '/mod/booking/optiondates_teachers_report.php',
                        ['cmid' => $this->cmid, 'optionid' => $record->optionid]
                    );
                    $missinghoursstring .= "<a href='$optionurl' target='_blank'>";
                }
                if (!empty($record->titleprefix)) {
                    $missinghoursstring .= $record->titleprefix . " - ";
                }
                if (!$this->is_downloading()) {
                    $missinghoursstring .= $record->text .
                        '</a> (<b>' . dates_handler::prettify_optiondates_start_end(
                            $record->coursestarttime,
                            $record->courseendtime,
                            current_language()
                        ) . '</b>) | ' . get_string('reason', 'mod_booking') . ': ' .
                        $record->reason . '<br/>';
                    if (
                        $deductionrecord = $DB->get_record('booking_odt_deductions', [
                        'optiondateid' => $record->optiondateid,
                        'userid' => $values->teacherid,
                        ])
                    ) {
                        $missinghoursstring .= '<i class="fa fa-minus-square-o" aria-hidden="true"></i>&nbsp;' .
                            get_string('deduction', 'mod_booking') . ' | ' .
                            get_string('deductionreason', 'mod_booking') . ': ' .
                            $deductionrecord->reason . '<br/>';
                    }
                } else {
                    $missinghoursstring .= $record->text .
                        ' (' . dates_handler::prettify_optiondates_start_end(
                            $record->coursestarttime,
                            $record->courseendtime,
                            current_language()
                        ) . ') | ' . get_string('reason', 'mod_booking') . ': ' .
                        $record->reason . PHP_EOL;
                    if (
                        $deductionrecord = $DB->get_record('booking_odt_deductions', [
                        'optiondateid' => $record->optiondateid,
                        'userid' => $values->teacherid,
                        ])
                    ) {
                        $missinghoursstring .= get_string('deduction', 'mod_booking') . ' | ' .
                            get_string('deductionreason', 'mod_booking') . ': ' .
                            $deductionrecord->reason . PHP_EOL;
                    }
                }
            }
        }

        if (!$this->is_downloading()) {
            $retstring = '<a data-toggle="collapse" href="#missinghoursforteacher-' . $values->teacherid .
                '" role="button" aria-expanded="false" aria-controls="missinghoursforteacher">
                <i class="fa fa-user-times"></i> ' . get_string('missinghours', 'mod_booking') .
                '</a><div class="collapse" id="missinghoursforteacher-' . $values->teacherid . '">' .
                $missinghoursstring . '</div>';
        } else {
            $retstring = trim($missinghoursstring);
        }
        return $retstring;
    }

    /**
     * This function is called for each data row to allow processing of the
     * substitutions value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a string containing all teacher names.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_substitutions($values) {
        global $DB;

        // We use the same SQL as for missing hours here.
        $sql = $this->missinghourssql;

        $params = [
            'teacherid' => $values->teacherid,
            'teacherid2' => $values->teacherid,
            'bookingid' => $this->bookingid,
        ];

        $substitutionsstring = '';
        if ($records = $DB->get_records_sql($sql, $params)) {
            foreach ($records as $record) {
                // For each missing hour, we need to find out who substituted it.
                $substitutionssql =
                    "SELECT u.id, u.firstname, u.lastname, u.email
                    FROM {booking_optiondates_teachers} bodt
                    JOIN {booking_optiondates} bod
                    ON bod.id = bodt.optiondateid
                    JOIN {user} u
                    ON u.id = bodt.userid
                    WHERE optiondateid = :optiondateid
                    AND userid NOT IN (
                        SELECT userid
                        FROM {booking_teachers} bt
                        WHERE optionid = bod.optionid
                    )";

                $substitutionsparams = [
                    'optiondateid' => $record->optiondateid,
                ];

                if ($substitutionsrecords = $DB->get_records_sql($substitutionssql, $substitutionsparams)) {
                    if (!$this->is_downloading()) {
                        $substitutionsstring .= '<hr/>';
                        $optionurl = new moodle_url(
                            '/mod/booking/optiondates_teachers_report.php',
                            ['cmid' => $this->cmid, 'optionid' => $record->optionid]
                        );
                        $substitutionsstring .= "<a href='$optionurl' target='_blank'>";
                    }
                    if (!empty($record->titleprefix)) {
                        $substitutionsstring .= $record->titleprefix . " - ";
                    }
                    if (!$this->is_downloading()) {
                        $substitutionsstring .= $record->text .
                            '</a> (' . dates_handler::prettify_optiondates_start_end(
                                $record->coursestarttime,
                                $record->courseendtime,
                                current_language()
                            ) . ')';
                        foreach ($substitutionsrecords as $sub) {
                            $substitutionsstring .= "<br/><b>$sub->firstname $sub->lastname</b> ($sub->email)";
                        }
                        $substitutionsstring .= '<br/>';
                    } else {
                        $substitutionsstring .= $record->text .
                            ' (' . dates_handler::prettify_optiondates_start_end(
                                $record->coursestarttime,
                                $record->courseendtime,
                                current_language()
                            ) . ')';
                        foreach ($substitutionsrecords as $sub) {
                            $substitutionsstring .= "<br/>$sub->firstname $sub->lastname ($sub->email)";
                        }
                        $substitutionsstring .= PHP_EOL;
                    }
                }
            }
        }

        if (!$this->is_downloading()) {
            $retstring = '<a data-toggle="collapse" href="#substitutionsforteacher-' . $values->teacherid .
                '" role="button" aria-expanded="false" aria-controls="substitutionsforteacher">
                <i class="fa fa-handshake-o"></i> ' . get_string('substitutions', 'mod_booking') .
                '</a><div class="collapse" id="substitutionsforteacher-' . $values->teacherid . '">' .
                $substitutionsstring . '</div>';
        } else {
            $retstring = trim($substitutionsstring);
        }
        return $retstring;
    }
}
