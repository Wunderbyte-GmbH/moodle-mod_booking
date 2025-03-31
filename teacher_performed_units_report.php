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
 * Report to track individual performed units of a specific teacher.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\form\teacher_performed_units_report_form;
use mod_booking\table\teacher_performed_units_table;

require_once(__DIR__ . '/../../config.php');

$teacherid = required_param('teacherid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

$filterstartdate = 0;
$filterenddate = 0;

// No guest autologin.
require_login(0, false);

$urlparams = [
    'teacherid' => $teacherid,
];

$context = context_system::instance();
$PAGE->set_context($context);

$baseurl = new moodle_url('/mod/booking/teacher_performed_units_report.php', $urlparams);
$PAGE->set_url($baseurl);

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

if (!$teacherobj = $DB->get_record('user', ['id' => $teacherid])) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('error'), 4);
    echo get_string('error:missingteacherid', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

// File name and sheet name.
$fileandsheetname = "performance_report_" . $teacherobj->firstname . "_" . $teacherobj->lastname;

$teacherperformedunitstable = new teacher_performed_units_table('teacher_performed_units_table');

$teacherperformedunitstable->is_downloading($download, $fileandsheetname, $fileandsheetname);

$tablebaseurl = $baseurl;
$tablebaseurl->remove_params('page');
$teacherperformedunitstable->define_baseurl($tablebaseurl);
$teacherperformedunitstable->sortable(false);
$teacherperformedunitstable->collapsible(false);
$teacherperformedunitstable->show_download_buttons_at([TABLE_P_TOP]);

// Get unit length from config (should be something like 45, 50 or 60 minutes).
if (!$unitlength = get_config('booking', 'educationalunitinminutes')) {
    $unitlength = '60';
}

// Initialize the Moodle form for filtering the table.
$mform = new teacher_performed_units_report_form();
$mform->set_data(['teacherid' => $teacherid]);

// Form processing and displaying is done here.
if ($fromform = $mform->get_data()) {
    if (!empty($fromform->filterstartdate) && !empty($fromform->filterenddate)) {
        $filterstartdate = $fromform->filterstartdate;
        // Add 23:59:59 (in seconds) to the end time.
        $filterenddate = $fromform->filterenddate + 86399;

        // Little hack, so we don't use the dates with downloading.
        set_user_preference('unitsreport_filterstartdate', $filterstartdate);
        set_user_preference('unitsreport_filterenddate', $filterenddate);
    } else {
        debugging('error:missingfilters');
    }
}

if (!$teacherperformedunitstable->is_downloading()) {
    // Table will be shown normally.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('teachingreportfortrainer', 'mod_booking') . ': '
        . $teacherobj->firstname . " " . $teacherobj->lastname);

    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingbooking']);
    echo '<div class="alert alert-secondary alert-dismissible fade show" role="alert">' .
        get_string('teachingreportfortrainer:subtitle', 'mod_booking', $settingsurl->out(false)) .
        '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>';

    // Now show the mform for filtering.
    $mform->display();

    // Headers.
    $teacherperformedunitstable->define_headers([
        get_string('titleprefix', 'mod_booking'),
        get_string('course'),
        get_string('time'),
        get_string('duration:minutes', 'mod_booking'),
        get_string('duration:units', 'mod_booking', $unitlength),
    ]);

    // Columns.
    $teacherperformedunitstable->define_columns([
        'titleprefix',
        'optionname',
        'optiondate',
        'duration_min',
        'duration_units',
    ]);

    // Header column.
    $teacherperformedunitstable->define_header_column('optionname');

    // SQL query. The subselect will fix the "Did you remember to make the first column something...
    // ...unique in your call to get_records?" bug.
    $fields = "s.id, s.prefix, s.optionname, s.coursestarttime, s.courseendtime,
               s.duration_min, s.duration_units";

    $from = "(
            SELECT bodt.id,
                bo.titleprefix prefix,
                bo.text optionname,
                bod.coursestarttime, bod.courseendtime,
                ROUND((cast((bod.courseendtime - bod.coursestarttime) as decimal))/60) as duration_min,
                ROUND(((cast((bod.courseendtime - bod.coursestarttime) as decimal))/60) /
                    cast(:unitlength as decimal), 2) as duration_units
            FROM
                {booking_optiondates_teachers} bodt
                JOIN {booking_optiondates} bod
                ON bod.id = bodt.optiondateid
                JOIN {booking_options} bo
                on bo.id = bod.optionid
            WHERE
                bodt.userid = :teacherid
                AND bod.coursestarttime >= :filterstartdate
                AND bod.courseendtime <= :filterenddate
                ORDER BY bod.coursestarttime ASC
            ) s";

    $where = "1=1";

    // Set the SQL filtering params now.
    $params = [
        'unitlength' => $unitlength,
        'teacherid' => $teacherid,
        'filterstartdate' => $filterstartdate,
        'filterenddate' => $filterenddate,
    ];

    // Now build the table.
    $teacherperformedunitstable->set_sql($fields, $from, $where, $params);
    $teacherperformedunitstable->out(TABLE_SHOW_ALL_PAGE_SIZE, false);

    echo $OUTPUT->footer();
} else {
    // The table is being downloaded.

    // Headers.
    $teacherperformedunitstable->define_headers([
        get_string('firstname'),
        get_string('lastname'),
        get_string('email'),
        get_string('bookinginstance', 'mod_booking'),
        get_string('titleprefix', 'mod_booking'),
        get_string('course'),
        get_string('optiondatestart', 'mod_booking'),
        get_string('optiondateend', 'mod_booking'),
        get_string('duration:minutes', 'mod_booking'),
        get_string('duration:units', 'mod_booking', $unitlength),
    ]);

    // Columns.
    $teacherperformedunitstable->define_columns([
        'firstname',
        'lastname',
        'email',
        'instancename',
        'titleprefix',
        'optionname',
        'coursestarttime',
        'courseendtime',
        'duration_min',
        'duration_units',
    ]);

    // Header column.
    $teacherperformedunitstable->define_header_column('optionname');

    // SQL query. The subselect will fix the "Did you remember to make the first column something...
    // ...unique in your call to get_records?" bug.
    $fields = "bodt.id,
        u.firstname, u.lastname, u.email,
        b.name instancename,
        bo.text optionname,
        bod.coursestarttime, bod.courseendtime,
        ROUND((cast((bod.courseendtime - bod.coursestarttime) as decimal))/60) as duration_min,
        ROUND(((cast((bod.courseendtime - bod.coursestarttime) as decimal))/60) /
            cast(:unitlength as decimal), 2) as duration_units";

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
        'filterenddate' => (int) get_user_preferences('unitsreport_filterenddate'),
    ];

    // Now build the table.
    $teacherperformedunitstable->set_sql($fields, $from, $where, $params);
    $teacherperformedunitstable->setup();
    $teacherperformedunitstable->pagesize(50, 500);
    $teacherperformedunitstable->query_db(500);
    $teacherperformedunitstable->build_table();
    $teacherperformedunitstable->finish_output();
}
