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
 * New report.php for booked users, users on waiting list, deleted users etc.
 *
 * @package     mod_booking
 * @author      Bernhard Fischer
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

use mod_booking\output\booked_users;
use mod_booking\singleton_service;

global $PAGE, $SITE;

// Navigation stylings cannot be done in styles.css because of string localization.
echo '<style>
    .report2-system-border::before {
        content: "' . get_string('report2label_system', 'mod_booking') . '";
        position: absolute;
        top: -18px;
        padding: 0 5px;
        font-weight: 200;
        font-size: small;
        color: #333;
    }
    .report2-course-border::before {
        content: "' . get_string('report2label_course', 'mod_booking') . '";
        position: absolute;
        top: -18px;
        padding: 0 5px;
        font-weight: 200;
        font-size: small;
        color: #333;
    }
    .report2-instance-border::before {
        content: "' . get_string('report2label_instance', 'mod_booking') . '";
        position: absolute;
        top: -18px;
        padding: 0 5px;
        font-weight: 200;
        font-size: small;
        color: #333;
    }
    .report2-option-border::before {
        content: "' . get_string('report2label_option', 'mod_booking') . '";
        position: absolute;
        top: -18px;
        padding: 0 5px;
        font-weight: 200;
        font-size: small;
        color: #333;
    }
</style>';

$optionid = optional_param('optionid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

if (!empty($optionid)) {
    $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $cmid = $optionsettings->cmid;
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $courseid = $course->id;
    require_course_login($course, false, $cm);
    $scope = 'option'; // If we have an optionid, we want the report for this booking option.
    $scopeid = $optionid;
    $urlparams = ["optionid" => $optionid]; // For PAGE url.

    $r2systemurl = new moodle_url('/mod/booking/report2.php');
    $r2courseurl = new moodle_url('/mod/booking/report2.php', ['courseid' => $courseid]);
    $r2instanceurl = new moodle_url('/mod/booking/report2.php', ['cmid' => $cmid]);

    // Define scope contexts.
    $r2syscontext = context_system::instance();
    $r2coursecontext = context_course::instance($courseid);
    $r2instancecontext = context_module::instance($cmid);

    // Capability checks.
    $isteacher = booking_check_if_teacher($optionid);
    if (!($isteacher || has_capability('mod/booking:viewreports', $r2instancecontext))) {
        require_capability('mod/booking:readresponses', $r2instancecontext);
    }
    // To create the correct links.
    $r2syscap = has_capability('mod/booking:managebookedusers', $r2syscontext);
    $r2coursecap = has_capability('mod/booking:managebookedusers', $r2coursecontext);
    $r2instancecap = has_capability('mod/booking:managebookedusers', $r2instancecontext);

    // We only show links, if we have the matching capabilities.
    $heading = get_string('managebookedusers_heading', 'mod_booking');
    $navhtml =
        ($r2syscap ? "<a href='{$r2systemurl}' class='report2-system-border'>" : "") .
        $SITE->fullname .
        ($r2syscap ? "</a>" : "") .
        "&nbsp;&rsaquo;&nbsp;" .
        ($r2coursecap ? "<a href='{$r2courseurl}' class='report2-course-border'>" : "") .
        $course->fullname .
        ($r2coursecap ? "</a>" : "") .
        "&nbsp;&rsaquo;&nbsp;" .
        ($r2instancecap ? "<a href='{$r2instanceurl}' class='report2-instance-border'>" : "") .
        $bookingsettings->name .
        ($r2instancecap ? "</a>" : "") .
        "&nbsp;&rsaquo;&nbsp;" .
        "<span class='report2-option-border'>" .
        $optionsettings->get_title_with_prefix() .
        "</span>";
} else if (!empty($cmid)) {
    $scope = 'instance';
    $scopeid = $cmid;
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $courseid = $course->id;
    require_course_login($course, false, $cm);
    $urlparams = ["cmid" => $cmid]; // For PAGE url.

    $r2systemurl = new moodle_url('/mod/booking/report2.php');
    $r2courseurl = new moodle_url('/mod/booking/report2.php', ['courseid' => $courseid]);

    // Define scope contexts.
    $r2syscontext = context_system::instance();
    $r2coursecontext = context_course::instance($courseid);
    $r2instancecontext = context_module::instance($cmid);

    // To create the correct links.
    $r2syscap = has_capability('mod/booking:managebookedusers', $r2syscontext);
    $r2coursecap = has_capability('mod/booking:managebookedusers', $r2coursecontext);
    $r2instancecap = has_capability('mod/booking:managebookedusers', $r2instancecontext);

    require_capability('mod/booking:managebookedusers', $r2instancecontext);

    // We only show links, if we have the matching capabilities.
    $heading = get_string('managebookedusers_heading', 'mod_booking');
    $navhtml =
        ($r2syscap ? "<a href='{$r2systemurl}' class='report2-system-border'>" : "") .
        $SITE->fullname .
        ($r2syscap ? "</a>" : "") .
        "&nbsp;&rsaquo;&nbsp;" .
        ($r2coursecap ? "<a href='{$r2courseurl}' class='report2-course-border'>" : "") .
        $course->fullname .
        ($r2coursecap ? "</a>" : "") .
        "&nbsp;&rsaquo;&nbsp;" .
        "<span class='report2-instance-border'>" .
        $bookingsettings->name .
        "</span>";
} else if (!empty($courseid)) {
    $scope = 'course'; // A moodle course containing (a) booking option(s).
    $scopeid = $courseid;
    $course = get_course($courseid);
    require_course_login($course, false);
    $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
    $urlparams = ["courseid" => $courseid]; // For PAGE url.
    $context = context_course::instance($courseid);
    require_capability('mod/booking:managebookedusers', $context);
    $heading = "";
} else {
    require_login(0, false);
    $scope = 'system'; // The whole site.
    $scopeid = 0;
    $urlparams = []; // For PAGE url.
    $context = context_system::instance();
    require_capability('mod/booking:managebookedusers', $context);
    $heading = "";
}

$url = new moodle_url('/mod/booking/report2.php', $urlparams);
$PAGE->set_url($url);

echo $OUTPUT->header();

echo $OUTPUT->heading("<div class='mb-5'>" . $heading . "</div>" . "<div class='h4 mt-3 mb-5'>$navhtml</div");

// Now we render the booked users for the provided scope.
$data = new booked_users(
    $scope,
    $scopeid,
    true,
    true,
    true,
    true,
    true
);
$renderer = $PAGE->get_renderer('mod_booking');
echo $renderer->render_booked_users($data);

echo $OUTPUT->footer();
