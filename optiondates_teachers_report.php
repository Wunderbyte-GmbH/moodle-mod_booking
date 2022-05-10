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
 * Report to track which teacher was teaching at which session (optiondate).
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\table\optiondates_teachers_table;

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT); // Course module id.
$optionid = required_param('optionid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);
require_course_login($course, false, $cm);

$urlparams = [
    'id' => $cmid,
    'optionid' => $optionid
];

$context = context_module::instance($cmid);
$PAGE->set_context($context);

$baseurl = new moodle_url('/mod/booking/optiondates_teachers_report.php', $urlparams);
$PAGE->set_url($baseurl);

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
}

$optiondatesteacherstable = new optiondates_teachers_table('optiondates_teachers_table');

$optiondatesteacherstable->is_downloading($download, 'test', 'test123');

$tablebaseurl = $baseurl;
$tablebaseurl->remove_params('page');
$optiondatesteacherstable->define_baseurl($tablebaseurl);
$optiondatesteacherstable->defaultdownloadformat = 'pdf';
$optiondatesteacherstable->sortable(false);

$optiondatesteacherstable->show_download_buttons_at(array(TABLE_P_BOTTOM));

if (!$optiondatesteacherstable->is_downloading()) {

    // Table will be shown normally.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('optiondatesteachersreport', 'mod_booking'));

    // Dismissible alert containing the description of the report.
    echo '<div class="alert alert-info alert-dismissible fade show" role="alert">' .
        get_string('optiondatesteachersreport_desc', 'mod_booking') .
        '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
        </button>
    </div>';

    // Header.
    $optiondatesteacherstable->define_headers([
        get_string('name'),
        get_string('optiondate', 'mod_booking'),
        get_string('teacher', 'mod_booking'),
        get_string('edit')
    ]);
    // Columns.
    $optiondatesteacherstable->define_columns([
        'optionname',
        'optiondate',
        'teacher',
        'edit'
    ]);
    // SQL query. The subselect will fix the "Did you remember to make the first column something...
    // ...unique in your call to get_records?" bug.
    $fields = "s.optiondateid, s.optionid, s.text, s.coursestarttime, s.courseendtime, s.teachers";
    $from = "(
        SELECT bod.id optiondateid, bod.optionid, bo.text, bod.coursestarttime, bod.courseendtime, " .
        $DB->sql_group_concat('u.id', ',', 'u.id') . " teachers
        FROM {booking_optiondates_teachers} bodt
        LEFT JOIN {booking_optiondates} bod
        ON bodt.optiondateid = bod.id
        LEFT JOIN {booking_options} bo
        ON bo.id = bod.optionid
        LEFT JOIN {user} u
        ON u.id = bodt.userid
        WHERE bod.optionid = :optionid
        GROUP BY bod.id, bod.optionid, bo.text, bod.coursestarttime, bod.courseendtime
        ORDER BY bod.coursestarttime ASC
        ) s";
    $where = "1=1";
    $params = ['optionid' => $optionid];

    // Now build the table.
    $optiondatesteacherstable->set_sql($fields, $from, $where, $params);
    $optiondatesteacherstable->out(100, false);

    echo $OUTPUT->footer();
} else {
    // The table is being downloaded.

    // Header.
    $optiondatesteacherstable->define_headers([
        get_string('name'),
        get_string('optiondate', 'mod_booking'),
        get_string('teacher', 'mod_booking')
    ]);
    // Columns.
    $optiondatesteacherstable->define_columns([
        'optionname',
        'optiondate',
        'teacher'
    ]);
    // SQL query.
    // TODO: copy from above and adapt accordingly!
    $fields = "bodt.optiondateid, bod.optionid, bo.text, bod.coursestarttime, bod.courseendtime, bodt.userid,
                u.firstname, u.lastname";
    $from = "{booking_optiondates} bod
            LEFT JOIN {booking_optiondates_teachers} bodt
            ON bodt.optiondateid = bod.id
            LEFT JOIN {booking_options} bo
            ON bo.id = bod.optionid
            LEFT JOIN {user} u
            ON u.id = bodt.userid";
    $where = "bod.optionid = :optionid
            ORDER BY bod.coursestarttime ASC";
    $params = ['optionid' => $optionid];

    // Now build the table.
    $optiondatesteacherstable->set_sql($fields, $from, $where, $params);
    $optiondatesteacherstable->setup();
    $optiondatesteacherstable->query_db(100);
    $optiondatesteacherstable->build_table();
    $optiondatesteacherstable->finish_output();
}
