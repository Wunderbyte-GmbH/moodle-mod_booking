<?php 
/**
 * Manage bookings
 *
 * @package   Booking
 * @copyright 2012 David Bogner www.edulabs.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/
require_once("../../config.php");
require_once("lib.php");
require_once("bookingmanageusers.class.php");

$id         = required_param('id', PARAM_INT);   //moduleid
$optionid   = required_param('optionid', PARAM_INT);
$download   = optional_param('download', '', PARAM_ALPHA);
$action     = optional_param('action', '', PARAM_ALPHANUM);
$confirm    = optional_param('confirm', '', PARAM_INT);

$url = new moodle_url('/mod/booking/report.php', array('id'=>$id));

if ($action !== '') {
	$url->param('action', $action);
}
$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('booking', $id)) {
	error("Course Module ID was incorrect");
}
if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}

require_course_login($course, false, $cm);

if (!$booking = booking_get_booking($cm)) {
	print_error("Course module is incorrect");
}

$context = get_context_instance(CONTEXT_MODULE, $cm->id);
require_capability('mod/booking:readresponses', $context);
$url = new moodle_url('/mod/booking/report.php', array('id'=>$id,'optionid'=>$optionid));

$strbooking = get_string("modulename", "booking");
$strbookings = get_string("modulenameplural", "booking");
$strresponses = get_string("responses", "booking");

add_to_log($course->id, "booking", "report", "report.php?id=$cm->id", "$booking->id",$cm->id);

if ($action == 'deletebookingoption' && $confirm == 1 && has_capability('mod/booking:updatebooking',$context) && confirm_sesskey()) {
	booking_delete_booking_option($booking, $optionid); //delete booking_option
	redirect("view.php?id=$cm->id");
} elseif ($action == 'deletebookingoption' && has_capability('mod/booking:updatebooking',$context) && confirm_sesskey()) {
	echo $OUTPUT->header();
	$confirmarray['action'] = 'deletebookingoption';
	$confirmarray['confirm'] = 1;
	$confirmarray['optionid'] = $optionid;
	$continue = $url;
	$cancel = new moodle_url('/mod/booking/report.php', array('id'=>$id));;
	$continue->params($confirmarray);
	echo $OUTPUT->confirm(get_string('confirmdeletebookingoption','booking'), $continue,$cancel);
	echo $OUTPUT->footer();
	die;
}
$bookinglist = booking_get_spreadsheet_data($booking, $cm);
$PAGE->navbar->add($strresponses);
$PAGE->set_title(format_string($booking->name).": $strresponses");
$PAGE->set_heading($course->fullname);

if (!$download) {
	if(!isset($bookinglist[$optionid])){
		$bookinglist[$optionid] = false;
	}
	$sortedusers = booking_user_status($booking->option[$optionid],$bookinglist[$optionid]);
	$booking->option[$optionid]->courseurl = new moodle_url('/course/view.php', array('id'=>$booking->option[$optionid]->courseid));
	$booking->option[$optionid]->urltitle =$DB->get_field('course', 'shortname', array('id'=>$booking->option[$optionid]->courseid));
	$booking->option[$optionid]->cmid = $cm->id;
    $booking->option[$optionid]->autoenrol = $booking->autoenrol;
	$mform = new mod_booking_manageusers_form(null, array('bookingdata' => $booking->option[$optionid],'waitinglistusers' => $sortedusers['waitinglist'], 'bookedusers' => $sortedusers['booked'])); //name of the form you defined in file above.

	//managing the form
	if ($mform->is_cancelled()){
		redirect("view.php?id=$cm->id");
	} else if ($fromform=$mform->get_data()){
		//this branch is where you process validated data.
		if (isset($fromform->deleteusers) && has_capability('mod/booking:deleteresponses',$context) && confirm_sesskey()) {
			$selectedusers[$optionid] = array_keys($fromform->user,1);
			booking_delete_responses($selectedusers, $booking, $cm->id); //delete responses.
			redirect($url);
		} else if (isset($fromform->subscribetocourse) && confirm_sesskey()) { // subscription submitted - confirm it
			$selectedusers = array_keys($fromform->user,1);
			foreach($selectedusers as $selecteduserid){
				$straddselect = "addselect[$selecteduserid]";
				$confirmarray[$straddselect] = $selecteduserid;
			}
			$courseid = $DB->get_field('booking_options', 'courseid', array('id' => $optionid));
			$enrolid = $DB->get_field('enrol', 'id', array('courseid' => $courseid, 'enrol' => 'manual'));
			$courseshortname = $DB->get_field('course', 'shortname', array('id' => $courseid));
			if($courseid != 0){
				echo $OUTPUT->header();
				$continue = new moodle_url($CFG->wwwroot.'/enrol/manual/manage.php', array('id' =>$courseid, 'enrolid' => $enrolid, 'add' => 1, 'sesskey' => $USER->sesskey));
				$continue->params($confirmarray);
				$cancel = new moodle_url($url);
				echo $OUTPUT->confirm(get_string('subscribeuser','booking').": ".$courseshortname."?", $continue, $cancel);
			} else {
				error("No course selected for this booking option", "report.php?id=$cm->id");
			}
			die;
		}

	} else {
		echo $OUTPUT->header();
	}
	$mform->display();
	echo $OUTPUT->footer();
} else {
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
		$myxls = $workbook->add_worksheet($strresponses);
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
}
?>
