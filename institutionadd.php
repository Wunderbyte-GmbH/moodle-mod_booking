<?php
require_once ("../../config.php");
require_once ("locallib.php");
require_once ("institutionform.class.php");

$courseid = required_param('courseid', PARAM_INT);
$cid = optional_param('cid', '', PARAM_INT);
$delete = optional_param('delete', '', PARAM_INT);

if ($cid != '') {
    $url = new moodle_url('/mod/booking/institutionadd.php',
            array('courseid' => $courseid, 'cid' => $cid));
} else {
    $url = new moodle_url('/mod/booking/institutionadd.php', array('courseid' => $courseid));
}

$PAGE->set_url($url);

$context = context_course::instance($courseid);

if (!$course = $DB->get_record("course", array("id" => $courseid))) {
    print_error('coursemisconf');
}

$PAGE->navbar->add(get_string('addnewcategory', 'booking'));

require_login($courseid, false);

require_capability('mod/booking:addinstance', $context);

$PAGE->set_pagelayout('standard');

$redirecturl = new moodle_url('institutions.php', array('courseid' => $courseid));

if ($delete == 1) {
    $DB->delete_records("booking_institutions", array("id" => $cid));
    $delmessage = get_string('sucesfulldeletedinstitution', 'booking');

    redirect($redirecturl, $delmessage, 5);
}

$mform = new mod_booking_institution_form(null, array('courseid' => $courseid, 'cidd' => $cid));

$default_values = new stdClass();
if ($cid != '') {
    $default_values = $DB->get_record('booking_institutions', array('id' => $cid));
}

$default_values->courseid = $courseid;
$default_values->course = $courseid;

$PAGE->set_title(get_string('addnewinstitution', 'booking'));

if ($mform->is_cancelled()) {
    redirect($redirecturl, '', 0);
} else if ($data = $mform->get_data(true)) {
    $institution = new stdClass();
    $institution->id = $data->id;
    $institution->name = $data->name;

    $institution->course = $data->course;

    if ($institution->id != '') {
        $DB->update_record("booking_institutions", $institution);
    } else {
        $DB->insert_record("booking_institutions", $institution);
    }
    redirect($redirecturl, '', 0);
}

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
// this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
// or on the first display of the form.

$mform->set_data($default_values);
$mform->display();

echo $OUTPUT->footer();
?>