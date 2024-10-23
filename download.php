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
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_wunderbyte_table\wunderbyte_table;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;

require_once("../../config.php");

global $CFG, $PAGE;

require_login();

require_once($CFG->dirroot . '/local/wunderbyte_table/classes/wunderbyte_table.php');

$cmid = required_param('cmid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$encodedtable = optional_param('encodedtable', '', PARAM_RAW);

$context = context_system::instance();
$PAGE->set_context($context);
$downloadurl = new moodle_url('/mod/booking/download.php', ['cmid' => $cmid]);
$PAGE->set_url($downloadurl);

$booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

/** @var bookingoptions_wbtable $table */
$table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtable);

$bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
$instancename = $bookingsettings->name;

// Replace special characters to prevent errors.
$instancename = str_replace(' ', '_', $instancename); // Replaces all spaces with underscores.
$instancename = preg_replace('/[^A-Za-z0-9\_]/', '', $instancename); // Removes special chars.
$instancename = preg_replace('/\_+/', '_', $instancename); // Replace multiple underscores with exactly one.
$instancename = format_string($instancename);

// File name and sheet name.
$fileandsheetname = "download_of_" . $instancename;
$table->is_downloading($download, $fileandsheetname, $fileandsheetname);

$table->headers = [];
$table->columns = [];

[$headers, $columns] = $booking->get_bookingoptions_fields(true); // Param needs to be true for download!

if (!empty($headers)) {
    $table->define_headers($headers);
}
if (!empty($columns)) {
    $table->define_columns($columns);
}

$table->printtable(20, true);
