<?php 

require_once('../../config.php');
require_once($CFG->libdir.'/gdlib.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/mod/booking/editprofileform.class.php');
require_once($CFG->dirroot.'/user/editlib.php');
require_once($CFG->dirroot.'/user/profile/lib.php');

$cmid   = required_param('cmid', PARAM_INT);    // course module id
$courseid = required_param('courseid', PARAM_INT);   // course id (defaults to Site)

$url = new moodle_url('/mod/booking/edituserprofile.php', array('id'=>$cmid));

$PAGE->set_url($url);
$PAGE->requires->css('/mod/booking/styles.css');

if (! $course = $DB->get_record("course", array("id" => $courseid))) {
	print_error('coursemisconf');
}
if (! $cm = get_coursemodule_from_id('booking', $cmid)) {
	print_error('invalidcoursemodule');
}
require_course_login($course, false, $cm);

if ($course->id == SITEID) {
	$coursecontext = get_context_instance(CONTEXT_SYSTEM);   // SYSTEM context
} else {
	$coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);   // Course context
}
$systemcontext = get_context_instance(CONTEXT_SYSTEM);

// editing existing user
require_capability('moodle/user:editownprofile', $systemcontext);
if (!$user = $DB->get_record('user', array('id' => $USER->id))) {
	error('User ID was incorrect');
}

// remote users cannot be edited
if ($user->id != -1 and is_mnet_remote_user($user)) {
	redirect($CFG->wwwroot . "/user/view.php?id=$USER->id&course={$course->id}");
}

if ($user->id != $USER->id and is_primary_admin($user->id)) {  // Can't edit primary admin
	print_error('adminprimarynoedit');
}

if (isguestuser($user->id)) { // the real guest user can not be edited
	print_error('guestnoeditprofileother');
}

//Load custom profile fields data
profile_load_data($user);

//user interests separated by commas
if (!empty($CFG->usetags)) {
	require_once($CFG->dirroot.'/tag/lib.php');
	$user->interests = tag_get_tags_csv('user', $USER->id, TAG_RETURN_TEXT); // formslib uses htmlentities itself
}

//create form
$userform = new mod_booking_userprofile_form();
$user->cmid = $cmid;
$userform->set_data($user);

if ($usernew = $userform->get_data()) {
	add_to_log($course->id, 'user', 'update', "view.php?id=$user->id&course=$course->id", '');

	// use all the profile settings from $user and only replace user_profile_fields;
	$usernew->timemodified = time();

	// save custom profile fields data
	profile_save_data($usernew);

	// reload from db
	$usernew = $DB->get_record('user', array('id' => $usernew->id));

	events_trigger('user_updated', $usernew);

	redirect("$CFG->wwwroot/mod/booking/view.php?id=$cmid");
}

// print header
$streditmyprofile = get_string('editmyprofile');
$strparticipants  = get_string('participants');
$strnewuser       = get_string('newuser');
$userfullname     = fullname($user, true);

$PAGE->set_url($url);
$PAGE->set_title(get_string('edituser'));
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
    
/// Finally display THE form
$userform->display();

/// and proper footer
echo $OUTPUT->footer();


?>
