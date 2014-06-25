<?php
require_once("../../config.php");
require_once("lib.php");
require_once("categoriesform.class.php");

$courseid         = required_param('courseid', PARAM_INT);
$cid    = optional_param('cid', '', PARAM_INT);
$delete    = optional_param('delete', '', PARAM_INT);

if ($cid != '') {
    $url = new moodle_url('/mod/booking/categoryadd.php', array('courseid' => $courseid, 'cid' => $cid));
} else {
    $url = new moodle_url('/mod/booking/categoryadd.php', array('courseid' => $courseid));
}

$PAGE->set_url($url);

$context = context_course::instance($courseid);

if (! $course = $DB->get_record("course", array("id" => $courseid))) {
	print_error('coursemisconf');
}

$PAGE->navbar->add(get_string('addnewcategory', 'booking'));

require_login($courseid, false);

require_capability('mod/booking:addinstance', $context);

$PAGE->set_pagelayout('standard');

$redirecturl = new moodle_url('categories.php', array('courseid' => $courseid));

if ($delete == 1) {
    $candelete = TRUE;
    
    $categories = $DB->get_records("booking_category", array("cid" => $cid));
    if (count((array)$categories) > 0) {
        $candelete = FALSE;
        $delmessage = get_string('deletesubcategory','booking');
    }

    $bookings = $DB->get_records("booking", array("categoryid" => $cid));
    if (count((array)$bookings) > 0) {
        $candelete = FALSE;
        $delmessage = get_string('usedinbooking','booking');
    }    

    if ($candelete) {
        $DB->delete_records("booking_category", array("id" => $cid));
        $delmessage = get_string('sucesfulldeleted','booking');
    }
    redirect($redirecturl,$delmessage,5);
}

$mform = new mod_booking_categories_form(null, array('courseid'=>$courseid, 'cidd' => $cid));

$default_values  = new stdClass();  
if ($cid != '') {
    $default_values = $DB->get_record('booking_category', array('id' => $cid));
}

$default_values->courseid = $courseid;
$default_values->course = $courseid;

$PAGE->set_title(get_string('addnewcategory','booking'));

if($mform->is_cancelled()) {	
	redirect($redirecturl,'',0);
} else if($data = $mform->get_data(true)) {
    $category = new stdClass();  
    $category->id = $data->id;
    $category->name = $data->name;
    if ($cid == $data->id) {
        $category->cid = 0;
    } else {
        $category->cid = $data->cid;
    }
    $category->course = $data->course;

    if ($category->id != '') {
        $DB->update_record("booking_category", $category);
    } else {
        $DB->insert_record("booking_category", $category);
    }
    redirect($redirecturl,'',0);
} 

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
	// this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
	// or on the first display of the form.

$mform->set_data($default_values);
$mform->display();

echo $OUTPUT->footer();

?>