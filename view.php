<?php 

require_once("../../config.php");
require_once("lib.php");
require_once($CFG->libdir . '/completionlib.php');

$id         = required_param('id', PARAM_INT);                 // Course Module ID
$action     = optional_param('action', '', PARAM_ALPHA);
$optionid = optional_param('optionid', '', PARAM_INT); 
$confirm = optional_param('confirm', '',PARAM_INT); 
$answer = optional_param('answer', '',PARAM_ALPHANUM);
$sorto = optional_param('sort', '', PARAM_INT);

$sort = '';
$sorturl = new moodle_url('/mod/booking/view.php', array('id' => $id, 'sort' => 0));

if ($sorto == 1) {
	$sort = 'coursestarttime ASC';
	$sorturl = new moodle_url('/mod/booking/view.php', array('id' => $id, 'sort' => 0));
} else if ($sorto == 0) {
	$sort = 'coursestarttime DESC';
	$sorturl = new moodle_url('/mod/booking/view.php', array('id' => $id, 'sort' => 1));
}

$url = new moodle_url('/mod/booking/view.php', array('id'=>$id));

$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('booking', $id)) {
	print_error('invalidcoursemodule');
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}


require_course_login($course, false, $cm);


if (!$booking = booking_get_booking($cm, $sort)) {
	print_error("Course module is incorrect");
}

$strbooking = get_string('modulename', 'booking');
$strbookings = get_string('modulenameplural', 'booking');

if (!$context = context_module::instance($cm->id)) {
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
		if(booking_delete_singlebooking($answer,$booking,$optionid,$newbookeduser,$cm->id)){
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
	$deletemessage = $booking->option[$optionid]->text."<br />".$booking->option[$optionid]->coursestarttimetext." - ".$booking->option[$optionid]->courseendtimetext;
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
if (has_capability('moodle/user:editownprofile', $context, NULL, false) and booking_check_user_profile_fields($USER->id) and !has_capability('moodle/site:config', $context)){
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
$booking = booking_get_booking($cm, $sort);

/// Display the booking and possibly results
add_to_log($course->id, "booking", "view", "view.php?id=$cm->id", $booking->id, $cm->id);


$bookinglist = booking_get_spreadsheet_data($booking, $cm);

echo '<div class="clearer"></div>';

echo $OUTPUT->box_start('generalbox boxaligncenter boxwidthwide');
echo html_writer::tag('div', format_module_intro('booking', $booking, $cm->id), array('class' => 'intro'));

if (!empty($booking->duration)) {
	echo html_writer::start_tag('div');
	echo html_writer::tag('label', get_string('eventduration','booking').': ', array('class' => 'bold'));
	echo html_writer::tag('span', $booking->duration);
	echo html_writer::end_tag('div');
}

if (!empty($booking->points) && ($booking->points != 0)) {
	echo html_writer::start_tag('div');
	echo html_writer::tag('label', get_string('eventpoints','booking').': ', array('class' => 'bold'));
	echo html_writer::tag('span', $booking->points);
	echo html_writer::end_tag('div');
}

if (!empty($booking->organizatorname)) {
	echo html_writer::start_tag('div');
	echo html_writer::tag('label', get_string('organizatorname','booking').': ', array('class' => 'bold'));
	echo html_writer::tag('span', $booking->organizatorname);
	echo html_writer::end_tag('div');
}

if (!empty($booking->pollurl)) {
	echo html_writer::start_tag('div');
	echo html_writer::tag('label', get_string('pollurl','booking').': ', array('class' => 'bold'));
	echo html_writer::tag('span', html_writer::link($booking->pollurl, $booking->pollurl, array()));
	echo html_writer::end_tag('div');
}

$out = array();
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_booking', 'myfilemanager', $booking->id);

if (count($files) > 0) {
	echo html_writer::start_tag('div');
	echo html_writer::tag('label', get_string("attachedfiles", "booking").': ', array('class' => 'bold'));

	foreach ($files as $file) {
		if ($file->get_filesize() > 0) {
			$filename = $file->get_filename();
			//$url = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
			$url = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$file->get_contextid().'/'.$file->get_component().'/'.$file->get_filearea().'/'.$file->get_itemid().'/'.$file->get_filename());
			$out[] = html_writer::link($url, $filename);
		}		
	}
	echo html_writer::tag('span', implode(', ', $out));
	echo html_writer::end_tag('div');
}

if (!empty($CFG->usetags)) {
	$tags = tag_get_tags_array('booking', $booking->id);

	$links = array();
	foreach ($tags as $tagid=>$tag) {
		$url = new moodle_url('tag.php', array('id' => $id, 'tag'=>$tag));
		$links[] = html_writer::link($url, $tag, array());
	}

	if (!empty($tags)) {
		echo html_writer::start_tag('div');
		echo html_writer::tag('label', get_string('tags').': ', array('class' => 'bold'));
		echo html_writer::tag('span', implode(', ', $links));
		echo html_writer::end_tag('div');
	}
}

if ($booking->categoryid > 0) {		
	$category = $DB->get_record('booking_category', array('id' => $booking->categoryid));

	echo html_writer::start_tag('div');
	echo html_writer::tag('label', get_string('category', 'booking').': ', array('class' => 'bold'));
	$url = new moodle_url('category.php', array('id' => $id, 'category'=>$category->id));		
	echo html_writer::tag('span', html_writer::link($url, $category->name, array()));
	echo html_writer::end_tag('div');
}

if (strlen($booking->bookingpolicy) > 0) {
	$link = new moodle_url('/mod/booking/viewpolicy.php', array('id'=>$booking->id));
	echo $OUTPUT->action_link($link, get_string("bookingpolicy", "booking"), new popup_action ('click', $link));
}

echo $OUTPUT->box_end();


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
	if ($booking->timeopen > $timenow && !has_capability('mod/booking:updatebooking', $context)) {
		echo $OUTPUT->box(get_string("notopenyet", "booking", userdate($booking->timeopen, get_string('strftimedate'))), "center");
		echo $OUTPUT->footer();
		exit;
	} else if ($timenow > $booking->timeclose && !has_capability('mod/booking:updatebooking', $context)) {
		echo $OUTPUT->box(get_string("expired", "booking", userdate($booking->timeclose)), "center");
		$bookingopen = false;
	}
}

if ( !$current and $bookingopen and has_capability('mod/booking:choose', $context) ) {

	echo $OUTPUT->box(booking_show_maxperuser($booking, $USER, $bookinglist), 'box mdl-align');

	if ($action=='mybooking'){
		$message = "<a href=\"view.php?id=$cm->id\">".get_string('showallbookings','booking')."</a>";
		echo $OUTPUT->box($message,'box mdl-align');
		booking_show_form($booking, $USER, $cm, $bookinglist,1,$sorturl);
	} else {
		$message = "<a href=\"view.php?id=$cm->id&action=mybooking\">".get_string('showmybookings','booking')."</a>";
		echo $OUTPUT->box($message,'box mdl-align');
		booking_show_form($booking, $USER, $cm, $bookinglist,0,$sorturl);
	}

	$bookingformshown = true;
} else {
	$bookingformshown = false;
}

if (!$bookingformshown) {
	echo $OUTPUT->box(get_string("norighttobook", "booking"));
}
if (has_capability('mod/booking:updatebooking', $context)) {
	$addoptionurl = new moodle_url('editoptions.php', array('id'=>$cm->id, 'optionid'=> 'add'));
	echo '<div style="width: 100%; text-align: center;">';
	echo $OUTPUT->single_button($addoptionurl,get_string('addnewbookingoption','booking'),'get');
	echo '</div>';
}
echo $OUTPUT->box("<a href=\"http://www.edulabs.org\">".get_string('createdby','booking')."</a>",'box mdl-align');
echo $OUTPUT->footer();


?>