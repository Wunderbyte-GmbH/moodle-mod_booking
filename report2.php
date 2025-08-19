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

use mod_booking\booking;
use mod_booking\option\dates_handler;
use mod_booking\output\booked_users;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use mod_booking\output\renderer;

global $PAGE, $SITE;

if (!get_config('booking', 'bookingstracker') || !wb_payment::pro_version_is_activated()) {
    require_login(1, false);
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php'));
    echo "<div class='alert alert-warning'>" . get_string('error:bookingstrackernotactivated', 'mod_booking') . "</div>";
}

$PAGE->requires->js_call_amd('mod_booking/bookingjslib', 'init');

$optiondateid = optional_param('optiondateid', 0, PARAM_INT);
$optionid = optional_param('optionid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$viewtype = optional_param('viewtype', 'options', PARAM_RAW); // Can be 'options' or 'answers'.

$ticketicon = '<i class="fa fa-fw fa-sm fa-ticket" aria-hidden="true"></i>&nbsp;';
$linkicon = '<i class="fa fa-fw fa-xs fa-external-link" aria-hidden="true"></i>&nbsp;';
$divider = "<span class='report2-nav-divider'>▸</span>";

$r2syscontext = context_system::instance();
$r2syscap = has_capability('mod/booking:managebookedusers', $r2syscontext);
$r2systemurl = new moodle_url('/mod/booking/report2.php');

if (!empty($optiondateid)) {
    // We are in optiondate (session) scope.
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid, 'optiondateid' => $optiondateid]));
    $scopes = ['system', 'course', 'instance', 'option', 'optiondate'];
    $scope = 'optiondate'; // A specific date of a booking option.
    $scopeid = $optiondateid;
    if (empty($optionid)) {
        $optionid = $DB->get_field('booking_optiondates', 'optionid', ['id' => $optiondateid]);
    }
    $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $cmid = $optionsettings->cmid;
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $courseid = $course->id;
    require_course_login($course, false, $cm);
    $urlparams = ["optionid" => $optionid, "optiondateid" => $optiondateid]; // For PAGE url.

    // Define scope contexts.
    $r2coursecontext = context_course::instance($courseid);
    $r2instancecontext = context_module::instance($cmid);

    // Check capabilities.
    if (
        (
            has_capability('mod/booking:updatebooking', $r2instancecontext)
            || (
                has_capability('mod/booking:addeditownoption', $r2instancecontext)
                && booking_check_if_teacher($optionid)
            )
        ) == false
    ) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
        echo get_string('nopermissiontoaccesspage', 'mod_booking');
        echo $OUTPUT->footer();
        die();
    }

    $r2courseurl = new moodle_url('/mod/booking/report2.php', ['courseid' => $courseid]);
    $r2instanceurl = new moodle_url('/mod/booking/report2.php', ['cmid' => $cmid]);
    $r2optionurl = new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid]);

    // To create the correct links.
    $r2coursecap = has_capability('mod/booking:managebookedusers', $r2coursecontext);
    $r2instancecap = has_capability('mod/booking:managebookedusers', $r2instancecontext);

    // Get the optiondate record from DB.
    $optiondate = $DB->get_record('booking_optiondates', ['id' => $optiondateid]);
    $prettydatestring = dates_handler::prettify_optiondates_start_end(
        $optiondate->coursestarttime,
        $optiondate->courseendtime,
        current_language(),
        true
    );

    // We only show links, if we have the matching capabilities.
    $a = new stdClass();
    $a->scopestring = get_string('report2labeloptiondate', 'mod_booking');
    $a->title = $optionsettings->get_title_with_prefix() . " - " . $prettydatestring;
    $heading = get_string('managebookedusers_heading', 'mod_booking', $a);

    $navhtml = "<div class='report2-nav mb-3 flex-wrap-container'>" .
        ($r2syscap ? "<a href='{$r2systemurl}' class='report2-system-border'>" :
            "<span class='report2-system-border'>") .
        $ticketicon . booking::shorten_text($SITE->fullname) .
        ($r2syscap ? "</a>" : "</span>") .
        $divider .
        ($r2coursecap ? "<a href='{$r2courseurl}' class='report2-course-border'>" :
            "<span class='report2-course-border'>") .
        $ticketicon . booking::shorten_text($course->fullname) .
        ($r2coursecap ? "</a>" : "</span>") .
        $divider .
        ($r2instancecap ? "<a href='{$r2instanceurl}' class='report2-instance-border'>" :
            "<span class='report2-instance-border'>") .
        $ticketicon . booking::shorten_text($bookingsettings->name) .
        ($r2instancecap ? "</a>" : "</span>") .
        $divider .
        "<a href='{$r2optionurl}' class='report2-option-border'>" .
        $ticketicon . booking::shorten_text($optionsettings->get_title_with_prefix()) .
        "</a>";

    // Create a navigation dropdown for all optiondates (sessions) of the booking option.
    $optiondates = $optionsettings->sessions;
    if (!empty($optiondates) && count($optiondates) > 0) {
        $data['optiondatesexist'] = true;
        foreach ($optiondates as &$optiondate) {
            $optiondate = (array) $optiondate;
            $optiondate['prettydate'] = dates_handler::prettify_optiondates_start_end(
                $optiondate['coursestarttime'],
                $optiondate['courseendtime'],
                current_language(),
                true
            );
            $dateurl = new moodle_url('/mod/booking/report2.php', [
                'optionid' => $optionid,
                'optiondateid' => $optiondate['id'],
            ]);
            $optiondate['dateurl'] = $dateurl->out(false);
        }
        $firstentry['prettydate'] = get_string('choosesession', 'mod_booking');
        $firstentry['dateurl'] = $PAGE->url; // The current page.
        array_unshift($optiondates, $firstentry);
        $data['optiondates'] = array_values((array) $optiondates);
        // Now we just append the dropdown to the navigation HTML.
        $navhtml .= $divider . $OUTPUT->render_from_template('mod_booking/report/navigation_dropdown', $data);
    }
    $navhtml .= "</div>";
} else if (!empty($optionid)) {
    // We are in option scope.
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid]));
    $scopes = ['system', 'course', 'instance', 'option'];
    $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $cmid = $optionsettings->cmid;
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $courseid = $course->id;
    require_course_login($course, false, $cm);
    $scope = 'option'; // If we have an optionid, we want the report for this booking option.
    $scopeid = $optionid;
    $urlparams = ["optionid" => $optionid]; // For PAGE url.

    $r2courseurl = new moodle_url('/mod/booking/report2.php', ['courseid' => $courseid]);
    $r2instanceurl = new moodle_url('/mod/booking/report2.php', ['cmid' => $cmid]);
    $r2optionurl = new moodle_url('/mod/booking/view.php', [
        'id' => $cmid,
        'optionid' => $optionid,
        'whichview' => 'showonlyone',
    ]);

    // Define scope contexts.
    $r2coursecontext = context_course::instance($courseid);
    $r2instancecontext = context_module::instance($cmid);

    // Check capabilities.
    if (
        (
            has_capability('mod/booking:updatebooking', $r2instancecontext)
            || (
                has_capability('mod/booking:addeditownoption', $r2instancecontext)
                && booking_check_if_teacher($optionid)
            )
        ) == false
    ) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
        echo get_string('nopermissiontoaccesspage', 'mod_booking');
        echo $OUTPUT->footer();
        die();
    }

    // To create the correct links.
    $r2coursecap = has_capability('mod/booking:managebookedusers', $r2coursecontext);
    $r2instancecap = has_capability('mod/booking:managebookedusers', $r2instancecontext);

    // We only show links, if we have the matching capabilities.
    $a = new stdClass();
    $a->scopestring = get_string('report2labeloption', 'mod_booking');
    $a->title = $optionsettings->get_title_with_prefix();
    $heading = get_string('managebookedusers_heading', 'mod_booking', $a);

    $navhtml = "<div class='report2-nav mb-3 flex-wrap-container'>" .
        ($r2syscap ? "<a href='{$r2systemurl}' class='report2-system-border'>" :
            "<span class='report2-system-border'>") .
        $ticketicon . booking::shorten_text($SITE->fullname) .
        ($r2syscap ? "</a>" : "</span>") .
        $divider .
        ($r2coursecap ? "<a href='{$r2courseurl}' class='report2-course-border'>" :
            "<span class='report2-course-border'>") .
        $ticketicon . booking::shorten_text($course->fullname) .
        ($r2coursecap ? "</a>" : "</span>") .
        $divider .
        ($r2instancecap ? "<a href='{$r2instanceurl}' class='report2-instance-border'>" :
            "<span class='report2-instance-border'>") .
        $ticketicon . booking::shorten_text($bookingsettings->name) .
        ($r2instancecap ? "</a>" : "</span>") .
        $divider .
        "<a href='{$r2optionurl}' target='_blank' class='report2-option-border'>" .
        $linkicon . booking::shorten_text($optionsettings->get_title_with_prefix()) .
        "</a>";

    // Create a navigation dropdown for all optiondates (sessions) of the booking option.
    $optiondates = $optionsettings->sessions;
    if (!empty($optiondates) && count($optiondates) > 0) {
        $data['optiondatesexist'] = true;
        foreach ($optiondates as &$optiondate) {
            $optiondate = (array) $optiondate;
            $optiondate['prettydate'] = dates_handler::prettify_optiondates_start_end(
                $optiondate['coursestarttime'],
                $optiondate['courseendtime'],
                current_language(),
                true
            );
            $dateurl = new moodle_url('/mod/booking/report2.php', [
                'optionid' => $optionid,
                'optiondateid' => $optiondate['id'],
            ]);
            $optiondate['dateurl'] = $dateurl->out(false);
        }
        $firstentry['prettydate'] = get_string('choosesession', 'mod_booking');
        $firstentry['dateurl'] = $PAGE->url; // The current page.
        array_unshift($optiondates, $firstentry);
        $data['optiondates'] = array_values((array) $optiondates);
        // Now we just append the dropdown to the navigation HTML.
        $navhtml .= $divider . $OUTPUT->render_from_template('mod_booking/report/navigation_dropdown', $data);
    }
    $navhtml .= "</div>";
} else if (!empty($cmid)) {
    // We are in instance scope.
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php', ['cmid' => $cmid]));
    $scopes = ['system', 'course', 'instance'];
    $scope = 'instance';
    if ($viewtype == 'answers') {
        $scope .= 'answers'; // Non-aggregated, individual answers view.
    }
    $scopeid = $cmid;
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $courseid = $course->id;
    require_course_login($course, false, $cm);
    $urlparams = ["cmid" => $cmid]; // For PAGE url.

    $r2courseurl = new moodle_url('/mod/booking/report2.php', ['courseid' => $courseid]);
    $r2instanceurl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);

    // Define scope contexts.
    $r2coursecontext = context_course::instance($courseid);
    $r2instancecontext = context_module::instance($cmid);

    // To create the correct links.
    $r2coursecap = has_capability('mod/booking:managebookedusers', $r2coursecontext);
    $r2instancecap = has_capability('mod/booking:managebookedusers', $r2instancecontext);

    require_capability('mod/booking:managebookedusers', $r2instancecontext);

    // We only show links, if we have the matching capabilities.
    $a = new stdClass();
    $a->scopestring = get_string('report2labelinstance', 'mod_booking');
    $a->title = $bookingsettings->name;
    $heading = get_string('managebookedusers_heading', 'mod_booking', $a);

    $navhtml =
        ($r2syscap ? "<a href='{$r2systemurl}' class='report2-system-border'>" :
            "<span class='report2-system-border'>") .
        $ticketicon . booking::shorten_text($SITE->fullname) .
        ($r2syscap ? "</a>" : "</span>") .
        $divider .
        ($r2coursecap ? "<a href='{$r2courseurl}' class='report2-course-border'>" :
            "<span class='report2-course-border'>") .
        $ticketicon . booking::shorten_text($course->fullname) .
        ($r2coursecap ? "</a>" : "</span>") .
        $divider .
        "<a href='{$r2instanceurl}' target='_blank' class='report2-instance-border'>" .
        $linkicon . booking::shorten_text($bookingsettings->name) .
        "</a>";
} else if (!empty($courseid)) {
    // We are in course scope.
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php', ['courseid' => $courseid]));
    $scopes = ['system', 'course'];
    $scope = 'course'; // A moodle course containing (a) booking option(s).
    if ($viewtype == 'answers') {
        $scope .= 'answers'; // Non-aggregated, individual answers view.
    }
    $scopeid = $courseid;
    $course = get_course($courseid);
    require_course_login($course, false);
    $urlparams = ["courseid" => $courseid]; // For PAGE url.

    $r2courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);

    // Define scope contexts.
    $r2coursecontext = context_course::instance($courseid);

    // To create the correct links.
    $r2coursecap = has_capability('mod/booking:managebookedusers', $r2coursecontext);

    require_capability('mod/booking:managebookedusers', $r2coursecontext);

    // We only show links, if we have the matching capabilities.
    $a = new stdClass();
    $a->scopestring = get_string('report2labelcourse', 'mod_booking');
    $a->title = $course->fullname;
    $heading = get_string('managebookedusers_heading', 'mod_booking', $a);

    $navhtml =
        ($r2syscap ? "<a href='{$r2systemurl}' class='report2-system-border'>" :
            "<span class='report2-system-border'>") .
        $ticketicon . booking::shorten_text($SITE->fullname) .
        ($r2syscap ? "</a>" : "</span>") .
        $divider .
        "<a href='$r2courseurl' target='_blank' class='report2-course-border'>" .
        $linkicon . booking::shorten_text($course->fullname) .
        "</a>";
} else {
    // We are in system scope.
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php'));
    $scopes = ['system'];
    require_login(1, false);
    $scope = 'system'; // The whole site.
    if ($viewtype == 'answers') {
        $scope .= 'answers'; // Non-aggregated, individual answers view.
    }
    $scopeid = 0;
    $urlparams = []; // For PAGE url.

    $r2systemurl = new moodle_url('/');

    require_capability('mod/booking:managebookedusers', $r2syscontext);

    // We only show links, if we have the matching capabilities.
    $a = new stdClass();
    $a->scopestring = get_string('report2labelsystem', 'mod_booking');
    $a->title = $SITE->fullname;
    $heading = get_string('managebookedusers_heading', 'mod_booking', $a);

    $navhtml =
        "<a href='$r2systemurl' target='_blank' class='report2-system-border'>" .
        $linkicon . booking::shorten_text($SITE->fullname) .
        "</a>";
}

$url = new moodle_url('/mod/booking/report2.php', $urlparams);
$PAGE->set_url($url);

echo $OUTPUT->header();

// Add the navigation here.
echo "<div class='mt-3 mb-5'>$navhtml</div>";

// Title of the page for the current scope.
echo $OUTPUT->heading("<div class='mb-5'>$ticketicon $heading</div>");

// Navigation stylings cannot be done in styles.css because of string localization.
echo booking::generate_localized_css_for_navigation_labels('report2', $scopes);

// For option scope and optiondate scope, there is no switch.
if (empty($optionid) && empty($optiondateid)) {
    // Switch to turn booking of anyone ON or OFF.
    if ($viewtype == 'answers') {
        set_user_preference('bookingstrackerviewtype', 'answers');
        // Show button to switch back to aggregated options view.
        $url = new moodle_url(
            '/mod/booking/report2.php',
            [
                'optionid' => $optionid,
                'optiondateid' => $optiondateid,
                'cmid' => $cmid,
                'courseid' => $courseid,
                'viewtype' => 'options',
            ]
        );
        echo '<a class="btn btn-sm btn-primary" href="' . $url . '">' .
            '<i class="fa fa-object-group" aria-hidden="true"></i>&nbsp;' .
            get_string('bookingstrackerswitchviewtypetooptions', 'mod_booking') . '</a>';
    } else {
        set_user_preference('bookingstrackerviewtype', 'options');
        // Show button to switch back to non-aggregated separate booking answers.
        $url = new moodle_url(
            '/mod/booking/report2.php',
            [
                'optionid' => $optionid,
                'optiondateid' => $optiondateid,
                'cmid' => $cmid,
                'courseid' => $courseid,
                'viewtype' => 'answers',
            ]
        );
        echo '<a class="btn btn-sm btn-primary" href="' . $url . '">' .
            '<i class="fa fa-object-ungroup" aria-hidden="true"></i>&nbsp;' .
            get_string('bookingstrackerswitchviewtypetoanswers', 'mod_booking') . '</a>';
    }
}

// Buttons, we only show in option scope can be added here.
if (!empty($optionid) && empty($optiondateid)) {
    $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $cmid = $optionsettings->cmid;
    $context = context_module::instance($cmid);
    if (
        has_capability('mod/booking:bookforothers', $context)
        && (
            has_capability('mod/booking:subscribeusers', $context)
            || booking_check_if_teacher($optionsettings)
        )
    ) {
        $url = new moodle_url(
            '/mod/booking/subscribeusers.php',
            ['id' => $cmid, 'optionid' => $optionid]
        );
        echo html_writer::link($url, '<i class="fa fa-users fa-fw" aria-hidden="true"></i>&nbsp;' .
                get_string('bookotherusers', 'booking'), ['class' => 'btn btn-primary btn-sm']);
    }
}

// Now we render the booked users for the provided scope.
$data = new booked_users(
    $scope,
    $scopeid,
    true, // Booked users.
    true, // Users on waiting list.
    true, // Reserved answers (e.g. in shopping cart).
    true, // Users on notify list.
    true, // Deleted users.
    true, // Booking history.
    $cmid
);
/** @var renderer $renderer */
$renderer = $PAGE->get_renderer('mod_booking');
echo $renderer->render_booked_users($data);

echo $OUTPUT->footer();
