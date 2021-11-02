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
use mod_booking\existing_subscriber_selector;
use mod_booking\potential_subscriber_selector;

require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");
require_once("teachers_form.php");

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);

$url = new moodle_url('/mod/booking/teachers.php',
        array('id' => $id, 'optionid' => $optionid, 'edit' => $edit));

$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if (!$booking = new mod_booking\booking_option($id, $optionid, array(), 0, 0, false)) {
    print_error("Course module is incorrect");
}

$context = context_module::instance($cm->id);
if (!has_capability('mod/booking:updatebooking', $context)) {
    print_error('nopermissiontupdatebooking', 'booking');
}

$output = $PAGE->get_renderer('mod_booking');

$currentgroup = groups_get_activity_group($cm);
$options = array('optionid' => $optionid, 'currentgroup' => $currentgroup, 'context' => $context);
$existingselector = new existing_subscriber_selector('existingsubscribers', $options);
$subscriberselector = new potential_subscriber_selector('potentialsubscribers', $options);
$subscriberselector->set_existing_subscribers($existingselector->find_users(''));

if ($edit === 0) {
    $option = $DB->get_record("booking_options", array("id" => $optionid));
    $allsubscribedteachers = booking_subscribed_teachers($course, $optionid, $id, $currentgroup,
            $context);
    $mform = new mod_booking_teachers_form(null,
            array('teachers' => $allsubscribedteachers, 'option' => $option, 'cm' => $cm,
                'id' => $id, 'optionid' => $optionid, 'edit' => $edit));

    if ($mform->is_cancelled()) {
        redirect("report.php?id=$cm->id&optionid={$optionid}");
    } else if ($fromform = $mform->get_data()) {

        if (isset($fromform->turneditingon) && has_capability('mod/booking:updatebooking', $context) &&
                 confirm_sesskey()) {
            $urlr = new moodle_url('/mod/booking/teachers.php',
                    array('id' => $id, 'optionid' => $optionid, 'edit' => 1));
            redirect($urlr, '', 0);
        }

        if (isset($fromform->activitycompletion) &&
                 has_capability('mod/booking:readresponses', $context) && confirm_sesskey()) {
            $selectedusers[$optionid] = array_keys($fromform->user, 1);

            if (empty($selectedusers[$optionid])) {
                redirect($url, get_string('selectatleastoneuser', 'booking'), 5);
            }

            booking_activitycompletion_teachers($selectedusers, $booking->booking->settings, $cm->id,
                    $optionid);
            redirect($url, get_string('activitycompletionsuccess', 'booking'), 5);
        }
    }
} else if (data_submitted()) {
    require_sesskey();
    $subscribe = (bool) optional_param('subscribe', false, PARAM_RAW);
    $unsubscribe = (bool) optional_param('unsubscribe', false, PARAM_RAW);
    $addtogroup = optional_param('addtogroup', false, PARAM_RAW);
    // It has to be one or the other, not both or neither.
    if (!($subscribe xor $unsubscribe)) {
        print_error('invalidaction');
    }
    if ($subscribe) {
        $users = $subscriberselector->get_selected_users();
        foreach ($users as $user) {
            if (!subscribe_teacher_to_booking_option($user->id, $optionid, $cm, $addtogroup)) {
                print_error('cannotaddsubscriber', 'booking', '', $user->id);
            }
        }
    } else if ($unsubscribe) {
        $users = $existingselector->get_selected_users();
        foreach ($users as $user) {
            if (!unsubscribe_teacher_from_booking_option($user->id, $optionid, $cm)) {
                print_error('cannotremovesubscriber', 'booking', '', $user->id);
            }
        }
    }
    $subscriberselector->invalidate_selected_users();
    $existingselector->invalidate_selected_users();
    $subscriberselector->set_existing_subscribers($existingselector->find_users(''));
}

if ($edit === 1) {
    $PAGE->navbar->add(get_string('addteachers', 'booking'));
} else {
    $PAGE->navbar->add(get_string('teachers', 'booking'));
}

$PAGE->set_title(get_string('addteachers', 'booking'));
$PAGE->set_heading($COURSE->fullname);

if (has_capability('mod/booking:updatebooking', $context)) {
    $USER->subscriptionsediting = $edit;
} else {
    unset($USER->subscriptionsediting);
}
echo $output->header();
if ($edit === 1) {
    echo $output->heading(get_string('addteachers', 'booking') . " [{$booking->option->text}]");
} else {
    echo $output->heading(get_string('teachers', 'booking') . " [{$booking->option->text}]");
}

echo html_writer::link(
        new moodle_url('/mod/booking/report.php', array('id' => $cm->id, 'optionid' => $optionid)),
        get_string('users', 'booking'), array('style' => 'float:right;'));
echo '<br>';

if (empty($USER->subscriptionsediting)) {
    $mform->display();
} else {
    echo $output->subscriber_selection_form($existingselector, $subscriberselector, $course->id);

}
echo $output->footer();