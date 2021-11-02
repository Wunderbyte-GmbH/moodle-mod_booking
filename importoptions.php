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
 * @package Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
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
global $OUTPUT;

$id = required_param('id', PARAM_INT); // Course Module ID.

$url = new moodle_url('/mod/booking/importoptions.php', array('id' => $id));
$urlredirect = new moodle_url('/mod/booking/view.php', array('id' => $id));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);
$context = context_module::instance($cm->id);

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importcsvtitle", "booking"));
$booking = new booking($cm->id);
$PAGE->set_title(format_string($booking->settings->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');
$importer = new csv_import($booking);
$mform = new importoptions_form($url, ['importer' => $importer]);

// Form processing and displaying is done here.
if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($urlredirect, '', 0);
    die();
} else if ($fromform = $mform->get_data()) {

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "mod_booking"), 3, 'helptitle', 'uniqueid');
    $continue = $OUTPUT->single_button($urlredirect, get_string("continue"), 'get');
    $csvfile = $mform->get_file_content('csvfile');

    if ($importer->process_data($csvfile, $fromform)) {
        echo $OUTPUT->notification(get_string('importfinished', 'booking'), 'notifysuccess');
        if (!empty($importer->get_line_errors())) {
            $output = get_string('import_partial', 'mod_booking');
            $output .= html_writer::div($importer->get_line_errors());
            echo $OUTPUT->notification($output);
        }
        echo $continue;
    } else {
        // Not ok, write error.
        $output = get_string('import_failed', 'booking');
        $output .= $importer->get_error() . '<br>';
        echo $OUTPUT->notification($output);
        echo $continue;
    }

    // In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');
    $mform->display();
}

echo $OUTPUT->footer();
