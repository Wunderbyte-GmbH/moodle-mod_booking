<?php
require_once("../../config.php");
require_once("lib.php");
require_once("sendmessageform.class.php");

$id         = required_param('id', PARAM_INT);
$optionid   = optional_param('optionid','', PARAM_INT);

$url = new moodle_url('/mod/booking/sendmessage.php', array('id'=>$id, 'optionid' => $optionid, 'uids' => $uids));
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

$default_values  = new stdClass();
$default_values->optionid = $optionid;
$default_values->id = $id;

$redirecturl = new moodle_url('report.php', array('id' => $id, 'optionid' => $optionid));

$mform = new mod_booking_sendmessage_form();

$PAGE->set_pagelayout('standard');

$PAGE->set_title(get_string('sendcustommessage','booking'));

if($mform->is_cancelled()) {	
	redirect($redirecturl,'',0);
} else if($data = $mform->get_data(true)) {
	booking_sendcustommessage($optionid, $data->subject, $data->message, unserialize($uids));

    redirect($redirecturl, get_string('messagesend', 'booking'), 5);
} 

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

echo $OUTPUT->heading(get_string("sendcustommessage", "booking"), 2);

$mform->set_data($default_values);
$mform->display();

echo $OUTPUT->footer();
?>