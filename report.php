<?php 
/**
 * Manage bookings
 *
 * @package   Booking
 * @copyright 2011 David Bogner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/
require_once("../../config.php");
require_once("lib.php");

$id         = required_param('id', PARAM_INT);   //moduleid
$download   = optional_param('download', '', PARAM_ALPHA);
$action     = optional_param('action', '', PARAM_ALPHANUM);
$attemptids = my_optional_param_array('attemptid', array(), PARAM_INT); //get array of responses to delete.
$confirm    = optional_param('confirm', '', PARAM_INT);
$optionid   = optional_param('optionid', '', PARAM_INT);

$url = new moodle_url('/mod/booking/report.php', array('id'=>$id));

if ($action !== '') {
	$url->param('action', $action);
}
$PAGE->set_url($url);
$PAGE->requires->css('/mod/booking/styles.css');


if (! $cm = get_coursemodule_from_id('booking', $id)) {
	error("Course Module ID was incorrect");
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}


require_course_login($course, false, $cm);

$context = get_context_instance(CONTEXT_MODULE, $cm->id);
require_capability('mod/booking:readresponses', $context);
$url = new moodle_url('/mod/booking/report.php', array('id'=>$id));

/// Check to see if groups are being used in this booking
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = booking_get_booking($cm, $groupmode)) {
	error("Course module is incorrect");
}

$strbooking = get_string("modulename", "booking");
$strbookings = get_string("modulenameplural", "booking");
$strresponses = get_string("responses", "booking");

add_to_log($course->id, "booking", "report", "report.php?id=$cm->id", "$booking->id",$cm->id);


if ($action == 'deletebookingoption' && $confirm == 1 && has_capability('mod/booking:updatebooking',$context) && confirm_sesskey()) {
	booking_delete_booking_option($booking->id, $optionid); //delete booking_option
	redirect("report.php?id=$cm->id");
} elseif ($action == 'deletebookingoption' && has_capability('mod/booking:updatebooking',$context) && confirm_sesskey()) {
	echo $OUTPUT->header();
	$confirmarray['action'] = 'deletebookingoption';
	$confirmarray['confirm'] = 1;
	$confirmarray['optionid'] = $optionid;
	$continue = $url;
	$continue->params($confirmarray);
	echo $OUTPUT->confirm(get_string('confirmdeletebookingoption','booking'), $continue,$url);
	echo $OUTPUT->footer();
	die;
}
if (data_submitted() && $action == 'delete' && $confirm == 1 && has_capability('mod/booking:deleteresponses',$context) && confirm_sesskey()) {
	booking_delete_responses($attemptids, $booking->id); //delete responses.
	redirect($url);
} elseif (data_submitted() && $action == 'delete' && has_capability('mod/booking:deleteresponses',$context) && confirm_sesskey()) {
	echo $OUTPUT->header();
		foreach($attemptids as $optionid => $attemptobjects){
		if(!is_array($attemptobjects) || empty($attemptobjects)) {
			return false;
		}
		foreach($attemptobjects as $key => $attemptid) {
			$stroptionname = "attemptid[$optionid][$key]";
			$confirmarray[$stroptionname] = $attemptid;
		}
	}
	$confirmarray['action'] = 'delete';
	$confirmarray['confirm'] = 1;
	$continue = $url;
	$continue->params($confirmarray);
	echo $OUTPUT->confirm(get_string('deleteuserfrombooking','booking'),$continue,$url);
	echo $OUTPUT->footer();
	die;
}

if (data_submitted() && $action == 'subscribe' && $confirm == 1 && confirm_sesskey()) {  // subscription confirmed - do it
	booking_subscribe_tocourse($attemptids, $booking->id);
	redirect($url);
} elseif (data_submitted() && $action == 'subscribe' && confirm_sesskey()) { // subscription submitted - confirm it
	echo $OUTPUT->header();
	
	foreach($attemptids as $optionid => $attemptobjects){
		$optionid1 = $optionid;
		if(!is_array($attemptobjects) || empty($attemptobjects)) {
			return false;
		}
		foreach($attemptobjects as $key => $userid) {
			$straddselect = "addselect[$userid]";
			$confirmarray[$straddselect] = $userid;
		}
	}
	$courseid = $DB->get_field('booking_options', 'courseid', array('id' => $optionid1));
	if($courseid != 0){
		$coursecontext = get_context_instance(CONTEXT_COURSE, $courseid);
		$continue = new moodle_url($CFG->wwwroot.'/'.$CFG->admin.'/roles/assign.php', array('contextid' =>$coursecontext->id, 'roleid' => 5, 'add' => 1, 'sesskey' => $USER->sesskey, 'id' => $cm->id));
		$continue->params($confirmarray);
		$cancel = new moodle_url($url);
		echo $OUTPUT->confirm(get_string('subscribeuser','booking'), $continue, $cancel);
	} else {
		error("No course selected for this booking-option", "report.php?id=$cm->id");
	}
	echo $OUTPUT->footer();
	die;
}

if (!$download) {
        $PAGE->navbar->add($strresponses);
        $PAGE->set_title(format_string($booking->name).": $strresponses");
        $PAGE->set_heading($course->fullname);
	echo $OUTPUT->header();

	if ($groupmode) {
		groups_get_activity_group($cm, true);
		groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/booking/report.php?id='.$id);
	}
} else {
	$groupmode = groups_get_activity_groupmode($cm);
}
$bookinglist = booking_get_spreadsheet_data($booking, $cm, $groupmode);

if ($download == "ods" OR $download == "xls" && has_capability('mod/booking:downloadresponses', $context)) {
	if ($action == "all"){
		$filename = clean_filename("$course->shortname ".strip_tags(format_string($booking->name,true)));
	} else {
		$optionname = $booking->option[$action]->text;
		$filename = clean_filename(strip_tags(format_string($optionname,true)));
	}
	if ( $download =="ods"){
		require_once("$CFG->libdir/odslib.class.php");
		$workbook = new MoodleODSWorkbook("-");
		$filename .= '.ods';
	}  else {
		require_once("$CFG->libdir/excellib.class.php");
		$workbook = new MoodleExcelWorkbook("-");
		$filename .= '.xls';
	}


	/// Send HTTP headers
	$workbook->send($filename);
	/// Creating the first worksheet
	$myxls =& $workbook->add_worksheet($strresponses);
	if ( $download =="ods"){
		$cellformat =& $workbook->add_format(array('bg_color' => 'white'));
		$cellformat1 =& $workbook->add_format(array('bg_color' => 'red'));
	} else {
		$cellformat = '';
		$cellformat1 =& $workbook->add_format(array('fg_color' => 'red'));
	}
	/// Print names of all the fields
	$myxls->write_string(0,0,get_string("booking","booking"));
	$myxls->write_string(0,1,get_string("user")." ".get_string("idnumber"));
	$myxls->write_string(0,2,get_string("firstname"));
	$myxls->write_string(0,3,get_string("lastname"));
	$myxls->write_string(0,4,get_string("email"));
	$i=5;
	if ($userprofilefields = $DB->get_records_select('user_info_field', 'id > 0', array(), 'id', 'id, shortname, name' )){
		foreach ($userprofilefields as $profilefield){
			$myxls->write_string(0,$i++,$profilefield->name);
		}
	}
	$myxls->write_string(0,$i++,get_string("group"));
	/// generate the data for the body of the spreadsheet
	$row=1;

	if ($bookinglist && ($action == "all")) { // get list of all booking options
		foreach ($bookinglist as $optionid => $optionvalue) {

			$option_text = booking_get_option_text($booking, $optionid);
			foreach ($bookinglist[$optionid] as $usernumber => $user) {
				if ($usernumber > $booking->option[$optionid]->maxanswers){
					$cellform = $cellformat1;
				} else {
					$cellform = $cellformat;
				}
				if (isset($option_text)) {
					$myxls->write_string($row,0,format_string($option_text,true));
				}
				$myxls->write_string($row,1,$user->id,$cellform);
				$myxls->write_string($row,2,$user->firstname,$cellform);
				$myxls->write_string($row,3,$user->lastname,$cellform);
				$myxls->write_string($row,4,$user->email,$cellform);
				$i=5;
				if ($DB->get_records_select('user_info_data', 'userid = '. $user->id,array(), 'fieldid')){
					foreach ($userprofilefields as $profilefieldid => $profilefield){
						$myxls->write_string($row,$i++,strip_tags($DB->get_field('user_info_data', 'data', array('fieldid' => $profilefieldid, 'userid' => $user->id))),$cellform);
					}
				} else {
					$myxls->write_string($row,$i++,'');
				}
				$studentid=(!empty($user->idnumber) ? $user->idnumber : " ");
				$ug2 = '';
				if ($usergrps = groups_get_all_groups($course->id, $user->id)) {
					foreach ($usergrps as $ug) {
						$ug2 = $ug2. $ug->name;
					}
				}
				//$myxls->write_string($row,12,$ug2);
				$row++;
				$pos=4;
			}
		}
	} elseif ($bookinglist && !empty($bookinglist[$action])) { // get list of one specified booking option: $action is $optionid
		foreach ($bookinglist[$action] as $usernumber => $user) {
			if ($usernumber > $booking->option[$action]->maxanswers){
				$cellform = $cellformat1;
			} else {
				$cellform = $cellformat;
			}
			if (isset($option_text)) {
				$myxls->write_string($row,0,format_string($option_text,true));
			}
			$myxls->write_string($row,1,$user->id,$cellform);
			$myxls->write_string($row,2,$user->firstname,$cellform);
			$myxls->write_string($row,3,$user->lastname,$cellform);
			$myxls->write_string($row,4,$user->email,$cellform);
			$i=5;
			if ($DB->get_records_select('user_info_data', 'userid = '. $user->id, array(), 'fieldid')){
				foreach ($userprofilefields as $profilefieldid => $profilefield){
					$myxls->write_string($row,$i++,strip_tags($DB->get_field('user_info_data', 'data', array('fieldid' => $profilefieldid, 'userid' => $user->id))),$cellform);
				}
			} else {
				$myxls->write_string($row,$i++,'asdf');
			}
			$studentid=(!empty($user->idnumber) ? $user->idnumber : " ");
			$ug2 = '';
			if ($usergrps = groups_get_all_groups($course->id, $user->id)) {
				foreach ($usergrps as $ug) {
					$ug2 = $ug2. $ug->name;
				}
			}
			//$myxls->write_string($row,12,$ug2);
			$row++;
			$pos=4;
		}
	}
	/// Close the workbook
	$workbook->close();
	exit;
}

    $renderer = $PAGE->get_renderer('mod_booking');
    echo $renderer->booking_show_results($booking, $course, $cm, $bookinglist);
echo "<br />";
echo $OUTPUT->box_start('box mdl-align');
if(has_capability('mod/booking:updatebooking', $context)){
	$url->param('optionid', 'add');
	$OUTPUT->single_button($url, get_string('addnewbookingoption','booking'));
}

//now give links for downloading spreadsheets.
if (!empty($bookinglist) && has_capability('mod/booking:downloadresponses',$context)) {
	/// Download spreadsheet for each booking option and all booking options
	echo $html = html_writer::tag('h2', get_string('download', 'booking').' '.get_string('userdata'), array('class' => 'main'));
	$optionstochoose = array( 'all' => get_string('allbookingoptions', 'booking'));
	foreach ($booking->option as $option){
		$optionstochoose[$option->id] = $option->text;
	}

        $options = array();
        $options["id"] = "$cm->id";
        $options["download"] = "ods";
        $options['action'] = "all";
        $button =  $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadods"));
        $downloadoptions[] = html_writer::tag('div', $button, array('class'=>'reportoption'));

        $options["download"] = "xls";
        $button = $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadexcel"));
        $downloadoptions[] = html_writer::tag('div', $button, array('class'=>'reportoption'));

        $downloadlist = html_writer::tag('div', implode('', $downloadoptions));
        $downloadlist .= html_writer::tag('div', '', array('class'=>'clearfloat'));
        echo html_writer::tag('div',$downloadlist, array('class'=>'downloadreport'));
}
echo $OUTPUT->box_end();
echo $OUTPUT->footer();

?>
