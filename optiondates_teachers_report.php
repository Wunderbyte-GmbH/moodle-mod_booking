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

$optiondatesteacherstable->show_download_buttons_at(array(TABLE_P_BOTTOM));

if (!$optiondatesteacherstable->is_downloading()) {

    // Table will be shown normally.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('optiondatesteachersreport', 'mod_booking'));

    // Columns.
    $optiondatesteacherstable->define_columns([
        'optiondateid',
        'optionid',
        'coursestarttime',
        'courseendtime',
        'userid'
    ]);

    // Header.
    $optiondatesteacherstable->define_headers([
        'optiondateid',
        'optionid',
        'coursestarttime',
        'courseendtime',
        'userid'
    ]);

    // SQL query.
    $fields = "bodt.optiondateid, bod.optionid, bod.coursestarttime, bod.courseendtime, bodt.userid";
    $from = "{booking_optiondates_teachers} bodt
            LEFT JOIN {booking_optiondates} bod
            ON bodt.optiondateid = bod.id";
    $where = "bod.optionid = :optionid";
    $params = ['optionid' => $optionid];

    // Now build the table.
    $optiondatesteacherstable->set_sql($fields, $from, $where, $params);
    $optiondatesteacherstable->out(100, true);

    echo $OUTPUT->footer();
} else {
    // Columns.
    $optiondatesteacherstable->define_columns([
        'optiondateid',
        'optionid',
        'coursestarttime',
        'courseendtime',
        'userid'
    ]);

    // Headers.
    $optiondatesteacherstable->define_headers([
        'optiondateid',
        'optionid',
        'coursestarttime',
        'courseendtime',
        'userid'
    ]);

    // SQL query.
    $fields = "bodt.id, bodt.optiondateid, bod.optionid, bod.coursestarttime, bod.courseendtime, bodt.userid";
    $from = "{booking_optiondates_teachers} bodt
            LEFT JOIN {booking_optiondates} bod
            ON bodt.optiondateid = bod.id";
    $where = "bod.optionid = :optionid";
    $params = ['optionid' => $optionid];

    // Now build the table.
    $optiondatesteacherstable->set_sql($fields, $from, $where, $params);
    $optiondatesteacherstable->setup();
    $optiondatesteacherstable->query_db(100);
    $optiondatesteacherstable->build_table();
    $optiondatesteacherstable->finish_output();
}
