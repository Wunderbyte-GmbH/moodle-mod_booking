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
 * This page allows a user to subscribe/unsubscribe other users from a booking option.
 *
 * TODO: upgrade logging, add logging for added/deleted users
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner davidbogner@gmail.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

use core\output\notification;
use mod_booking\booking_utils;
use mod_booking\form\subscribe_cohort_or_group_form;
use mod_booking\output\booked_users;
use mod_booking\output\renderer;
use mod_booking\singleton_service;

global $CFG, $DB, $COURSE, $PAGE, $OUTPUT;

$id = required_param('id', PARAM_INT); // Course_module ID.
$optionid = required_param('optionid', PARAM_INT);
$subscribe = optional_param('subscribe', false, PARAM_BOOL);
$unsubscribe = optional_param('unsubscribe', false, PARAM_BOOL);
$agree = optional_param('agree', false, PARAM_BOOL);
$bookanyone = optional_param('bookanyone', false, PARAM_BOOL);

// If we have already submitted the form, we don't want to fall into the agree policy.
$formsubmitted = optional_param('submitbutton', '', PARAM_TEXT);

[$course, $cm] = get_course_and_cm_from_cmid($id);

(bool) $subscribesuccess = false;
(bool) $unsubscribesuccess = false;

require_login($course, true, $cm);

// Print the page header.
$context = context_module::instance($cm->id);
$PAGE->set_context($context);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);
$optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

$url = new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id, 'optionid' => $optionid, 'agree' => $agree]);
$errorurl = new moodle_url('/mod/booking/view.php', ['id' => $id]);

$PAGE->set_url($url);

// Without the "bookforothers" capability, we do not allow anything.
if (!has_capability('mod/booking:bookforothers', $context)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

if (!booking_check_if_teacher($bookingoption->option)) {
    if (!(has_capability('mod/booking:subscribeusers', $context) || has_capability('moodle/site:accessallgroups', $context))) {
        throw new moodle_exception('nopermissions', 'core', $errorurl, get_string('bookotherusers', 'mod_booking'));
    }
}

$bookingoption->update_booked_users();
$bookingoption->apply_tags();

$PAGE->set_title(get_string('modulename', 'booking'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->navbar->add(get_string('booking:subscribeusers', 'booking'), $url);
if (!$agree && empty($formsubmitted) && (!empty($bookingoption->booking->settings->bookingpolicy))) {
    echo $OUTPUT->header();
    $alright = false;
    $message = "<p><b>" . get_string('bookingpolicyagree', 'booking') . ":</b></p>";
    $message .= "<p>" . format_text($bookingoption->booking->settings->bookingpolicy, FORMAT_HTML) . "<p>";
    $continueurl = new moodle_url($PAGE->url->out(false, ['agree' => 1]));
    $continue = new single_button($continueurl, get_string('continue'), 'get');
    $cancel = new single_button($errorurl, get_string('cancel'), 'get');
    echo $OUTPUT->confirm($message, $continue, $cancel);
    echo $OUTPUT->footer();
    die();
} else {
    $subscribeduseroptions = [
        'bookingid' => $cm->instance,
        'accesscontext' => $context,
        'optionid' => $optionid,
        'cm' => $cm,
        'course' => $course,
        'potentialusers' => $bookingoption->bookedvisibleusers,
    ];
    $potentialuseroptions = $subscribeduseroptions;

    // Potential users will be selected on instantiation of booking_potential_user_selector.
    $potentialuseroptions['potentialusers'] = [];

    /** @var renderer $bookingoutput */
    $bookingoutput = $PAGE->get_renderer('mod_booking');
    $existingselector = new booking_existing_user_selector('removeselect', $subscribeduseroptions);
    $subscriberselector = new booking_potential_user_selector('addselect', $potentialuseroptions);

    if (data_submitted()) {
        require_sesskey();
        if ($subscribe) {
            $users = $subscriberselector->get_selected_users();
            $subscribesuccess = true;
            $subscribedusers = [];
            $notsubscribedusers = [];

            if (
                has_capability('mod/booking:subscribeusers', $context) ||
                (booking_check_if_teacher($bookingoption->option))
            ) {
                foreach ($users as $user) {
                    // If there is a price on the booking option, we don't want to subscribe the user directly.
                    if (
                        class_exists('local_shopping_cart\shopping_cart')
                        && !empty($optionsettings->jsonobject->useprice)
                        && empty(get_config('booking', 'turnoffwaitinglist'))
                    ) {
                        $status = 3; // This added without confirmation.
                    } else {
                        $status = 0;
                    }

                    if (!$bookingoption->user_submit_response($user, 0, 0, $status, MOD_BOOKING_VERIFIED)) {
                        $subscribesuccess = false;
                        $notsubscribedusers[] = $user;
                    }
                    $subscribedusers[] = $user->id;
                }
                if ($subscribesuccess) {
                    redirect(
                        $url,
                        get_string(
                            'allusersbooked',
                            'mod_booking',
                            count($subscribedusers)
                        ),
                        5
                    );
                } else {
                    $output = '<br>';
                    if (!empty($notsubscribedusers)) {
                        foreach ($notsubscribedusers as $user) {
                            $result = $DB->get_records_sql(
                                'SELECT ba.id answerid, bo.text
                                FROM {booking_answers} ba
                                LEFT JOIN {booking_options} bo ON bo.id = ba.optionid
                                WHERE ba.userid = ? AND ba.waitinglist < ?
                                AND ba.bookingid = ?',
                                [
                                    $user->id,
                                    MOD_BOOKING_STATUSPARAM_RESERVED,
                                    $bookingoption->booking->id,
                                    ]
                            );
                            $output .= "{$user->firstname} {$user->lastname}";
                            if (!empty($result)) {
                                $r = [];
                                foreach ($result as $v) {
                                    $r[] = $v->text;
                                }
                                $output .= '&nbsp;' . get_string('enrolledinoptions', 'mod_booking') .
                                         implode(', ', $r);
                            }
                            $output .= " <br>";
                        }
                    }
                    redirect($url, get_string('notallbooked', 'mod_booking', $output), 5);
                }
            } else {
                throw new moodle_exception('invalidaction');
            }
        } else if (
            $unsubscribe
            && (
                has_capability('mod/booking:deleteresponses', $context)
                || (booking_check_if_teacher($bookingoption->option))
            )
        ) {
            $users = $existingselector->get_selected_users();
            $unsubscribesuccess = true;
            foreach ($users as $user) {
                if (!$bookingoption->user_delete_response($user->id)) {
                    $unsubscribesuccess = false;
                    throw new moodle_exception(
                        'cannotremovesubscriber',
                        'booking',
                        $url->out(),
                        null,
                        'Cannot remove subscriber with id ' . $user->id
                    );
                }
            }
        } else if (
            $unsubscribe
            && (
                !has_capability('mod/booking:deleteresponses', $context)
                || (booking_check_if_teacher($bookingoption->option))
            )
        ) {
                    throw new moodle_exception(
                        'nopermission',
                        'booking',
                        $url->out(),
                        null,
                        'Permission to unsubscribe users is missing'
                    );
        }
        $subscriberselector->invalidate_selected_users();
        $existingselector->invalidate_selected_users();
        $bookingoption->update_booked_users();
        $subscriberselector->set_potential_users($bookingoption->potentialusers);
        $existingselector->set_potential_users($bookingoption->bookedvisibleusers);
    }
}

// Add the Moodle form for cohort and group subscription.
$mform = new subscribe_cohort_or_group_form();
$mform->set_data(['id' => $id, 'optionid' => $optionid, 'agree' => "1"]);

// Form processing and displaying is done here.
if ($fromform = $mform->get_data()) {
    $notificationstring = '';
    $delay = 0;
    $notificationtype = notification::NOTIFY_INFO;

    $url = new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id, 'optionid' => $optionid, 'agree' => 1]);

    if (!empty($fromform->cohortids) || !empty($fromform->groupids)) {
        $result = booking_utils::book_cohort_or_group_members($fromform, $bookingoption, $context);
        $delay = 120;

        // Generate the notification string and determine the notification color.
        $notificationstring = get_string('resultofcohortorgroupbooking', 'mod_booking', $result);

        if ($result->notenrolledusers > 0 || $result->notsubscribedusers > 0) {
            $notificationstring .= get_string('problemsofcohortorgroupbooking', 'mod_booking', $result);

            if ($result->subscribedusers > 0) {
                $notificationtype = notification::NOTIFY_WARNING;
            } else {
                $notificationtype = notification::NOTIFY_ERROR;
            }
        } else {
            if ($result->subscribedusers > 0) {
                $notificationtype = notification::NOTIFY_SUCCESS;
            } else {
                $notificationtype = notification::NOTIFY_ERROR;
            }
        }
    } else {
        $notificationtype = notification::NOTIFY_ERROR;
        $notificationstring = get_string('nogrouporcohortselected', 'mod_booking');
        $delay = 5;
    }

    try {
        redirect($url, $notificationstring, $delay, $notificationtype);
    } catch (moodle_exception $e) {
        debugging('subscribeusers.php: Exception in redirect function.');
    }
}

// Under some circumstances, we don't allow direct booking of user.
if (
    class_exists('local_shopping_cart\shopping_cart')
    && !empty($optionsettings->jsonobject->useprice)
    && empty(get_config('booking', 'turnoffwaitinglist'))
) {
    $message = get_string('nodirectbookingbecauseofprice', 'mod_booking');
    $type = \core\notification::INFO;
    \core\notification::add($message, $type);
}

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($optionsettings->get_title_with_prefix()), 3, 'helptitle', 'uniqueid');

// Switch to turn booking of anyone ON or OFF.
if (has_capability('mod/booking:bookanyone', $context) && $bookanyone) {
    set_user_preference('bookanyone', '1');
    // Show button to turn it off again.
    $url = new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id,
                                                                'optionid' => $optionid,
                                                                'agree' => $agree,
                                                            ]);
    echo '<a class="btn btn-sm btn-light" href="' . $url . '">' . get_string('bookanyoneswitchoff', 'mod_booking') . '</a>';
    echo '<div class="alert alert-warning p-1 mt-1 text-center">' . get_string('bookanyonewarning', 'mod_booking')  . '</div>';
} else if (has_capability('mod/booking:bookanyone', $context)) {
    set_user_preference('bookanyone', '0');
    // Show button to turn it off again.
    $url = new moodle_url(
        '/mod/booking/subscribeusers.php',
        [
            'id' => $id,
            'optionid' => $optionid,
            'agree' => $agree,
            'bookanyone' => true,
        ]
    );
    echo '<a class="btn btn-sm btn-light" href="' . $url . '">' . get_string('bookanyoneswitchon', 'mod_booking') . '</a>';
}


// We call the template render to display how many users are currently reserved.
$data = new booked_users(
    'option',
    $optionid,
    false,
    true,
    true,
    true,
    true
);
$renderer = $PAGE->get_renderer('mod_booking');
echo $renderer->render_booked_users($data);

// We call the template render to display how many users are currently reserved.
$data = new booked_users('option', $optionid, false, false, false, false, true);
$deletedlist = $renderer->render_booked_users($data);

if (!empty($deletedlist)) {
    $contents = html_writer::tag(
        'a',
        get_string('deletedusers', 'mod_booking'),
        [
            'class' => 'h5',
            'data-toggle' => "collapse",
            'href' => "#collapseDeletedlist",
            'role' => "button",
            'aria-expanded' => "false",
            'aria-controls' => "collapseDeletedlist",
        ]
    );
    echo html_writer::tag('div', $contents);
    echo html_writer::tag(
        'div',
        $deletedlist,
        [
            'class' => "collapse",
            'id' => "collapseDeletedlist",
        ]
    );
}


echo html_writer::tag(
    'div',
    html_writer::link(
        new moodle_url(
            '/mod/booking/report.php',
            ['id' => $cm->id, 'optionid' => $optionid]
        ),
        get_string('backtoresponses', 'booking')
    ),
    ['style' => 'width:100%; font-weight: bold; text-align: right;']
);

if ($subscribesuccess || $unsubscribesuccess) {
    if ($subscribesuccess) {
        echo $OUTPUT->container(get_string('allchangessaved', 'booking'), 'important', 'notice');
    }
    if (
        $unsubscribesuccess && (
            has_capability('mod/booking:deleteresponses', $context) ||
            (booking_check_if_teacher($bookingoption->option))
        )
    ) {
        echo $OUTPUT->container(get_string('allchangessaved', 'booking'), 'important', 'notice');
    }
}

if (
    booking_check_if_teacher($bookingoption->option)
    && !has_capability(
        'mod/booking:readallinstitutionusers',
        $context
    )
) {
    echo html_writer::tag(
        'div',
        get_string('onlyusersfrominstitution', 'mod_booking', $bookingoption->option->institution),
        ['class' => 'alert alert-info']
    );
}

echo $bookingoutput->subscriber_selection_form($existingselector, $subscriberselector, $course->id);
echo '<br>';

// We separated this part of the form handling from the above part, because of the redirect function.
if (!$fromform = $mform->get_data()) {
    // This branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed...
    // ... or on the first display of the form.
    $mform->display();
}

echo $OUTPUT->footer();
