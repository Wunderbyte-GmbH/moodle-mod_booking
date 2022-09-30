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
 * Overall report for all teachers within a booking instance.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\form\teachers_instance_report_form;
use mod_booking\singleton_service;
use mod_booking\table\teachers_instance_report_table;

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

// No guest autologin.
require_login(0, false);

$urlparams = [
    'cmid' => $cmid
];

$context = context_system::instance();
$PAGE->set_context($context);

$baseurl = new moodle_url('/mod/booking/teachers_instance_report.php', $urlparams);
$PAGE->set_url($baseurl);

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

if (!$cmidobj = $DB->get_record_sql(
   "SELECT cm.id FROM {course_modules} cm
    JOIN {modules} m
    ON m.id = cm.module
    WHERE m.name = 'booking' AND cm.id = :cmid",
    ['cmid' => $cmid]
)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('error'), 4);
    echo get_string('error:invalidcmid', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

$bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
$instancename = $bookingsettings->name;
$bookingid = $bookingsettings->id; // Do not confuse bookingid with cmid!

// Replace special characters to prevent errors.
$instancename = str_replace(' ', '_', $instancename); // Replaces all spaces with underscores.
$instancename = preg_replace('/[^A-Za-z0-9\_]/', '', $instancename); // Removes special chars.
$instancename = preg_replace('/\_+/', '_', $instancename); // Replace multiple underscores with exactly one.
$instancename = format_string($instancename);

// File name and sheet name.
$fileandsheetname = "teachers_instance_report_for_" . $instancename;

$teachersinstancereporttable = new teachers_instance_report_table('teachers_instance_report_table', $bookingid);

$teachersinstancereporttable->is_downloading($download, $fileandsheetname, $fileandsheetname);

$tablebaseurl = $baseurl;
$tablebaseurl->remove_params('page');
$teachersinstancereporttable->define_baseurl($tablebaseurl);
$teachersinstancereporttable->sortable(false);
$teachersinstancereporttable->collapsible(false);
$teachersinstancereporttable->show_download_buttons_at([TABLE_P_TOP]);

// Get unit length from config (should be something like 45, 50 or 60 minutes).
if (!$unitlength = get_config('booking', 'educationalunitinminutes')) {
    $unitlength = '60'; // If it's not set, we use an hour as default.
}

// Initialize the Moodle form for filtering the table.
$mform = new teachers_instance_report_form();
$mform->set_data(['cmid' => $cmid]);

// Form processing and displaying is done here.
if ($fromform = $mform->get_data()) {

    if (empty($fromform->teacherid)) {
        $teacherid = 0;
    } else {
        $teacherid = $fromform->teacherid;
        // Needed, so we can also use the filter for downloading.
        set_user_preference('teachersinstancereport_teacherid', $teacherid);
    }
}

if (!$teachersinstancereporttable->is_downloading()) {

    // Table will be shown normally.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('teachingreportforinstance', 'mod_booking') .
        $bookingsettings->name);

    echo '<div class="alert alert-info alert-dismissible fade show" role="alert">' .
        get_string('teachersinstancereport:subtitle', 'mod_booking') .
        '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>';

    // Now show the mform for filtering.
    $mform->display();

    // Headers.
    $teachersinstancereporttable->define_headers([
        get_string('teacher', 'mod_booking'),
        get_string('email'),
        get_string('sum_units', 'mod_booking'),
        get_string('units_courses', 'mod_booking'),
        get_string('missinghours', 'mod_booking'),
        get_string('substitutions', 'mod_booking')
    ]);

    // Columns.
    $teachersinstancereporttable->define_columns([
        'userid',
        'email',
        'sum_units',
        'units_courses',
        'missinghours',
        'substitutions'
    ]);

    // Header column.
    $teachersinstancereporttable->define_header_column('userid');

    // SQL query.
    $fields = "DISTINCT u.id teacherid, u.firstname, u.lastname, u.email";

    $from = "{booking_teachers} bt
            JOIN {user} u
            ON u.id = bt.userid";

    $andteacher = '';
    if (!empty($teacherid)) {
        $andteacher = "AND userid = :teacherid";
        $params['teacherid'] = $teacherid;
    }
    $where = "bt.bookingid = :bookingid $andteacher";

    $params['bookingid'] = $bookingid;

    // Now build the table.
    $teachersinstancereporttable->set_sql($fields, $from, $where, $params);
    $teachersinstancereporttable->out(TABLE_SHOW_ALL_PAGE_SIZE, false);

    echo $OUTPUT->footer();

}
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/* else {
    // The table is being downloaded.

    // Headers.
    $teachersinstancereporttable->define_headers([
        get_string('firstname'),
        get_string('lastname'),
        get_string('email'),
        get_string('bookinginstance', 'mod_booking'),
        get_string('titleprefix', 'mod_booking'),
        get_string('course'),
        get_string('optiondatestart', 'mod_booking'),
        get_string('optiondateend', 'mod_booking'),
        get_string('duration:minutes', 'mod_booking'),
        get_string('duration:units', 'mod_booking', $unitlength)
    ]);

    // Columns.
    $teachersinstancereporttable->define_columns([
        'firstname',
        'lastname',
        'email',
        'instancename',
        'titleprefix',
        'optionname',
        'coursestarttime',
        'courseendtime',
        'duration_min',
        'duration_units'
    ]);

    // Header column.
    $teachersinstancereporttable->define_header_column('optionname');

    // SQL query. The subselect will fix the "Did you remember to make the first column something...
    // ...unique in your call to get_records?" bug.
    $fields = "bodt.id,
        u.firstname, u.lastname, u.email,
        b.name instancename,
        bo.text optionname,
        bod.coursestarttime, bod.courseendtime,
        ROUND((bod.courseendtime - bod.coursestarttime)/60) as duration_min,
        ROUND(((bod.courseendtime - bod.coursestarttime)/60) / :unitlength, 2) as duration_units";

    $from = "{booking_optiondates_teachers} bodt
            JOIN {booking_optiondates} bod
            ON bod.id = bodt.optiondateid
            JOIN {booking_options} bo
            on bo.id = bod.optionid
            JOIN {user} u
            on u.id = bodt.userid
            JOIN {booking} b
            ON b.id = bo.bookingid";

    $where = "bodt.userid = :teacherid
            AND bod.coursestarttime >= :filterstartdate
            AND bod.courseendtime <= :filterenddate
            ORDER BY bod.coursestarttime ASC";

    // Set the SQL filtering params now.
    $params = [
        'unitlength' => (int) $unitlength,
        'teacherid' => $teacherid,
        'filterstartdate' => (int) get_user_preferences('unitsreport_filterstartdate'),
        'filterenddate' => (int) get_user_preferences('unitsreport_filterenddate')
    ];

    // Now build the table.
    $teachersinstancereporttable->set_sql($fields, $from, $where, $params);
    $teachersinstancereporttable->setup();
    $teachersinstancereporttable->pagesize(50, 500);
    $teachersinstancereporttable->query_db(500);
    $teachersinstancereporttable->build_table();
    $teachersinstancereporttable->finish_output();
} */
