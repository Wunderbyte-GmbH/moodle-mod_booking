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
 * TODO: upgrade logging, add logging for added/deleted users
 *
 * @author David Bogner davidbogner@gmail.com
 * @package mod_booking
 */
global $CFG, $DB, $COURSE, $PAGE, $OUTPUT;
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

use core\output\notification;
use mod_booking\booking_utils;
use mod_booking\form\subscribe_cohort_or_group_form;

$id = required_param('id', PARAM_INT); // Course_module ID.
$optionid = required_param('optionid', PARAM_INT);
$subscribe = optional_param('subscribe', false, PARAM_BOOL);
$unsubscribe = optional_param('unsubscribe', false, PARAM_BOOL);
$agree = optional_param('agree', false, PARAM_BOOL);

list($course, $cm) = get_course_and_cm_from_cmid($id);

(boolean) $subscribesuccess = false;
(boolean) $unsubscribesuccess = false;

require_login($course, true, $cm);

// Print the page header.
$context = context_module::instance($cm->id);
$PAGE->set_context($context);

$bookingoption = new \mod_booking\booking_option($id, $optionid);
$url = new moodle_url('/mod/booking/subscribeusers.php', array('id' => $id, 'optionid' => $optionid, 'agree' => $agree));
$errorurl = new moodle_url('/mod/booking/view.php', array('id' => $id));

if (!booking_check_if_teacher ($bookingoption->option)) {
    if (!(has_capability('mod/booking:subscribeusers', $context) || has_capability('moodle/site:accessallgroups', $context))) {
        throw new moodle_exception('nopermissions', 'core', $errorurl, get_string('bookotherusers', 'mod_booking'));
    }
}

$bookingoption->update_booked_users();
$bookingoption->apply_tags();

$PAGE->set_url($url);
$PAGE->set_title(get_string('modulename', 'booking'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->navbar->add(get_string('booking:subscribeusers', 'booking'), $url);
if (!$agree && (!empty($bookingoption->booking->settings->bookingpolicy))) {
    echo $OUTPUT->header();
    $alright = false;
    $message = "<p><b>" . get_string('agreetobookingpolicy', 'booking') . ":</b></p>";
    $message .= "<p>" . format_text($bookingoption->booking->settings->bookingpolicy, FORMAT_HTML) . "<p>";
    $continueurl = new moodle_url($PAGE->url->out(false, array('agree' => 1)));
    $continue = new single_button($continueurl, get_string('continue'), 'get');
    $cancel = new single_button($errorurl, get_string('cancel'), 'get');
    echo $OUTPUT->confirm($message, $continue, $cancel);
    echo $OUTPUT->footer();
    die();
} else {
     $options = array('bookingid' => $cm->instance,
                    'accesscontext' => $context, 'optionid' => $optionid, 'cm' => $cm, 'course' => $course,
                    'potentialusers' => $bookingoption->bookedvisibleusers);
    $bookingoutput = $PAGE->get_renderer('mod_booking');
    $existingoptions = $options;
    $existingselector = new booking_existing_user_selector('removeselect', $existingoptions);
    $subscriberselector = new booking_potential_user_selector('addselect', $options);

    if (data_submitted()) {
        require_sesskey();
        if ($subscribe) {
            $users = $subscriberselector->get_selected_users();
            $subscribesuccess = true;
            $subscribedusers = array();
            $notsubscribedusers = array();

            if (has_capability('mod/booking:subscribeusers', $context) or (booking_check_if_teacher(
                    $bookingoption->option))) {
                foreach ($users as $user) {
                    if (!$bookingoption->user_submit_response($user)) {
                        $subscribesuccess = false;
                        $notsubscribedusers[] = $user;
                    }
                    $subscribedusers[] = $user->id;
                }
                if ($subscribesuccess) {
                    redirect($url,
                            get_string('allusersbooked', 'mod_booking', count($subscribedusers)), 5);
                } else {
                    $output = '<br>';
                    if (!empty($notsubscribedusers)) {
                        foreach ($notsubscribedusers as $user) {
                            $result = $DB->get_records_sql(
                                    'SELECT bo.text FROM {booking_answers} ba
                                     LEFT JOIN {booking_options} bo ON bo.id = ba.optionid
                                     WHERE ba.userid = ?
                                     AND ba.bookingid = ?', array($user->id, $bookingoption->booking->id));
                            $output .= "{$user->firstname} {$user->lastname}";
                            if (!empty($result)) {
                                $r = array();
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
                print_error('invalidaction');
            }
        } else if ($unsubscribe && (has_capability('mod/booking:deleteresponses', $context) ||
                 (booking_check_if_teacher($bookingoption->option)))) {
            $users = $existingselector->get_selected_users();
            $unsubscribesuccess = true;
            foreach ($users as $user) {
                if (!$bookingoption->user_delete_response($user->id)) {
                    $unsubscribesuccess = false;
                    print_error('cannotremovesubscriber', 'booking', $url->out(), $user->id);
                }
            }
        } else if ($unsubscribe && (!has_capability('mod/booking:deleteresponses', $context) ||
                 (booking_check_if_teacher($bookingoption->option)))) {
            print_error('nopermission', null, $url->out());
        }
        $subscriberselector->invalidate_selected_users();
        $existingselector->invalidate_selected_users();
        $bookingoption->update_booked_users();
        $subscriberselector->set_potential_users($bookingoption->potentialusers);
        $existingselector->set_potential_users($bookingoption->bookedvisibleusers);
    }
}
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($bookingoption->option->text), 3, 'helptitle', 'uniqueid');

echo html_writer::tag('div',
        html_writer::link(
                new moodle_url('/mod/booking/report.php',
                        array('id' => $cm->id, 'optionid' => $optionid)),
                get_string('backtoresponses', 'booking')),
        array('style' => 'width:100%; font-weight: bold; text-align: right;'));

if ($subscribesuccess || $unsubscribesuccess) {
    if ($subscribesuccess) {
        echo $OUTPUT->container(get_string('allchangessave', 'booking'), 'important', 'notice');
    }
    if ($unsubscribesuccess &&
             (has_capability('mod/booking:deleteresponses', $context) ||
             (booking_check_if_teacher($bookingoption->option)))) {
        echo $OUTPUT->container(get_string('allchangessave', 'booking'), 'important', 'notice');
    }
}

echo $bookingoutput->subscriber_selection_form($existingselector, $subscriberselector, $course->id);

echo '<br>';

// Add the Moodle form for cohort and group subscription.
$mform = new subscribe_cohort_or_group_form();
$mform->set_data(['id' => $id, 'optionid' => $optionid]);

// Form processing and displaying is done here.
if ($fromform = $mform->get_data()) {

    $notificationstring = '';
    $delay = 0;
    $notificationtype = notification::NOTIFY_INFO;

    $url = new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id, 'optionid' => $optionid, 'agree' => $agree]);

    if (!empty($fromform->cohortids) || !empty($fromform->groupids)){
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
        error_log('subscribeusers.php: Exception in redirect function.');
    }

} else {
    // This branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed...
    // ... or on the first display of the form.
    $mform->display();
}

echo $OUTPUT->footer();
