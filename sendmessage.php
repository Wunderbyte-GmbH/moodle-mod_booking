<?php
require_once ("../../config.php");
require_once ("lib.php");
require_once ("sendmessageform.class.php");

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$uids = required_param('uids', PARAM_RAW);

$url = new moodle_url('/mod/booking/sendmessage.php', 
        array('id' => $id, 'optionid' => $optionid, 'uids' => $uids));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

$strbooking = get_string('modulename', 'booking');

// if (!$context = get_context_instance(CONTEXT_MODULE, $cm->id)) {
if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:communicate', $context);

$default_values = new stdClass();
$default_values->optionid = $optionid;
$default_values->id = $id;
$default_values->uids = $uids;

$redirecturl = new moodle_url('report.php', array('id' => $id, 'optionid' => $optionid));

$mform = new mod_booking_sendmessage_form();

$PAGE->set_pagelayout('standard');

$PAGE->set_title(get_string('sendcustommessage', 'booking'));

if ($mform->is_cancelled()) {
    redirect($redirecturl, '', 0);
} else if ($data = $mform->get_data(true)) {
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