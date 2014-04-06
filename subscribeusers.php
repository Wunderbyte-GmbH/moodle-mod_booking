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

$id = required_param('id', PARAM_INT); // course_module ID
$optionid = required_param('optionid', PARAM_INT); //
$subscribe = optional_param('subscribe', false, PARAM_BOOL);
$unsubscribe = optional_param('unsubscribe', false, PARAM_BOOL);
$agree = optional_param('agree', false, PARAM_BOOL);

$cm = get_coursemodule_from_id('booking', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);

$bookingoption = new booking_option($id, $optionid);
//$booking = booking_get_booking($cm);

require_login($course,true,$cm);

add_to_log($course->id, "booking", "subscribeusers", "subscribeusers.php?id=$cm->id", "$bookingoption->id");

/// Print the page header
$context = context_module::instance($cm->id);
$PAGE->set_context($context);

require_capability('mod/booking:subscribeusers', $context);

$url = new moodle_url('/mod/booking/subscribeusers.php', array('id'=>$id, 'optionid' => $optionid));
$errorurl = new moodle_url('/mod/booking/view.php', array('id'=>$id));

$PAGE->set_url($url);
$PAGE->set_title(get_string('modulename', 'booking'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->navbar->add(get_string('booking:subscribeusers', 'booking'),$url);



if(!$agree && (!empty($bookingoption->booking->bookingpolicy))){
    echo $OUTPUT->header();
	$alright = false;
	$message = "<p><b>".get_string('agreetobookingpolicy','booking').":</b></p>";
	$message .= "<p>".$bookingoption->booking->bookingpolicy."<p>";
	$continueurl = new moodle_url($PAGE->url->out(false,array('agree' => 1)));
	$continue = new single_button($continueurl, get_string('continue'),'get');
	$cancel = new single_button($errorurl, get_string('cancel'),'get');;
	echo $OUTPUT->confirm($message,$continue,$cancel);
	echo $OUTPUT->footer();
	die();
} else {
	$currentgroup = groups_get_course_group($course);
	if($currentgroup){
		$groupmembers = groups_get_members($currentgroup,'u.id');
	}
	$options = array('bookingid'=>$cm->instance, 'currentgroup'=>$currentgroup, 'accesscontext'=>$context, 'optionid'=>$optionid, 'cmid' =>$cm->id, 'course' => $course,'potentialusers'=>$bookingoption->potentialusers);

	$bookingoutput = $PAGE->get_renderer('mod_booking');
	
	$existingoptions = $options;
	$existingoptions['potentialusers'] = $bookingoption->bookedvisibleusers;

	$existingselector = new booking_existing_user_selector('removeselect', $existingoptions);

	$subscriberselector = new booking_potential_user_selector('addselect', $options);

	if (data_submitted()) {
		require_sesskey();
		/** It has to be one or the other, not both or neither */
		if (!($subscribe xor $unsubscribe)) {
			//print_error('invalidaction');
		}
		if ($subscribe) {
			$users = $subscriberselector->get_selected_users();
			if($currentgroup AND !has_capability('moodle/site:accessallgroups', $context)){
				$usersofgroup = array_intersect_key($users, $groupmembers);
				$usersallowed = (count($users) === count($usersofgroup));
			} else {
				$usersallowed = true;
			}
			// compare if selected users are members of the currentgroup if person has not the
			// right to access all groups
			if($usersallowed AND (groups_is_member($currentgroup,$USER->id) OR has_capability('moodle/site:accessallgroups', $context))){
				foreach ($users as $user) {
					if (!$bookingoption->user_submit_response($user)) {
						print_error('bookingmeanwhilefull', 'booking', $errorurl->out() , $user->id);
					}
				}
			} else {
				print_error('invalidaction');
			}
		} else if ($unsubscribe) {
			$users = $existingselector->get_selected_users();
			foreach ($users as $user) {
				$newbookeduser = booking_check_statuschange($optionid, $bookingoption->booking, $user->id, $cm->id);
				$answer = $DB->get_record('booking_answers', array('bookingid' => $cm->instance, 'userid' => $user->id, 'optionid' => $optionid));
				if(!booking_delete_singlebooking($answer,$bookingoption->booking,$optionid,$newbookeduser,$cm->id)){
					print_error('cannotremovesubscriber', 'forum',  $errorurl->out(), $user->id);
				} 
			}
		}
		$subscriberselector->invalidate_selected_users();
		$existingselector->invalidate_selected_users();
		$bookingoption->update_booked_users();
		$subscriberselector->set_potential_users($bookingoption->potentialusers);
		$existingselector->set_potential_users($bookingoption->bookedvisibleusers);
	}

}
echo $OUTPUT->header();
echo $bookingoutput->subscriber_selection_form($existingselector, $subscriberselector);
echo $OUTPUT->footer();
?>
