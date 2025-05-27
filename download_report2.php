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
use mod_booking\table\manageusers_table;

require_once("../../config.php");

global $CFG, $PAGE;

require_login();

require_once($CFG->dirroot . '/local/wunderbyte_table/classes/wunderbyte_table.php');

$download = optional_param('download', '', PARAM_ALPHA);
$encodedtable = optional_param('encodedtable', '', PARAM_RAW);
$scope = optional_param('scope', '', PARAM_TEXT);
$statusparam = optional_param('statusparam', '', PARAM_INT); // Value as stored in field 'waitinglist'.

$syscontext = context_system::instance();
$PAGE->set_context($syscontext);
$PAGE->set_url('/download_report2.php');

// Table will be of an instance of the child class extending wunderbyte_table.
/** @var manageusers_table $table */
$table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtable);

// Re-initialize, otherwise the defining will not work!
$table->headers = [];
$table->columns = [];

switch ($scope) {
    case 'optiondate':
        $table->define_headers([
            get_string('lastname'),
            get_string('firstname'),
            get_string('email'),
            get_string('presence', 'mod_booking'),
            get_string('notes', 'mod_booking'),
        ]);
        // Columns.
        $table->define_columns([
            'lastname',
            'firstname',
            'email',
            'status',
            'notes',
        ]);
        break;
    case 'option':
        // Headers.
        $table->define_headers([
            get_string('lastname'),
            get_string('firstname'),
            get_string('email'),
            get_string('presencecount', 'mod_booking'),
        ]);
        // Columns.
        $table->define_columns([
            'lastname',
            'firstname',
            'email',
            'presencecount',
        ]);
        break;
    default:
        // Headers.
        $table->define_headers([
            get_string('titleprefix', 'mod_booking'),
            get_string('bookingoption', 'mod_booking'),
            get_string('answerscount', 'mod_booking'),
            get_string('presencecount', 'mod_booking'),
        ]);
        // Columns.
        $table->define_columns([
            'titleprefix',
            'text', // This is the booking option name (without prefix).
            'answerscount',
            'presencecount',
        ]);
        break;
}

// File name and sheet name.
$fileandsheetname = "download"; // Todo: Better name depending on scope etc.
$table->is_downloading($download, $fileandsheetname, $fileandsheetname);

$table->printtable(20, true);
