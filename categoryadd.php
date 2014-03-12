<?php
require_once("../../config.php");
require_once("lib.php");
require_once("categoriesform.class.php");

$courseid         = required_param('courseid', PARAM_INT);
$url = new moodle_url('/mod/booking/categoryadd.php', array('courseid' => $courseid));
$PAGE->set_url($url);

$context = context_course::instance($courseid);

if (! $course = $DB->get_record("course", array("id" => $courseid))) {
	print_error('coursemisconf');
}

$PAGE->navbar->add(get_string('addnewcategory', 'booking'));

require_login($courseid, false);

require_capability('mod/booking:addinstance', $context);

$PAGE->set_pagelayout('standard');

$mform = new mod_booking_categories_form(null, array('courseid'=>$courseid));

$redirecturl = new moodle_url('categories.php', array('courseid' => $courseid));

$PAGE->set_title(get_string('addnewcategory','booking'));

if($mform->is_cancelled()) {	
	redirect($redirecturl,'',0);
} else if($data = $mform->get_data(true)) {
    $category = new stdClass();
    $category->name = $data->name;
    $category->cid = $data->cid;
    $category->course = $data->course;

    $DB->insert_record("booking_category", $category);
    redirect($redirecturl,'',0);
} 

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
	// this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
	// or on the first display of the form.

$mform->set_data(array('courseid'=> $courseid, 'course' => $courseid));
$mform->display();

echo $OUTPUT->footer();

?>