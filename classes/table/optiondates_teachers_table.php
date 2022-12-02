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

use dml_exception;
use html_writer;
use mod_booking\booking_option;
use mod_booking\dates_handler;
use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

/**
 * Report table to show and edit teachers for specific sessions (a.k.a. optiondates).
 */
class optiondates_teachers_table extends table_sql {

    /**
     * Constructor
     * @param string $uniqueid all tables have to have a unique id, this is used
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        global $PAGE;
        $this->baseurl = $PAGE->url;

        // Columns and headers are not defined in constructor, in order to keep things as generic as possible.
    }

    /**
     * This function is called for each data row to allow processing of the
     * optiondateid value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Rendered name (text) of the booking option.
     * @throws dml_exception
     */
    public function col_optionname(object $values): string {
        return $values->text;
    }

    /**
     * This function is called for each data row to allow processing of the
     * optiondateid value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Rendered date and time of the optiondate.
     * @throws dml_exception
     */
    public function col_optiondate(object $values): string {

        return dates_handler::prettify_optiondates_start_end($values->coursestarttime,
            $values->courseendtime, current_language());

    }

    /**
     * This function is called for each data row to allow processing of the
     * userid value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Rendered teacher(s) for the specific optiondate.
     * @throws dml_exception
     */
    public function col_teacher(object $values): string {
        global $DB;

        if ($this->is_downloading()) {
            $teacherstrings = [];
            if (!empty($values->teachers)) {
                $teacherids = explode(',', $values->teachers);
                foreach ($teacherids as $teacherid) {
                    if ($teacheruser = $DB->get_record('user', ['id' => $teacherid])) {
                        $teacherstring = "$teacheruser->firstname $teacheruser->lastname ($teacheruser->email)";
                        $teacherstrings[] = $teacherstring;
                    }
                }
            }
            return implode(', ', $teacherstrings);
        } else {
            $teacherlinks = [];
            $returnstring = '';
            if (!empty($values->teachers)) {
                $teacherids = explode(',', $values->teachers);
                foreach ($teacherids as $teacherid) {
                    if ($teacheruser = $DB->get_record('user', ['id' => $teacherid])) {
                        $teacherprofileurl = new moodle_url('/mod/booking/teacher_performed_units_report.php',
                            ['teacherid' => $teacherid]);
                        $teacherlink = "<a href='$teacherprofileurl'>" .
                            "$teacheruser->firstname $teacheruser->lastname</a>";
                        $teacherlinks[] = $teacherlink;
                    }
                }
            }
            if (!empty($teacherlinks)) {
                $returnstring = implode(' | ', $teacherlinks);
            } else {
                $returnstring = get_string('noteacherset', 'mod_booking');
            }

            return $returnstring;
        }
    }

    /**
     * This function is called for each data row to allow processing of the
     * userid value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Rendered edit button.
     * @throws dml_exception
     */
    public function col_edit(object $values): string {

        $ret = '';
        $ret .= html_writer::div(html_writer::link('#', "<h5><i class='fa fa-edit'></i></h5>",
            ['class' => 'btn-modal-edit-teachers',
            'data-cmid' => $_GET['id'],
            'data-optionid' => $values->optionid,
            'data-teachers' => $values->teachers,
            'data-optiondateid' => $values->optiondateid
        ]));

        return $ret;
    }
}
