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

use mod_booking\singleton_service;
use mod_booking\table\optiondates_teachers_table;

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT); // Course module id.
$optionid = required_param('optionid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_course_login($course, true, $cm);

$urlparams = [
    'cmid' => $cmid,
    'optionid' => $optionid,
];

$context = context_module::instance($cmid);
$PAGE->set_context($context);

$baseurl = new moodle_url('/mod/booking/optiondates_teachers_report.php', $urlparams);
$PAGE->set_url($baseurl);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

if (
    (has_capability('mod/booking:updatebooking', $context)
    || has_capability('mod/booking:addeditownoption', $context)
    || has_capability('mod/booking:viewreports', $context)
    || has_capability('mod/booking:limitededitownoption', $context)) == false
) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

$settings = singleton_service::get_instance_of_booking_option_settings($optionid);

$bookingoptionname = $settings->text;

// File name and sheet name.
$fileandsheetname = $bookingoptionname . "_teachers";

// Important: We have to add $optionid here, so the cache gets not overwritten by the next table.
$optiondatesteacherstable = new optiondates_teachers_table('optiondates_teachers_table_' . $optionid);

$optiondatesteacherstable->is_downloading($download, $fileandsheetname, $fileandsheetname);

$tablebaseurl = $baseurl;
$tablebaseurl->remove_params('page');
$optiondatesteacherstable->define_baseurl($tablebaseurl);
$optiondatesteacherstable->sortable(false);

$optiondatesteacherstable->show_download_buttons_at([TABLE_P_BOTTOM]);

// Table will be shown normally.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('optiondatesteachersreport', 'mod_booking'));

$instancereportsurl = new moodle_url('/mod/booking/teachers_instance_report.php', ['cmid' => $cmid]);

// Dismissible alert containing the description of the report.
echo '<div class="alert alert-secondary alert-dismissible fade show" role="alert">' .
    get_string('optiondatesteachersreport_desc', 'mod_booking') .
    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">&times;</span>
    </button>
</div>';

echo get_string('linktoteachersinstancereport', 'mod_booking', $instancereportsurl->out());

// Show header with booking option name (and prefix if present).
if (!empty($settings->titleprefix)) {
    $bookingoptionname = $settings->titleprefix . " - " . $bookingoptionname;
}
echo "<h2 class='mt-5'>$bookingoptionname</h2>";

$columns = [
    'optiondate' => get_string('optiondate', 'mod_booking'),
    'teacher' => get_string('teacher', 'mod_booking'),
    'reason' => get_string('reason', 'mod_booking'),
    'deduction' => get_string('deduction', 'mod_booking'),
    'reviewed' => get_string('reviewed', 'mod_booking'),
];

$columns['edit'] = get_string('edit');

// Header.
$optiondatesteacherstable->define_headers(array_values($columns));

// Columns.
$optiondatesteacherstable->define_columns(array_keys($columns));

// Header column.
$optiondatesteacherstable->define_header_column('optiondate');

// Table cache.
$optiondatesteacherstable->define_cache('mod_booking', 'cachedteachersjournal');

$downloadbaseurl = new moodle_url('/mod/booking/download_optiondates_teachers_report.php');
$optiondatesteacherstable->define_baseurl($downloadbaseurl);
$optiondatesteacherstable->showdownloadbutton = true;

// SQL query. The subselect will fix the "Did you remember to make the first column something...
// ...unique in your call to get_records?" bug.
$fields = "s.optiondateid, s.text, s.optionid, s.coursestarttime, s.courseendtime, s.reason, s.reviewed, s.teachers";
$from = "(
    SELECT bod.id optiondateid, bo.text, bod.optionid, bod.coursestarttime, bod.courseendtime, bod.reason, bod.reviewed, " .
    $DB->sql_group_concat('u.id', ',', 'u.id') . " teachers
    FROM {booking_optiondates} bod
    LEFT JOIN {booking_optiondates_teachers} bodt
    ON bodt.optiondateid = bod.id
    LEFT JOIN {booking_options} bo
    ON bo.id = bod.optionid
    LEFT JOIN {user} u
    ON u.id = bodt.userid
    WHERE bod.optionid = :optionid
    GROUP BY bod.id, bo.text, bod.optionid, bod.coursestarttime, bod.courseendtime
    ORDER BY bod.coursestarttime ASC
    ) s";
$where = "1=1";
$params = ['optionid' => $optionid];

// We only have 3 columns, so no need to collapse anything.
$optiondatesteacherstable->collapsible(false);

// Now build the table.
$optiondatesteacherstable->set_sql($fields, $from, $where, $params);

$optiondatesteacherstable->tabletemplate = 'mod_booking/optiondatesteacherstable_list';

$optiondatesteacherstable->out(TABLE_SHOW_ALL_PAGE_SIZE, false);

echo $OUTPUT->footer();
