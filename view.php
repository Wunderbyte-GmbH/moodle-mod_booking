<?php  // $Id: view.php,v 1.102.2.11 2011/02/04 17:12:18 dasistwas Exp $

require_once("../../config.php");
require_once("lib.php");
require_once($CFG->libdir . '/completionlib.php');

$id         = required_param('id', PARAM_INT);                 // Course Module ID
$action     = optional_param('action', '', PARAM_ALPHA);
$optionid = optional_param('optionid', '', PARAM_INT); 
$confirm = optional_param('confirm', '',PARAM_INT); 
$answer = optional_param('answer', '',PARAM_ALPHANUM);

$url = new moodle_url('/mod/booking/view.php', array('id'=>$id));

$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('booking', $id)) {
	print_error('invalidcoursemodule');
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}


require_course_login($course, false, $cm);


if (!$booking = booking_get_booking($cm)) {
	print_error("Course module is incorrect");
}

$strbooking = get_string('modulename', 'booking');
$strbookings = get_string('modulenameplural', 'booking');

if (!$context = get_context_instance(CONTEXT_MODULE, $cm->id)) {
	print_error('badcontext');
}
// check if booking options have already been set or if they are still empty
$records = $DB->get_records('booking_options', array('bookingid' => $booking->id));
if (empty($records)) {      // Brand new database!
	if (has_capability('mod/booking:updatebooking', $context)) {
		redirect($CFG->wwwroot.'/mod/booking/editoptions.php?id='.$cm->id.'&optionid=add');  // Redirect to field entry
	} else {
		print_error("There are no booking options available yet");
	}
}


// check if data has been submitted to be processed
if ($action == 'delbooking' and confirm_sesskey() && $confirm == 1 and has_capability('mod/booking:choose', $context) and ($booking->allowupdate or has_capability('mod/booking:deleteresponses', $context))) {
	if ($answer = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $USER->id, 'optionid' => $optionid))) {
		$newbookeduser = booking_check_statuschange($optionid, $booking, $USER->id, $cm->id);
		if(booking_delete_singlebooking($answer->id,$booking,$optionid,$newbookeduser,$cm->id)){
			echo $OUTPUT->header();
			$contents = get_string('bookingdeleted','booking');
			$contents .= $OUTPUT->single_button($url, get_string('continue'),'get');
			echo $OUTPUT->box($contents, 'box generalbox', 'notice');
			echo $OUTPUT->footer();
			die;
		}
	}
} elseif ($action == 'delbooking'  and confirm_sesskey() and has_capability('mod/booking:choose', $context) and ($booking->allowupdate or has_capability('mod/booking:deleteresponses', $context))){    //print confirm delete form
	echo $OUTPUT->header();
	$options = array('id' => $cm->id, 'action' => 'delbooking', 'confirm' => 1, 'optionid' => $optionid, 'sesskey' => $USER->sesskey);
	$deletemessage = $booking->option[$optionid]->text."<br />".$booking->option[$optionid]->coursestarttime." - ".$booking->option[$optionid]->courseendtime;
	echo $OUTPUT->confirm(get_string('deletebooking','booking',$deletemessage), new moodle_url('view.php',$options),$url);
	echo $OUTPUT->footer();
	die;
}

// before processing data user has to agree to booking policy and confirm booking
if ($form = data_submitted() && has_capability('mod/booking:choose', $context) && confirm_sesskey() && $confirm != 1 && $answer) {
	booking_confirm_booking($answer, $booking, $USER, $cm,$url);
	die;
}

$PAGE->set_title(format_string($booking->name));
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

// check if custom user profile fields are required and redirect to complete them if necessary
if (has_capability('moodle/user:editownprofile', $context, NULL, false) and booking_check_user_profile_fields($USER->id) and !has_capability('moodle/site:doanything', $context)){
	$contents = get_string('mustfilloutuserinfobeforebooking','booking');
	$contents .= $OUTPUT->single_button(new moodle_url("edituserprofile.php", array('cmid' => $cm->id, 'courseid' => $course->id)), get_string('continue'),'get');
	echo $OUTPUT->box($contents, 'box generalbox', 'notice');
	echo $OUTPUT->footer();
	die;
}

/// Submit any new data if there is any
if ($form = data_submitted() && has_capability('mod/booking:choose', $context)) {
	$timenow = time();
	if (!empty($answer)) {
		if(booking_user_submit_response($answer, $booking, $USER, $course->id, $cm)){
			$contents = get_string('bookingsaved','booking');
			if ($booking->sendmail){
				$contents .= "<br />".get_string('mailconfirmationsent', 'booking').".";
			}
			$contents .= $OUTPUT->single_button(new moodle_url("view.php", array('id' => $cm->id)),get_string('continue'),'get');
			echo $OUTPUT->box($contents,'box generalbox', 'notice');
			echo $OUTPUT->footer();
			die;
		} elseif (is_int($answer)) {
			$contents = get_string('bookingmeanwhilefull','booking')." ".$booking->option[$answer]->text;
			$contents .= $OUTPUT->single_button(new moodle_url("view.php", array('id' => $cm->id)),'get');
			echo $OUTPUT->box($contents,'box generalbox', 'notice');
			echo $OUTPUT->footer();
			die;
		}
	} else {
		$contents = get_string('nobookingselected','booking');
		$contents .= $OUTPUT->single_button(new moodle_url("view.php", array('id' => $cm->id)),'get');
		echo $OUTPUT->box($contents,'box generalbox', 'notice');
		echo $OUTPUT->footer();
		die;
	}
}
// we have to refresh $booking as it is modified by submitted data;
$booking = booking_get_booking($cm);

/// Display the booking and possibly results
add_to_log($course->id, "booking", "view", "view.php?id=$cm->id", $booking->id, $cm->id);


$bookinglist = booking_get_spreadsheet_data($booking, $cm);

echo '<div class="clearer"></div>';

if ($booking->intro) {
	echo $OUTPUT->box(format_module_intro('booking', $booking, $cm->id,true), 'generalbox', 'intro');
}
//download spreadsheet of all users
if (has_capability('mod/booking:downloadresponses',$context)) {
	/// Download spreadsheet for all booking options
	echo $html = html_writer::tag('div', get_string('downloadallresponses', 'booking').': ', array('style' => 'width:100%; font-weight: bold; text-align: right;'));
	$optionstochoose = array( 'all' => get_string('allbookingoptions', 'booking'));
	foreach ($booking->option as $option){
		$optionstochoose[$option->id] = $option->text;
	}
	$options = array();
	$options["id"] = "$cm->id";
	$options["optionid"] = 0;
	$options["download"] = "ods";
	$options['action'] = "all";
	$button =  $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadods"));
	echo '<div style="width: 100%; text-align: right; display:table;">';
	echo html_writer::tag('span', $button, array('style' => 'width: 100%; text-align: right; display:table-cell;'));
	$options["download"] = "xls";
	$button = $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadexcel"));
	echo html_writer::tag('span', $button, array('style' => 'text-align: right; display:table-cell;'));
	echo '</div>';
}

$current = false;  // Initialise for later
//if user has already made a selection, show their selected answer.

/// Print the form
$bookingopen = true;
$timenow = time();
if ($booking->timeclose !=0) {
	if ($booking->timeopen > $timenow ) {
		echo $OUTPUT->box(get_string("notopenyet", "booking", userdate($booking->timeopen)), "center");
		echo $OUTPUT->footer();
		exit;
	} else if ($timenow > $booking->timeclose) {
		echo $OUTPUT->box(get_string("expired", "booking", userdate($booking->timeclose)), "center");
		$bookingopen = false;
	}
}

if ( !$current and $bookingopen and has_capability('mod/booking:choose', $context) ) {

	if ($action=='mybooking'){
		$message = "<a href=\"view.php?id=$cm->id\">".get_string('showallbookings','booking')."</a>";
		echo $OUTPUT->box($message,'box mdl-align');
		booking_show_form($booking, $USER, $cm, $bookinglist,1);
	} else {
		$message = "<a href=\"view.php?id=$cm->id&action=mybooking\">".get_string('showmybookings','booking')."</a>";
		echo $OUTPUT->box($message,'box mdl-align');
		booking_show_form($booking, $USER, $cm, $bookinglist,0);
	}

	$bookingformshown = true;
} else {
	$bookingformshown = false;
}

if (!$bookingformshown) {
	echo $OUTPUT->box(get_string("norighttobook", "booking"));
}
		echo $OUTPUT->box("<a href=\"http://www.edulabs.org\">".get_string('createdby','booking')."</a>",'box mdl-align');
echo $OUTPUT->footer();


?>
