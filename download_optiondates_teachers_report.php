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

require_once("../../config.php");

global $CFG, $PAGE;

require_login();

require_once($CFG->dirroot . '/local/wunderbyte_table/classes/wunderbyte_table.php');

$download = optional_param('download', '', PARAM_ALPHA);
$encodedtable = optional_param('encodedtable', '', PARAM_RAW);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/download_optiondates_teachers_report.php');

// Table will be of an instance of the child class extending wunderbyte_table.
/** @var optiondates_teachers_table $table */
$table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtable);

// Re-initialize, otherwise the defining will not work!
$table->headers = [];
$table->columns = [];

// Headers.
$table->define_headers([
    get_string('name'),
    get_string('optiondate', 'mod_booking'),
    get_string('reason', 'mod_booking'),
    get_string('teacher', 'mod_booking'),
    get_string('reviewed', 'mod_booking'),
]);

// Columns.
$table->define_columns([
    'optionname',
    'optiondate',
    'reason',
    'teacher',
    'reviewed',
]);

// File name and sheet name.
$fileandsheetname = "teaching_report";
$table->is_downloading($download, $fileandsheetname, $fileandsheetname);

$table->printtable(20, true);
