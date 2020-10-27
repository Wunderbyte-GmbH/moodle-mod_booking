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
 * Manage bookings for a booking option
 *
 * @package mod_booking
 * @copyright 2012 David Bogner www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("locallib.php");

use mod_booking\form\reports_form;
use mod_booking\booking;
use mod_booking\reports;

$id = required_param('id', PARAM_INT); // Course module id.

$urlparams = array();
$urlparams['id'] = $id;

$url = new moodle_url('/mod/booking/reports.php', $urlparams);

$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);

if (has_capability('mod/booking:updatebooking', $context)) {
    require_capability('mod/booking:updatebooking', $context);
}

$booking = new booking($cm->id);

$mform = new reports_form($url);

// Form processing and displaying is done here
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/booking/view.php', $urlparams));
} else if ($fromform = $mform->get_data()) {
    // In this case you process validated data. $mform->get_data() returns data posted in form.
    $reports = new reports($fromform->reporttype, $fromform->from, $fromform->to, $course, $cm, $booking);
    $reports->getreport();
} else {
    $toform = array();

    // Set default data (if any)
    $mform->set_data($toform);

    $PAGE->set_context($context);
    $PAGE->navbar->add(format_string("Reports"));
    $PAGE->set_title(format_string("Reports"));
    $PAGE->set_heading(format_string("Reports"));

    echo $OUTPUT->header();

    $mform->display();
    echo $OUTPUT->footer();
}