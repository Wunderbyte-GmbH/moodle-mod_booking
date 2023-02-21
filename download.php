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
 * Baseurl of wunderbyte_table will always point to this file for download.
 * @copyright 2023 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_wunderbyte_table\wunderbyte_table;
use mod_booking\singleton_service;

require_once("../../config.php");

global $CFG, $PAGE;

require_login();

require_once($CFG->dirroot . '/local/wunderbyte_table/classes/wunderbyte_table.php');

$download = optional_param('download', '', PARAM_ALPHA);
$encodedtable = optional_param('encodedtable', '', PARAM_RAW);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/download.php');

$lib = wunderbyte_table::decode_table_settings($encodedtable);

$table = new $lib['classname']($lib['uniqueid']);

$table->update_from_json($lib);

$bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($table->cmid);
$instancename = $bookingsettings->name;

// Replace special characters to prevent errors.
$instancename = str_replace(' ', '_', $instancename); // Replaces all spaces with underscores.
$instancename = preg_replace('/[^A-Za-z0-9\_]/', '', $instancename); // Removes special chars.
$instancename = preg_replace('/\_+/', '_', $instancename); // Replace multiple underscores with exactly one.
$instancename = format_string($instancename);

// File name and sheet name.
$fileandsheetname = "download_of_" . $instancename;
$table->is_downloading($download, $fileandsheetname, $fileandsheetname);

// TODO.
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/*
$booking = $table->booking;
list($columns, $headers, $userprofilefields) = $booking->get_fields();

$table->define_columns($columns);
$table->define_headers($headers); */

$table->headers = [];
$table->columns = [];

$table->define_headers([
    get_string('optionidentifier', 'mod_booking'),
    get_string('titleprefix', 'mod_booking'),
    get_string('bookingoption', 'mod_booking'),
    get_string('description', 'mod_booking'),
    get_string('teachers', 'mod_booking'),
    get_string('dates', 'mod_booking'),
    get_string('dayofweektime', 'mod_booking'),
    get_string('location', 'mod_booking'),
    get_string('institution', 'mod_booking'),
    get_string('course', 'core'),
    get_string('minanswers', 'mod_booking'),
    get_string('bookings', 'mod_booking'),
]);

$table->define_columns([
    'identifier',
    'titleprefix',
    'text',
    'description',
    'teacher',
    'showdates',
    'dayofweektime',
    'location',
    'institution',
    'course',
    'minanswers',
    'bookings',
]);

$table->printtable(20, true);
