<?php

require_once("../../config.php");
require_once("lib.php");

$id    = required_param('id',PARAM_INT);
$optionid  = required_param('optionid',PARAM_INT);
$edit  = optional_param('edit',-1,PARAM_BOOL);

$url = new moodle_url('/mod/booking/teachers.php', array('id'=>$id, 'optionid' => $optionid));

if ($edit !== 0) {
    $url->param('edit', $edit);
}
$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('booking', $id)) {
	print_error('invalidcoursemodule');
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}

require_course_login($course, false, $cm);

if (!$booking = booking_get_booking($cm, 'coursestarttime ASC')) {
	print_error("Course module is incorrect");
}

$context = context_module::instance($cm->id);
if (!has_capability('mod/booking:updatebooking', $context)) {
    print_error('nopermissiontupdatebooking', 'booking');
}

add_to_log($course->id, "booking", "view teachers", "teachers.php?id=$id", $booking->id, $cm->id);

$output = $PAGE->get_renderer('mod_booking');

$currentgroup = groups_get_activity_group($cm);
$options = array('optionid'=>$optionid, 'currentgroup'=>$currentgroup, 'context'=>$context);
$existingselector = new booking_existing_subscriber_selector('existingsubscribers', $options);
$subscriberselector = new booking_potential_subscriber_selector('potentialsubscribers', $options);
$subscriberselector->set_existing_subscribers($existingselector->find_users(''));

if (data_submitted()) {
    require_sesskey();
    $subscribe = (bool)optional_param('subscribe', false, PARAM_RAW);
    $unsubscribe = (bool)optional_param('unsubscribe', false, PARAM_RAW);
    /** It has to be one or the other, not both or neither */
    if (!($subscribe xor $unsubscribe)) {
        print_error('invalidaction');
    }
    if ($subscribe) {
        $users = $subscriberselector->get_selected_users();
        foreach ($users as $user) {
            if (!booking_optionid_subscribe($user->id, $optionid)) {
                print_error('cannotaddsubscriber', 'booking', '', $user->id);
            }
        }
    } else if ($unsubscribe) {
        $users = $existingselector->get_selected_users();
        foreach ($users as $user) {
            if (!booking_optionid_unsubscribe($user->id, $optionid)) {
                print_error('cannotremovesubscriber', 'booking', '', $user->id);
            }
        }
    }
    $subscriberselector->invalidate_selected_users();
    $existingselector->invalidate_selected_users();
    $subscriberselector->set_existing_subscribers($existingselector->find_users(''));
}

$PAGE->navbar->add(get_string('addteachers', 'booking'));
$PAGE->set_title(get_string('addteachers', 'booking'));
$PAGE->set_heading($COURSE->fullname);
if (has_capability('mod/booking:updatebooking', $context)) {
    $PAGE->set_button(booking_update_subscriptions_button($id, $optionid));
    if ($edit != -1) {
        $USER->subscriptionsediting = $edit;
    }
} else {
    unset($USER->subscriptionsediting);
}
echo $output->header();
echo $output->heading(get_string('addteachers', 'booking'));

if (empty($USER->subscriptionsediting)) {
	$option = $DB->get_record("booking_options", array("id" => $optionid));
    echo $output->subscriber_overview(booking_subscribed_teachers($course, $optionid,$id, $currentgroup, $context), $option, $course);
} else {
    echo $output->subscriber_selection_form($existingselector, $subscriberselector);
}
echo $output->footer();

?>