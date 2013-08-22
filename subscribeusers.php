<?php  
/**
 * This page prints a particular instance of booking
 *
 * @author  David Bogner davidbogner@gmail.com
 * @package mod/booking
 */


require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT); // course_module ID, or
$optionid = required_param('optionid', PARAM_INT); // 
$task  = optional_param('task', '', PARAM_ALPHA);  // 
$subscribe = optional_param('subscribe', false, PARAM_BOOL);
$unsubscribe = optional_param('unsubscribe', false, PARAM_BOOL);

$cm = get_coursemodule_from_id('booking', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$booking = booking_get_booking($cm);

require_login($course, true, $cm);

add_to_log($course->id, "booking", "subscribeusers", "subscribeusers.php?id=$cm->id", "$booking->id");

/// Print the page header
$context = context_module::instance($cm->id);
$PAGE->set_context($context);

require_capability('mod/booking:subscribeusers', $context);

$url = new moodle_url('/mod/booking/view.php', array('id'=>$id));
$PAGE->set_url($url);
$PAGE->set_title(get_string('modulename', 'booking'));


$bookingpage = new booking($context, $cm, $course, $booking);
$currentgroup = groups_get_course_group($course);
$params = array('task'=>$task,'optionid' => $optionid);
$options = array('bookingid'=>$booking->id, 'currentgroup'=>$currentgroup, 'context'=>$context, 'optionid'=>$optionid, 'cmid' =>$cm->id, 'course' => $course);
$potentialusers = $bookingpage->booking_potential_users($optionid);

$bookingoutput = $PAGE->get_renderer('mod_booking');

$existingselector = new booking_existing_user_selector('existingsubscribers', $options,$potentialusers);
$subscriberselector = new booking_potential_user_selector('potentialsubscribers', $options,$potentialusers);
$subscriberselector->set_existing_subscribers($existingselector->find_users(''));

if (data_submitted()) {
	require_sesskey();
	/** It has to be one or the other, not both or neither */
	if (!($subscribe xor $unsubscribe)) {
		print_error('invalidaction');
	}
	if ($subscribe) {
		$users = $subscriberselector->get_selected_users();
		foreach ($users as $user) {
			if(!groups_is_member($currentgroup,$USER->id) && !groups_is_member($currentgroup,$user->id) && !has_capability('moodle/site:accessallgroups', $context)){
				print_error('invalidaction');
			}
			if (!booking_user_submit_response($optionid, $booking, $user, $course->id, $cm)) {
				print_error('bookingmeanwhilefull', 'booking', $PAGE->url->out() , $user->id);
			}
		}
	} else if ($unsubscribe) {
		$users = $existingselector->get_selected_users();
		foreach ($users as $user) {
			$newbookeduser = booking_check_statuschange($optionid, $booking, $user->id, $cm->id);
			$answer = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $user->id, 'optionid' => $optionid));
			if(!booking_delete_singlebooking($answer,$booking,$optionid,$newbookeduser,$cm->id)){
				print_error('cannotremovesubscriber', 'forum', '', $user->id);
			} else {
				$bookingpage->booking_potential_users($optionid);
			}
		}
	}
	$subscriberselector->invalidate_selected_users();
	$existingselector->invalidate_selected_users();
	$subscriberselector->set_existing_subscribers($existingselector->find_users(''));
}

$PAGE->set_heading($COURSE->fullname);

echo $OUTPUT->header();

echo $bookingoutput->subscriber_selection_form($existingselector, $subscriberselector);


echo $OUTPUT->footer();
?>
