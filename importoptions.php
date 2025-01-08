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
 * Import options or just add new users from CSV
 *
 * @package mod_booking
 * @copyright 2014 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;
require_once(__DIR__ . '/../../config.php');
require_once("lib.php");
require_once($CFG->libdir . '/completionlib.php');

use mod_booking\form\importoptions_form;
use mod_booking\utils\csv_import;
use moodle_url;
use context_module;
use html_writer;
use mod_booking\importer\bookingoptionsimporter;

global $OUTPUT;

$id = required_param('id', PARAM_INT); // Course Module ID.

$url = new moodle_url('/mod/booking/importoptions.php', ['id' => $id]);
$urlredirect = new moodle_url('/mod/booking/view.php', ['id' => $id]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$groupmode = groups_get_activity_groupmode($cm);
$context = context_module::instance($cm->id);

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importcsvtitle", "booking"));
$booking = singleton_service::get_instance_of_booking_by_cmid((int)$cm->id);
$PAGE->set_title(format_string($booking->settings->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$PAGE->requires->js_call_amd('mod_booking/csvimport', 'init');

$ajaxformdata = array_merge(bookingoptionsimporter::return_ajaxformdata(), ['cmid' => $id]);
$inputform = new \mod_booking\form\csvimport(null, null, 'post', '', [], true, $ajaxformdata);

// Set the form data with the same method that is called when loaded from JS.
// It should correctly set the data for the supplied arguments.
$inputform->set_data_for_dynamic_submission();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');

// Render the form in a specific container, there should be nothing else in the same container.
echo html_writer::div($inputform->render(), '', ['id' => 'mbo_csv_import_form']);

echo $OUTPUT->footer();
