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
require_once("$CFG->libdir/excellib.class.php");

use mod_booking\form\reports_form;
use mod_booking\booking;

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
    $r = $DB->get_records_sql('SELECT bo.text, bo.coursestarttime, bo.courseendtime, bo.location, (select count(mba.id) from {booking_answers} mba where mba.optionid = bo.id)
    numberofusers, mu.firstname, mu.lastname, mu.address, mu.city, mu.country FROM {booking_options} bo left join {booking_teachers} mbt on mbt.optionid = bo.id	left join {user}
     mu on mu.id = mbt.userid  WHERE bo.bookingid = :bookingid AND bo.coursestarttime >= :coursestarttime AND bo.courseendtime <= :courseendtime',
    ['bookingid' => $cm->instance, 'coursestarttime' => $fromform->from, 'courseendtime' => $fromform->to]);

    $rcount = $DB->get_records_sql('SELECT mu.firstname, mu.lastname, count(mu.id) numberofcourses FROM {booking_options} bo left join {booking_teachers} mbt on mbt.optionid =
    bo.id	left join {user} mu on mu.id = mbt.userid  WHERE bo.bookingid = :bookingid AND bo.coursestarttime >= :coursestarttime AND bo.courseendtime <= :courseendtime GROUP BY
    mu.id',
    ['bookingid' => $cm->instance, 'coursestarttime' => $fromform->from, 'courseendtime' => $fromform->to]);

    // Calculate file name.
    $downloadfilename = clean_filename("{$course->shortname} {$booking->settings->name} " . get_string('teachersreport', 'booking') . ".xls");
    // Creating a workbook.
    $workbook = new MoodleExcelWorkbook("-");

    // Sending HTTP headers.
    $workbook->send($downloadfilename);

    $wsname = substr(get_string('teachersreport', 'booking'), 0, 31);
    $myxls = $workbook->add_worksheet($wsname);

    $checklistexportusercolumns = [
      'text' => get_string('bookingoptionname', 'booking'),
      'coursestarttime' => get_string("coursestarttime", "booking"),
      'courseendtime' => get_string("courseendtime", "booking"),
      'location' => get_string("location", "booking"),
      'numberofusers' => get_string('responses', 'booking'),
      'firstname' => get_string("firstname"),
      'lastname' => get_string("lastname"),
      'address' => get_string("address"),
      'city' => get_string("city"),
      'country' => get_string("country")
    ];

    $statiscitcheader = [
      'firstname' => get_string("firstname"),
      'lastname' => get_string("lastname"),
      'numberofcourses' => get_string('numberofcourses', 'booking')
    ];

    // Print names of all the fields.
    $col = 0;
    $row = 0;
    foreach ($checklistexportusercolumns as $field => $headerstr) {
        $myxls->write_string($row, $col++, $headerstr);
    }

    $row++;

    foreach ($r as $key => $value) {
        $col = 0;
        foreach ($value as $ckey => $cvalue) {
            $out = '';

            switch ($ckey) {
                case 'coursestarttime':
                case 'courseendtime':
                    $myxls->write_string($row, $col, userdate($cvalue, get_string('strftimedatetime', 'core_langconfig')));
                    break;

                case 'numberofusers':
                    $myxls->write_number($row, $col, $cvalue);
                    break;

                default:
                    $myxls->write_string($row, $col, $cvalue);
                    break;
            }

            $col++;
        }

        $row++;
    }

    $workbook->close();

    $wsname = substr(get_string('statistics', 'booking'), 0, 31);
    $myxls = $workbook->add_worksheet($wsname);

    $col = 0;
    $row = 0;
    foreach ($statiscitcheader as $field => $headerstr) {
        $myxls->write_string($row, $col++, $headerstr);
    }

    $row++;

    foreach ($rcount as $key => $value) {
        $col = 0;
        foreach ($value as $ckey => $cvalue) {
            $out = '';

            switch ($ckey) {
                case 'numberofcourses':
                    $myxls->write_number($row, $col, $cvalue);
                    break;

                default:
                    $myxls->write_string($row, $col, $cvalue);
                    break;
            }

            $col++;
        }

        $row++;
    }

    $workbook->close();

    exit;
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