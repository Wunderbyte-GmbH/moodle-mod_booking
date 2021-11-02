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
require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");
require_once('institutioncsv_form.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('courseid', PARAM_INT); // Course Module ID.

$url = new moodle_url('/mod/booking/institutioncsv.php', array('courseid' => $id));
$urlredirect = new moodle_url('/mod/booking/institutions.php', array('courseid' => $id));
$PAGE->set_url($url);

$context = context_course::instance($id);

if (!$course = $DB->get_record("course", array("id" => $id))) {
    print_error('coursemisconf');
}

require_course_login($course, false);

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importcsvtitle", "booking"));
$PAGE->set_title(get_string("importcsvtitle", "booking"));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$mform = new institutioncsv_form($url);

$completion = new \completion_info($course);

// Form processing and displaying is done here.
if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($urlredirect, '', 0);
    die();
} else if ($fromform = $mform->get_data()) {

    $csvfile = $mform->get_file_content('csvfile');

    $lines = explode(PHP_EOL, $csvfile);
    $csvarr = array();
    foreach ($lines as $line) {
        $csvarr[] = str_getcsv($line);
    }

    // Check if CSV is ok.

    if ($csvarr[0][0] == 'Institution') {
        array_shift($csvarr);

        foreach ($csvarr as $line) {

            if (count($line) == 1 && !empty($line[0])) {

                $institution = new stdClass();
                $institution->name = $line[0];
                $institution->course = $id;

                $bid = $DB->insert_record('booking_institutions', $institution, true);
            }
        }

        redirect($urlredirect, get_string('importfinished', 'booking'), 5);
    } else {
        // Not ok, write error!
        redirect($urlredirect, get_string('wrongfile', 'booking'), 5);
    }

    // In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');
    $mform->display();
}

echo $OUTPUT->footer();
