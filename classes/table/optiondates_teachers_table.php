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

use cache_helper;
use context_system;
use dml_exception;
use html_writer;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\dates_handler;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Report table to show and edit teachers for specific sessions (a.k.a. optiondates).
 */
class optiondates_teachers_table extends wunderbyte_table {

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
        if (!$this->is_downloading()) {
            $ret .= html_writer::div(html_writer::link('#', "<h5><i class='icon fa fa-edit'></i></h5>",
                ['class' => 'btn-modal-edit-teachers',
                'data-cmid' => $_GET['id'],
                'data-optionid' => $values->optionid,
                'data-teachers' => $values->teachers,
                'data-optiondateid' => $values->optiondateid,
                'title' => get_string('editteachers', 'mod_booking'),
                'aria-label' => get_string('editteachers', 'mod_booking')
            ]));
        }
        return $ret;
    }

    /**
     * This function is called for each data row to allow processing of the
     * 'reviewed' value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Rendered edit button.
     * @throws dml_exception
     */
    public function col_reviewed(object $values): string {
        global $OUTPUT;

        if ($this->is_downloading()) {
            return $values->reviewed ? get_string('yes') : get_string('no');
        }

        $data[] = [
            'label' => get_string('reviewed', 'mod_booking'), // Name of your action button.
            'class' => 'optiondates-teachers-reviewed-checkbox',
            'id' => $values->optiondateid,
            'methodname' => 'togglecheckbox', // The method needs to be added to your child of wunderbyte_table class.
            'ischeckbox' => true,
            'checked' => $values->reviewed == 1,
            'disabled' => !has_capability('mod/booking:canreviewsubstitutions', context_system::instance()),
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->optiondateid,
                'labelcolumn' => 'username',
            ]
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        return $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);;
    }

    /**
     * Toggle checkbox.
     *
     * @param int $optiondateid
     * @param string $data
     * @return array
     */
    public function togglecheckbox(int $optiondateid, string $data):array {
        global $DB;

        if (!has_capability('mod/booking:canreviewsubstitutions', context_system::instance())) {
            return [
                'success' => 0,
                'message' => get_string('error:missingcapability', 'mod_booking'),
             ];
        }

        $dataobject = json_decode($data);

        if ($record = $DB->get_record('booking_optiondates', ['id' => $optiondateid])) {
            $record->reviewed = $dataobject->state == 'true' ? 1 : 0;
            $DB->update_record('booking_optiondates', $record);
            cache_helper::purge_by_event('setbackcachedteachersjournal');
        }

        return [];
    }
}
