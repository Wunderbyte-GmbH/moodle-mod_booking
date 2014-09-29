<?php
require_once("../../config.php");
require_once("lib.php");
require_once("bookingform.class.php");

$id         = required_param('id', PARAM_INT);                 // Course Module ID
$optionid   = optional_param('optionid','', PARAM_ALPHANUM);
$sesskey    = optional_param('sesskey', '', PARAM_INT);

$url = new moodle_url('/mod/booking/editoptions.php', array('id'=>$id));
$PAGE->set_url($url);


if (! $cm = get_coursemodule_from_id('booking', $id)) {
	print_error("Course Module ID was incorrect");
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = booking_get_booking($cm,'')) {
	error("Course module is incorrect");
}

$strbooking = get_string('modulename', 'booking');
$strbookings = get_string('modulenameplural', 'booking');

//if (!$context = get_context_instance(CONTEXT_MODULE, $cm->id)) {
if (!$context = context_module::instance($cm->id)) {
	print_error('badcontext');
}

if (!isset($optionid) or empty($optionid)){
	print_error("Optionid is not correct or not set");
}
require_capability('mod/booking:updatebooking', $context);



$mform = new mod_booking_bookingform_form();

if($optionid == 'add'){
	$default_values = $booking;
	$default_values->optionid = "add";
	$default_values->bookingid = $booking->id;
	$default_values->id = $cm->id;
	$default_values->text = '';
} else if ($default_values = $DB->get_record('booking_options', array('bookingid' => $booking->id, 'id' => $optionid))){
   $default_values->optionid = $optionid;
   $default_values->description = array('text'=>$default_values->description, 'format'=>FORMAT_HTML);
   $default_values->id = $cm->id;
   if($default_values->bookingclosingtime){
   	   $default_values->restrictanswerperiod = "checked";
   }
   if($default_values->coursestarttime){
   	   $default_values->startendtimeknown = "checked";
   }   
   
} else {
	print_error('This booking option does not exist');
}

if ($mform->is_cancelled()){
	$redirecturl = new moodle_url('view.php', array('id' => $cm->id));
	redirect($redirecturl,'',0);
} else if ($fromform=$mform->get_data()){
	//validated data.
	if(confirm_sesskey() && has_capability('mod/booking:updatebooking', $context)){
		if(!isset($fromform->limitanswers)) {			
		   $fromform->limitanswers = 0;
		}
		if(!isset($fromform->daystonotify)) {
		   $fromform->daystonotify = 0;
		}
		booking_update_options($fromform);
		if(isset($fromform->submittandaddnew)){
			$redirecturl = new moodle_url('editoptions.php', array('id' => $cm->id, 'optionid' => 'add'));
			redirect($redirecturl,get_string('changessaved'),0);
		} else {
			$redirecturl = new moodle_url('view.php', array('id' => $cm->id));
			redirect($redirecturl,get_string('changessaved'),0);
		}
	}
} else {
$PAGE->set_title(format_string($booking->name));
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
	// this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
	// or on the first display of the form.

	$mform->set_data($default_values);
	$mform->display();
}
    echo $OUTPUT->footer();

?>
