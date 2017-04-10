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
 * This page allows a user to subscribe/unsubscribe other users from a booking option TODO: upgrade logging, add logging for added/deleted users
 *
 * @author David Bogner davidbogner@gmail.com
 * @package mod/booking
 */
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT); // course_module ID
$optionid = required_param('optionid', PARAM_INT); //
$subscribe = optional_param('subscribe', false, PARAM_BOOL);
$unsubscribe = optional_param('unsubscribe', false, PARAM_BOOL);
$agree = optional_param('agree', false, PARAM_BOOL);

list($course, $cm) = get_course_and_cm_from_cmid($id);

(boolean) $subscribesuccess = false;
(boolean) $unsubscribesuccess = false;

$bookingoption = new \mod_booking\booking_option($id, $optionid);
$bookingoption->update_booked_users();
$bookingoption->apply_tags();

require_login($course, true, $cm);

// Print the page header
$context = context_module::instance($cm->id);
$PAGE->set_context($context);

require_capability('mod/booking:subscribeusers', $context);

$url = new moodle_url('/mod/booking/subscribeusers.php', array('id' => $id, 'optionid' => $optionid));
$errorurl = new moodle_url('/mod/booking/view.php', array('id' => $id));

$PAGE->set_url($url);
$PAGE->set_title(get_string('modulename', 'booking'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->navbar->add(get_string('booking:subscribeusers', 'booking'), $url);
if (!$agree && (!empty($bookingoption->booking->bookingpolicy))) {
    echo $OUTPUT->header();
    $alright = false;
    $message = "<p><b>" . get_string('agreetobookingpolicy', 'booking') . ":</b></p>";
    $message .= "<p>" . $bookingoption->booking->bookingpolicy . "<p>";
    $continueurl = new moodle_url($PAGE->url->out(false, array('agree' => 1)));
    $continue = new single_button($continueurl, get_string('continue'), 'get');
    $cancel = new single_button($errorurl, get_string('cancel'), 'get');
    echo $OUTPUT->confirm($message, $continue, $cancel);
    echo $OUTPUT->footer();
    die();
} else {
    $options = array('bookingid' => $cm->instance, 'currentgroup' => array(),
        'accesscontext' => $context, 'optionid' => $optionid, 'cmid' => $cm->id, 'course' => $course,
        'potentialusers' => $bookingoption->potentialusers);

    $bookingoutput = $PAGE->get_renderer('mod_booking');

    $existingoptions = $options;
    $existingoptions['potentialusers'] = $bookingoption->bookedvisibleusers;

    $existingselector = new booking_existing_user_selector('removeselect', $existingoptions);
    $subscriberselector = new booking_potential_user_selector('addselect', $options);

    if (data_submitted()) {
        require_sesskey();
        // It has to be one or the other, not both or neither
        // if (!($subscribe xor $unsubscribe)) {
            // print_error('invalidaction');
        // }
        if ($subscribe) {
            $users = $subscriberselector->get_selected_users();
            // compare if selected users are members of the currentgroup if person has not the
            // right to access all groups
            $subscribesuccess = true;
            $subscribedusers = array();

            if (has_capability('moodle/site:accessallgroups', $context) or
                     (booking_check_if_teacher($bookingoption->option))) {
                foreach ($users as $user) {
                    if (!$bookingoption->user_submit_response($user)) {
                        $subscribesuccess = false;
                        print_error('bookingmeanwhilefull', 'booking', $errorurl->out(), $user->id);
                    }
                    $subscribedusers[] = $user->id;
                }
            } else {
                print_error('invalidaction');
            }
        } else if ($unsubscribe &&
                 (has_capability('mod/booking:deleteresponses', $context) ||
                 (booking_check_if_teacher($bookingoption->option, $USER)))) {
            $users = $existingselector->get_selected_users();
            $unsubscribesuccess = true;
            foreach ($users as $user) {
                if (!$bookingoption->user_delete_response($user->id)) {
                    $unsubscribesuccess = false;
                    print_error('cannotremovesubscriber', 'booking', $errorurl->out(), $user->id);
                }
            }
        } else if ($unsubscribe &&
                 (!has_capability('mod/booking:deleteresponses', $context) ||
                 (booking_check_if_teacher($bookingoption->option, $USER)))) {
            print_error('nopermission', null, $errorurl->out());
        }
        $subscriberselector->invalidate_selected_users();
        $existingselector->invalidate_selected_users();
        $bookingoption->update_booked_users();
        $subscriberselector->set_potential_users($bookingoption->potentialusers);
        $existingselector->set_potential_users($bookingoption->bookedvisibleusers);
    }
}
echo $OUTPUT->header();
echo $OUTPUT->heading("{$bookingoption->option->text}", 3, 'helptitle', 'uniqueid');

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
    if ($unsubscribesuccess && (has_capability('mod/booking:deleteresponses', $context) || (booking_check_if_teacher($bookingoption->option, $USER)))) {
        echo $OUTPUT->container(get_string('allchangessave', 'booking'), 'important', 'notice');
    }
}

echo $bookingoutput->subscriber_selection_form($existingselector, $subscriberselector);

echo $OUTPUT->footer();