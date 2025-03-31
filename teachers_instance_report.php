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

$urlparams = [
    'cmid' => $cmid,
];

$params = []; // SQL params.

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);
$context = context_module::instance($cm->id);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

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

if (
    !$cmidobj = $DB->get_record_sql(
        "SELECT cm.id FROM {course_modules} cm
    JOIN {modules} m
    ON m.id = cm.module
    WHERE m.name = 'booking' AND cm.id = :cmid",
        ['cmid' => $cmid]
    )
) {
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

$teachersinstancereporttable = new teachers_instance_report_table('teachers_instance_report_table', $bookingid, $cmid);

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
    }
    // Needed, so we can also use the filter for downloading.
    set_user_preference('teachersinstancereport_teacherid', $teacherid);
}

if (!$teachersinstancereporttable->is_downloading()) {
    // Headers.
    $teachersinstancereporttable->define_headers([
        get_string('teacher', 'mod_booking'),
        get_string('email'),
        get_string('sumunits', 'mod_booking'),
        get_string('unitscourses', 'mod_booking'),
        get_string('missinghours', 'mod_booking'),
        get_string('substitutions', 'mod_booking'),
    ]);

    // Columns.
    $teachersinstancereporttable->define_columns([
        'lastname',
        'email',
        'sum_units',
        'units_courses',
        'missinghours',
        'substitutions',
    ]);

    // Header column.
    $teachersinstancereporttable->define_header_column('lastname');

    // Table will be shown normally.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('teachingreportforinstance', 'mod_booking') .
        $bookingsettings->name);

    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingbooking']);
    echo '<div class="alert alert-secondary alert-dismissible fade show" role="alert">' .
        get_string('teachersinstancereport:subtitle', 'mod_booking', $settingsurl->out(false)) .
        '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>';

    // Now show the mform for filtering.
    $mform->display();

    // SQL query.
    $fields = "s.teacherid, s.firstname, s.lastname, s.email";

    $andteacher = '';
    if (!empty($teacherid)) {
        $andteacher = "AND u.id = :teacherid";
        $params['teacherid'] = $teacherid;
    }

    $from = "(
        SELECT DISTINCT u.id teacherid, u.firstname, u.lastname, u.email
        FROM {booking_teachers} bt
        JOIN {user} u
        ON u.id = bt.userid
        WHERE bt.bookingid = :bookingid
        $andteacher
        ORDER BY u.lastname
    ) s";

    $where = "1=1";

    $params['bookingid'] = $bookingid;

    // Now build the table.
    $teachersinstancereporttable->set_sql($fields, $from, $where, $params);
    $teachersinstancereporttable->out(TABLE_SHOW_ALL_PAGE_SIZE, false);

    echo $OUTPUT->footer();
} else {
    // Headers.
    $teachersinstancereporttable->define_headers([
        get_string('lastname'),
        get_string('firstname'),
        get_string('email'),
        get_string('sumunits', 'mod_booking'),
        get_string('unitscourses', 'mod_booking'),
        get_string('missinghours', 'mod_booking'),
        get_string('substitutions', 'mod_booking'),
    ]);

    // Columns.
    $teachersinstancereporttable->define_columns([
        'lastname',
        'firstname',
        'email',
        'sum_units',
        'units_courses',
        'missinghours',
        'substitutions',
    ]);

    // SQL query.
    $fields = "s.teacherid, s.firstname, s.lastname, s.email";

    // When downloading, we use the last set teacherid from preferences.
    $andteacher = '';
    $lastteacherid = get_user_preferences('teachersinstancereport_teacherid');
    if (!empty($lastteacherid)) {
        $andteacher = "AND u.id = :teacherid";
        $params['teacherid'] = $lastteacherid;
    }

    $from = "(
        SELECT DISTINCT u.id teacherid, u.firstname, u.lastname, u.email
        FROM {booking_teachers} bt
        JOIN {user} u
        ON u.id = bt.userid
        WHERE bt.bookingid = :bookingid
        $andteacher
        ORDER BY u.lastname
    ) s";

    $where = "1=1";

    $params['bookingid'] = $bookingid;

    // Now build the table.
    $teachersinstancereporttable->set_sql($fields, $from, $where, $params);

    // The table is being downloaded.
    $teachersinstancereporttable->setup();
    $teachersinstancereporttable->pagesize(50, 500);
    $teachersinstancereporttable->query_db(500);
    $teachersinstancereporttable->build_table();
    $teachersinstancereporttable->finish_output();
}
