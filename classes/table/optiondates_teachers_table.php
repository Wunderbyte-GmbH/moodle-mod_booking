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
 * Report table to show and edit teachers for specific sessions (a.k.a. optiondates).
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

use cache_helper;
use context_system;
use dml_exception;
use html_writer;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\option\dates_handler;
use mod_booking\singleton_service;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Class to handle report table to show and edit teachers for specific sessions (a.k.a. optiondates).
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondates_teachers_table extends wunderbyte_table {
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

        return dates_handler::prettify_optiondates_start_end(
            $values->coursestarttime,
            $values->courseendtime,
            current_language()
        );
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
                        $teacherprofileurl = new moodle_url(
                            '/mod/booking/teacher_performed_units_report.php',
                            ['teacherid' => $teacherid]
                        );
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
        if (!$this->is_downloading() && !$values->reviewed == 1) {
            $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid);
            $cmid = $settings->cmid;

            $ret .= html_writer::div(html_writer::link(
                '#',
                "<h5><i class='icon fa fa-edit'></i></h5>",
                ['class' => 'btn-modal-edit-teachers',
                'data-cmid' => $cmid,
                'data-optionid' => $values->optionid,
                'data-teachers' => $values->teachers,
                'data-optiondateid' => $values->optiondateid,
                'title' => get_string('editteachers', 'mod_booking'),
                'aria-label' => get_string('editteachers', 'mod_booking'),
                ]
            ));
        } else if ($values->reviewed == 1) {
            $ret .= "<h5 style='color: #D3D3D3; cursor: not-allowed;'><i class='icon fa fa-edit'></i></h5>";
        }
        return $ret;
    }

    /**
     * This function is called for each data row to allow processing of the
     * deduction value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Rendered name (text) of the booking option.
     * @throws dml_exception
     */
    public function col_deduction(object $values): string {
        global $DB;
        $optiondateid = $values->optiondateid;
        $ret = '';
        if ($deductions = $DB->get_records('booking_odt_deductions', ['optiondateid' => $optiondateid])) {
            foreach ($deductions as $ded) {
                $teacher = singleton_service::get_instance_of_user($ded->userid);
                if (!$this->is_downloading()) {
                    $ret .= '<i class="fa fa-minus-square-o" aria-hidden="true"></i>&nbsp;';
                    $ret .= "<b>$teacher->firstname $teacher->lastname</b>";
                } else {
                    $ret .= "$teacher->firstname $teacher->lastname";
                }
                if (!empty($ded->reason)) {
                    $ret .= " | " . get_string('deductionreason', 'mod_booking') . ": $ded->reason";
                }
                $ret .= "<br/>";
            }
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
            ],
        ];

        // This transforms the array to make it easier to use in mustache template.
        table::transform_actionbuttons_array($data);

        return $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);
        ;
    }

    /**
     * Toggle checkbox.
     *
     * @param int $optiondateid
     * @param string $data
     * @return array
     */
    public function action_togglecheckbox(int $optiondateid, string $data): array {
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

        return [
            'success' => 1,
            'message' => '',
        ];
    }
}
