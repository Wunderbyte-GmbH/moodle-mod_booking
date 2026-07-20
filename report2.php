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
use mod_booking\output\optiondates_with_entities;
use mod_booking\placeholders\placeholders_info;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use mod_booking\output\renderer;

global $PAGE, $SITE;

if (!get_config('booking', 'bookingstracker') || !wb_payment::pro_version_is_activated()) {
    require_login(1, false);
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php'));
    echo "<div class='alert alert-warning'>" . get_string('error:bookingstrackernotactivated', 'mod_booking') . "</div>";
}

$optiondateid = optional_param('optiondateid', 0, PARAM_INT);
$optionid = optional_param('optionid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$viewtype = optional_param('viewtype', 'options', PARAM_RAW); // Can be 'options' or 'answers'.
$id = optional_param('id', 0, PARAM_INT); // Only kept for compatibility to old report.php.
if (empty($cmid) && !empty($id)) {
    // In case we have no cmid, we use value of 'id' as cmid.
    // The only reason for this is to keep capability with old links to report.php.
    // So we can simply replace report.php?id=X&optionid=Y with report2.php?id=X&optionid=Y.
    $cmid = $id;
}

$ticketicon = '<i class="fa fa-fw fa-sm fa-ticket" aria-hidden="true"></i>&nbsp;';

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
    // Resolve course and cm cheaply and log in BEFORE building the option settings.
    // Constructing booking_option_settings runs format_text() on customfields, which
    // initialises the page theme; if that happens before require_course_login() the
    // subsequent set_course()/set_cm() throws a coding_exception (theme already set).
    $bookingid = $DB->get_field('booking_options', 'bookingid', ['id' => $optionid], MUST_EXIST);
    [$course, $cm] = get_course_and_cm_from_instance($bookingid, 'booking');
    $cmid = $cm->id;
    $courseid = $course->id;
    require_course_login($course, false, $cm);
    $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
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
} else if (!empty($optionid)) {
    // We are in option scope.
    $PAGE->set_url(new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid]));
    $scopes = ['system', 'course', 'instance', 'option'];
    // Resolve course and cm cheaply and log in BEFORE building the option settings.
    // Constructing booking_option_settings runs format_text() on customfields, which
    // initialises the page theme; if that happens before require_course_login() the
    // subsequent set_course()/set_cm() throws a coding_exception (theme already set).
    $bookingid = $DB->get_field('booking_options', 'bookingid', ['id' => $optionid], MUST_EXIST);
    [$course, $cm] = get_course_and_cm_from_instance($bookingid, 'booking');
    $cmid = $cm->id;
    $courseid = $course->id;
    require_course_login($course, false, $cm);
    $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
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
}

$url = new moodle_url('/mod/booking/report2.php', $urlparams);
$PAGE->set_url($url);

// Build the Bootstrap breadcrumb navigation between the scopes.
// Each scope block above has set the name, the URL and the capability of its
// crumb: ancestors point to their report2.php view, the current (= last)
// scope points out to its own page (course page, instance view, detail view
// of the booking option ...), which is opened in a new tab.
$basescope = str_replace('answers', '', $scope);
$scopelabels = [
    'system' => get_string('report2labelsystem', 'mod_booking'),
    'course' => get_string('report2labelcourse', 'mod_booking'),
    'instance' => get_string('report2labelinstance', 'mod_booking'),
    'option' => get_string('report2labeloption', 'mod_booking'),
    'optiondate' => get_string('report2labeloptiondate', 'mod_booking'),
];
$scopenames = [
    'system' => booking::shorten_text(format_string($SITE->fullname)),
    'course' => isset($course) ? booking::shorten_text(format_string($course->fullname)) : '',
    'instance' => isset($bookingsettings) ? booking::shorten_text(format_string($bookingsettings->name)) : '',
    'option' => isset($optionsettings) ? booking::shorten_text($optionsettings->get_title_with_prefix()) : '',
];
$scopeurls = [
    'system' => $r2systemurl,
    'course' => $r2courseurl ?? null,
    'instance' => $r2instanceurl ?? null,
    'option' => $r2optionurl ?? null,
];
$scopecaps = [
    'system' => $r2syscap,
    'course' => $r2coursecap ?? false,
    'instance' => $r2instancecap ?? false,
    'option' => true,
];
$navdata = ['items' => []];
foreach ($scopes as $navscope) {
    if ($navscope === 'optiondate') {
        continue; // The optiondate crumb is the sessions dropdown, see below.
    }
    $isactive = ($navscope === $basescope);
    $item = [
        'label' => $scopelabels[$navscope],
        'name' => $scopenames[$navscope],
        'active' => $isactive,
    ];
    if ($isactive) {
        $item['pageurl'] = $scopeurls[$navscope]->out(false);
        $item['pagelinktitle'] = get_string('report2gotoscopepage', 'mod_booking', $scopenames[$navscope]);
    } else if ($scopecaps[$navscope]) {
        $item['url'] = $scopeurls[$navscope]->out(false);
    }
    $navdata['items'][] = $item;
}

// In option and optiondate scope, a dropdown crumb lists all optiondates
// (sessions) of the booking option. In optiondate scope it is the active
// crumb, labelled with the current session and marking it in the menu.
if (in_array($basescope, ['option', 'optiondate']) && !empty($optionsettings->sessions)) {
    $sessionsdata = [];
    foreach ($optionsettings->sessions as $session) {
        $sessionurl = new moodle_url('/mod/booking/report2.php', [
            'optionid' => $optionid,
            'optiondateid' => $session->id,
        ]);
        $sessionsdata[] = [
            'prettydate' => dates_handler::prettify_optiondates_start_end(
                $session->coursestarttime,
                $session->courseendtime,
                current_language(),
                true
            ),
            'url' => $sessionurl->out(false),
            'current' => ((int) $session->id === $optiondateid),
        ];
    }
    $navdata['sessionsdropdown'] = [
        'label' => $scopelabels['optiondate'],
        'active' => ($basescope === 'optiondate'),
        'toggletext' => $basescope === 'optiondate'
            ? $prettydatestring
            : get_string('choosesession', 'mod_booking'),
        'sessions' => $sessionsdata,
    ];
}

echo $OUTPUT->header();

// Add the navigation here.
echo html_writer::div(
    $OUTPUT->render_from_template('mod_booking/report/navigation_breadcrumbs', $navdata),
    'mt-3 mb-4'
);

// Title of the page for the current scope.
echo $OUTPUT->heading("<div class='report2-title'>$ticketicon $heading</div>");

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
            '<i class="fa fa-object-group fa-fw" aria-hidden="true"></i>&nbsp;' .
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
            '<i class="fa fa-object-ungroup fa-fw" aria-hidden="true"></i>&nbsp;' .
            get_string('bookingstrackerswitchviewtypetoanswers', 'mod_booking') . '</a>';
    }
}

// Buttons, we only show in option scope can be added here.
if (!empty($optionid) && empty($optiondateid)) {
    $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
    $cmid = $optionsettings->cmid;
    $context = context_module::instance($cmid);

    // Compact info line below the title: dates, description, teachers and responsible contacts.
    // Dates (if more than one) and description expand as collapsibles below the line.
    $infoboxdata = [
        'optionid' => $optionid,
        'teachers' => [],
        'contacts' => [],
    ];
    if (!empty(trim($optionsettings->description ?? ''))) {
        $description = placeholders_info::render_text(
            $optionsettings->description,
            $optionsettings->cmid,
            $optionsettings->id,
            $USER->id
        );
        $infoboxdata['description'] = format_text(
            $description,
            $optionsettings->descriptionformat ?? FORMAT_HTML,
            ['context' => $context]
        );
    }
    $teachers = [];
    foreach ($optionsettings->teachers as $teacher) {
        $teacheruser = singleton_service::get_instance_of_user((int) $teacher->userid);
        if (!empty($teacheruser)) {
            $teachers[] = $teacheruser;
        }
    }
    $lastindex = count($teachers) - 1;
    foreach ($teachers as $index => $teacheruser) {
        $infoboxdata['teachers'][] = [
            'name' => fullname($teacheruser),
            'profileurl' => (new moodle_url('/user/profile.php', ['id' => $teacheruser->id]))->out(false),
            'notlast' => $index != $lastindex,
        ];
    }
    $infoboxdata['teachersexist'] = !empty($infoboxdata['teachers']);
    $contacts = array_values(array_filter($optionsettings->responsiblecontactuser));
    $lastindex = count($contacts) - 1;
    foreach ($contacts as $index => $contactuser) {
        $infoboxdata['contacts'][] = [
            'name' => fullname($contactuser),
            'profileurl' => (new moodle_url('/user/profile.php', ['id' => $contactuser->id]))->out(false),
            'notlast' => $index != $lastindex,
        ];
    }
    $infoboxdata['contactsexist'] = !empty($infoboxdata['contacts']);
    // No optiondates are shown for self-learning courses.
    if (empty($optionsettings->selflearningcourse)) {
        $optiondateswithentities = new optiondates_with_entities($optionsettings);
        $sessions = array_values($optiondateswithentities->sessions);
        if (count($sessions) == 1) {
            // A single date is shown directly in the info line.
            $infoboxdata['singledate'] = $sessions[0]['datestring'] ?? '';
        } else if (count($sessions) > 1) {
            // Multiple dates are collapsed behind a "Show dates" link.
            /** @var renderer $renderer */
            $renderer = $PAGE->get_renderer('mod_booking');
            $infoboxdata['optiondates'] = $renderer->render_optiondates_with_entities($optiondateswithentities);
        }
    }
    if (
        $infoboxdata['teachersexist']
        || $infoboxdata['contactsexist']
        || !empty($infoboxdata['singledate'])
        || !empty($infoboxdata['optiondates'])
        || !empty($infoboxdata['description'])
    ) {
        echo $OUTPUT->render_from_template('mod_booking/report/infobox', $infoboxdata);
    }

    // Slot booking options manage their participants per slot, so users cannot be
    // booked here directly. The "book other users" button is therefore hidden.
    $isslotoption = (int)($optionsettings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT)
        === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;

    $bookotherusersshown = false;
    if (
        !$isslotoption
        && has_capability('mod/booking:bookforothers', $context)
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
        $bookotherusersshown = true;
    }

    // Button to configure and download the sign-in sheet via dynamic form modal.
    // Same functionality as on report.php, the actual download runs through the
    // existing endpoint there (see mod_booking\form\modal_signinsheet_download).
    $signinsavebuttonstr = signinsheet_config::is_htmlmode()
        ? 'signinformatbutton'
        : 'signinsheetdownload';
    echo html_writer::tag(
        'button',
        '<i class="fa fa-list fa-fw" aria-hidden="true"></i>&nbsp;' .
            get_string('signinsheetconfigure', 'mod_booking'),
        [
            'type' => 'button',
            'class' => 'btn btn-primary btn-sm' . ($bookotherusersshown ? ' ms-2' : ''),
            'data-action' => 'booking-report2-signinsheet-modal',
            'data-cmid' => $cmid,
            'data-optionid' => $optionid,
            'data-savebuttonstr' => $signinsavebuttonstr,
        ]
    );
    $PAGE->requires->js_call_amd('mod_booking/signinsheetmodal', 'init');

    // Quick download of the sign-in sheet with the persisted settings of this
    // option (falling back to instance / plugin settings). The modal above
    // updates this link after each (re)configuration.
    $quickdownloadurl = signinsheet_config::download_url(
        $cmid,
        $optionid,
        signinsheet_config::for_option($optionid)
    );
    echo html_writer::link(
        $quickdownloadurl,
        '<i class="fa fa-download fa-fw" aria-hidden="true"></i>&nbsp;' .
            get_string('signinsheetdownload', 'mod_booking'),
        [
            'class' => 'btn btn-primary btn-sm ms-2',
            'data-id' => 'booking-report2-signinsheet-quickdownload',
        ]
    );

    // Button to send a message to the teachers of this option. Opens the same
    // dynamic form as the "Send message to teacher(s)" action button of the
    // booked users table (teacher autocomplete with preselection, subject,
    // message, attachment). Only shown if the option has teachers.
    if (
        !empty($optionsettings->teachers)
        && has_capability('mod/booking:communicate', $context)
    ) {
        echo html_writer::tag(
            'button',
            '<i class="fa fa-envelope fa-fw" aria-hidden="true"></i>&nbsp;' .
                get_string('sendmessagetoteachers', 'mod_booking'),
            [
                'type' => 'button',
                'class' => 'btn btn-primary btn-sm ms-2',
                'data-action' => 'booking-report2-sendmessagetoteachers-modal',
                'data-cmid' => $cmid,
                'data-optionid' => $optionid,
            ]
        );
        $PAGE->requires->js_call_amd('mod_booking/sendmessagetoteachersmodal', 'init');
    }

    // Analogous button to send a message to the responsible contact(s) of this
    // option: same modal, but with the responsible contacts preselected instead
    // of the teachers. Only shown if the option has responsible contacts.
    if (
        !empty(array_filter($optionsettings->responsiblecontactuser))
        && has_capability('mod/booking:communicate', $context)
    ) {
        echo html_writer::tag(
            'button',
            '<i class="fa fa-envelope fa-fw" aria-hidden="true"></i>&nbsp;' .
                get_string('sendmessagetoresponsiblecontacts', 'mod_booking'),
            [
                'type' => 'button',
                'class' => 'btn btn-primary btn-sm ms-2',
                'data-action' => 'booking-report2-sendmessagetocontacts-modal',
                'data-cmid' => $cmid,
                'data-optionid' => $optionid,
            ]
        );
        $PAGE->requires->js_call_amd('mod_booking/sendmessagetocontactsmodal', 'init');
    }

    // Hint for users who may edit the booking instance: which columns the
    // tables below show is configured in the instance settings. Labels and
    // link target are resolved at runtime, so they always match the real
    // settings form.
    if (has_capability('mod/booking:updatebooking', $context)) {
        $modediturl = new moodle_url(
            '/course/modedit.php',
            ['update' => $cmid, 'return' => 1],
            'id_configurefields'
        );
        $a = new stdClass();
        $a->url = $modediturl->out(false);
        $a->section = get_string('configurefields', 'mod_booking');
        $a->field = get_string('manageresponsespagefields', 'mod_booking');
        echo '<div class="alert alert-secondary mt-3 mb-2">' .
            get_string('report2columnsconfighint', 'mod_booking', $a) . '</div>';
    }
}

// Now we render the booked users for the provided scope.
// In optiondate scope only the booked users table is shown (the scope class
// returns null tables for all other status params).
$data = new booked_users(
    $scope,
    $scopeid,
    true, // Booked users.
    true, // Users on waiting list.
    true, // Reserved answers (e.g. in shopping cart).
    true, // Users on notify list.
    true, // Deleted users.
    true, // Booking history.
    // Options to confirm are not shown in the tracker: they would just duplicate the waiting
    // list here. The real confirm workflow uses its own scope (optionstoconfirm shortcode).
    false, // Options to confirm.
    true, // Previously booked users.
    $cmid,
    false, // Reduced buttons.
    [], // Customfields.
    true // Sent messages.
);
/** @var renderer $renderer */
$renderer = $PAGE->get_renderer('mod_booking');
echo $renderer->render_booked_users($data);

echo $OUTPUT->footer();
