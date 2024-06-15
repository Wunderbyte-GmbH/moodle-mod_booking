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
 * Import excel data - change activity completion to user
 *
 * @package mod_booking
 * @copyright 2015 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once('importexcel_form.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.

$url = new moodle_url('/mod/booking/importexcel.php', ['id' => $id]);
$urlredirect = new moodle_url('/mod/booking/view.php', ['id' => $id]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = singleton_service::get_instance_of_booking_by_cmid($cm->id)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importexceltitle", "booking"));
$PAGE->set_title(format_string($booking->settings->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$mform = new importexcel_form($url);

// Form processing and displaying is done here.
if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($urlredirect, '', 0);
    die();
} else if ($fromform = $mform->get_data()) {
    $csvfile = $mform->get_file_content('excelfile');

    $lines = explode(PHP_EOL, $csvfile);
    $csvarr = [];
    foreach ($lines as $line) {
        $csvarr[] = str_getcsv($line);
    }

    $optionidpos = -1;
    $useridpos = -1;
    $completedpos = -1;

    foreach ($csvarr[0] as $key => $value) {
        switch (trim($value)) {
            case "OptionID":
                $optionidpos = $key;
                break;

            case "UserID":
                $useridpos = $key;
                break;

            case "CourseCompleted":
                $completedpos = $key;
                break;

            default:
                break;
        }
    }

    if ($optionidpos > -1 && $useridpos > -1 && $completedpos > -1) {
        array_shift($csvarr);

        $completion = new \completion_info($course);

        foreach ($csvarr as $line) {
            if (count($line) >= 3) {
                $user = $DB->get_record('booking_answers',
                        ['bookingid' => $cm->instance, 'userid' => $line[$useridpos], 'optionid' => $line[$optionidpos]]);

                if ($user !== false) {
                    $user->completed = $line[$completedpos];
                    $user->timemodified = time();
                    $DB->update_record('booking_answers', $user, false);

                    $countcompleted = $DB->count_records('booking_answers',
                        ['bookingid' => $cm->instance, 'userid' => $line[$useridpos], 'completed' => '1']);

                    if ($completion->is_enabled($cm) && $booking->settings->enablecompletion > $countcompleted) {
                        $completion->update_state($cm, COMPLETION_INCOMPLETE, $user->userid);
                    }

                    if ($completion->is_enabled($cm) && $booking->settings->enablecompletion <= $countcompleted) {
                        $completion->update_state($cm, COMPLETION_COMPLETE, $user->userid);
                    }
                }
            }
        }

        redirect($urlredirect, get_string('importfinished', 'booking'), 5);
    } else {
        redirect($urlredirect, get_string('wrongfile', 'booking'), 5);
    }

    // In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importexceltitle", "booking"), 3, 'helptitle', 'uniqueid');

    $mform->display();
}

echo $OUTPUT->footer();
