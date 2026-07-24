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
 * Baseurl for download of optiondates_teachers_report.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers\booking_answers;
use mod_booking\table\manageusers_table;

require_once("../../config.php");

global $CFG, $PAGE;

require_login();

require_once($CFG->dirroot . '/local/wunderbyte_table/classes/wunderbyte_table.php');

$download = optional_param('download', '', PARAM_ALPHA);
$encodedtable = optional_param('encodedtable', '', PARAM_RAW);
$scope = optional_param('scope', '', PARAM_TEXT);
$statusparam = optional_param('statusparam', '', PARAM_INT); // Value as stored in field 'waitinglist'.
$scopeid = optional_param('scopeid', 0, PARAM_INT); // Needed to resolve the booking instance settings.

$syscontext = context_system::instance();
$PAGE->set_context($syscontext);
$PAGE->set_url('/download_report2.php');

$ba = new booking_answers();
/** @var \mod_booking\booking_answers\scope_base $class */
$class = $ba->return_class_for_scope($scope);

// Server-side re-check of the capability which also gates the download button:
// downloading the responses (personal data of the booked users) requires
// mod/booking:downloadresponses in the context of the requested scope.
if (!$class->has_capability_in_scope($scopeid, 'mod/booking:downloadresponses')) {
    throw new required_capability_exception($syscontext, 'mod/booking:downloadresponses', 'nopermissions', '');
}

// Table will be of an instance of the child class extending wunderbyte_table.
/** @var manageusers_table $table */
$table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtable);

// File name and sheet name.
$fileandsheetname = "{$scope}_report";
$table->is_downloading($download, $fileandsheetname, $fileandsheetname);

// Re-initialize, otherwise the defining will not work!
$table->headers = [];
$table->columns = [];

// The download columns can be configured per booking instance
// via the setting "Manage responses - Download" (reportfields).
$columns = $class->return_cols_for_download($statusparam, $scopeid);
$table->define_headers(array_values($columns));
$table->define_columns(array_keys($columns));

$table->printtable(20, true);
