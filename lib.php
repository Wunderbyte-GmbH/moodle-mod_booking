<?php // $Id: lib.php,v 1.59.2.29 2011/02/01 11:09:31 dasistwas Exp $

$COLUMN_HEIGHT = 300;


/// Standard functions /////////////////////////////////////////////////////////

function booking_user_outline($course, $user, $mod, $booking) {
	global $DB;
	if ($answer = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $user->id))) {
		$result = new stdClass();
		$result->info = "'".format_string(booking_get_option_text($booking, $answer->optionid))."'";
		$result->time = $answer->timemodified;
		return $result;
	}
	return NULL;
}


function booking_user_complete($course, $user, $mod, $booking) {
	global $DB;
	if ($answer = $DB->get_record('booking_answers', array("bookingid" => $booking->id, "userid" => $user->id))) {
		$result = new stdClass();
		$result->info = "'".format_string(booking_get_option_text($booking, $answer->optionid))."'";
		$result->time = $answer->timemodified;
		echo get_string("answered", "booking").": $result->info. ".get_string("updated", '', userdate($result->time));
	} else {
		print_string("notanswered", "booking");
	}
}

function booking_supports($feature) {
	switch($feature) {
		case FEATURE_GROUPS:                  return false;
		case FEATURE_GROUPINGS:               return false;
		case FEATURE_GROUPMEMBERSONLY:        return false;
		case FEATURE_MOD_INTRO:               return true;
		case FEATURE_COMPLETION_TRACKS_VIEWS: return false;
		case FEATURE_COMPLETION_HAS_RULES:    return false;
		case FEATURE_GRADE_HAS_GRADE:         return false;
		case FEATURE_GRADE_OUTCOMES:          return false;
		case FEATURE_BACKUP_MOODLE2:          return true;

		default: return null;
	}
}

function booking_add_instance($booking) {
	global $DB;
	// Given an object containing all the necessary data,
	// (defined by the form in mod.html) this function
	// will create a new instance and return the id number
	// of the new instance.

	$booking->timemodified = time();

	if (empty($booking->timerestrict)) {
		$booking->timeopen = 0;
		$booking->timeclose = 0;
	}

	//insert answer options from mod_form
	$booking->id = $DB->insert_record("booking", $booking);
	foreach ($booking->option as $key => $value) {
		$value = trim($value);
		if (isset($value) && $value <> '') {
			$option = new stdClass();
			$option->text = $value;
			$option->bookingid = $booking->id;
			if (isset($booking->limit[$key])) {
				$option->maxanswers = $booking->limit[$key];
			}
			$option->timemodified = time();
			$DB->insert_record("booking_options", $option);
		}
	}

	return $booking->id;
}


function booking_update_instance($booking) {
	global $DB;
	// Given an object containing all the necessary data,
	// (defined by the form in mod.html) this function
	// will update an existing instance with new data.

	// we have to prepare the bookingclosingtimes as an $arrray, currently they are in $booking as $key (string)
	$booking->id = $booking->instance;
	$booking->timemodified = time();


	if (empty($booking->timerestrict)) {
		$booking->timeopen = 0;
		$booking->timeclose = 0;
	}

	//update, delete or insert answers
	foreach ($booking->option as $key => $value) {
		$value = trim($value);
		$option = new stdClass();
		$option->text = $value;
		$option->bookingid = $booking->id;
		if (isset($booking->limit[$key])) {
			$option->maxanswers = $booking->limit[$key];
		}
		$option->timemodified = time();
		if (isset($booking->optionid[$key]) && !empty($booking->optionid[$key])){//existing booking record
			$option->id=$booking->optionid[$key];
			if (isset($value) && $value <> '') {
				$DB->update_record("booking_options", $option);
			} else { //empty old option - needs to be deleted.
				$DB->delete_records("booking_options", array("id"=>$option->id));
			}
		} else {
			if (isset($value) && $value <> '') {
				$DB->insert_record("booking_options", $option);
			}
		}
	}

	return $DB->update_record('booking', $booking);

}

function booking_update_options($optionvalues){
	global $DB;
	$option = new stdClass();
	$option->bookingid = $optionvalues->bookingid;
	$option->text = trim($optionvalues->text);
	if ($optionvalues->limitanswers == 0){
		$optionvalues->limitanswers = 0;
		$option->maxanswers = 0;
		$option->maxoverbooking = 0;
	} else {
		$option->maxanswers = $optionvalues->maxanswers;
		$option->maxoverbooking = $optionvalues->maxoverbooking;
		$option->limitanswers = 1;
	}
	if(isset($optionvalues->restrictanswerperiod)){
		$option->bookingclosingtime = $optionvalues->bookingclosingtime;
	} else {
		$option->bookingclosingtime = 0;
	}
	$option->courseid = $optionvalues->courseid;
	if (isset($optionvalues->startendtimeknown)){
		$option->coursestarttime = $optionvalues->coursestarttime;
		$option->courseendtime = $optionvalues->courseendtime;
	} else {
		$option->coursestarttime = 0;
		$option->courseendtime = 0;
	}

	$option->description = $optionvalues->description;
	$option->limitanswers = $optionvalues->limitanswers;
	$option->timemodified = time();

	if (isset($optionvalues->optionid) && !empty($optionvalues->optionid) && $optionvalues->id != "add"){//existing booking record
		$option->id=$optionvalues->optionid;
		if (isset($optionvalues->text) && $optionvalues->text <> '') {
			return $DB->update_record("booking_options", $option);
		}
	} elseif (isset($optionvalues->text) && $optionvalues->text <> '') {
		return $DB->insert_record("booking_options", $option);
	}
}
/**
 * Checks the status of the specified user
 * @param $userid userid of the user
 * @param $optionid booking option to check
 * @param $bookingid booking id
 * @param $cmid course module id
 * @return localised string of user status
 */
function booking_get_user_status($userid,$optionid,$bookingid,$cmid){
	global $DB;
	$option = $DB->get_record('booking_options', array('id' => $optionid));
	$current = $DB->get_record('booking_answers', array('bookingid' => $bookingid, 'userid' => $userid, 'optionid' => $optionid));
	$allresponses = $DB->get_records_select('booking_answers', "bookingid = $bookingid AND optionid = $optionid",array(), 'timemodified', 'userid');
	$context  = get_context_instance(CONTEXT_MODULE,$cmid);
	$i=1;
	foreach($allresponses as $answer){
		if (has_capability('mod/booking:choose', $context, $answer->userid)){
			$sortedresponses[$i++] = $answer->userid;
		}
	}
	$useridaskey = array_flip($sortedresponses);
	if($option->limitanswers){
		if($useridaskey[$userid] > $option->maxanswers + $option->maxoverbooking){
			$status = "Problem, please contact the admin";
		} elseif (($useridaskey[$userid]) > $option->maxanswers) { // waitspaceavailable
			$status = get_string('onwaitinglist','booking');
		} elseif ($useridaskey[$userid] <= $option->maxanswers) {
			$status = get_string('booked','booking');
		} else {
			$status = get_string('notbooked','booking');
		}
	} else {
		if ($useridaskey[$userid]){
			$status = get_string('booked','booking');
		} else {
			$status = get_string('notbooked','booking');
		}
	}
	return $status;
}

/**
 * Echoes HTML code for booking table with all booking options and booking status
 * @param $booking object containing complete details of the booking instance
 * @param $user object of current user
 * @param $cm module object
 * @param $allresponses array of all responses
 * @param $singleuser 0 show all booking options, 1 show only my booking options
 * @return nothing
 */
function booking_show_form($booking, $user, $cm, $allresponses,$singleuser=0) {
	global $DB, $OUTPUT;
	//$optiondisplay is an array of the display info for a booking $cdisplay[$optionid]->text  - text name of option.
	//                                                                            ->maxanswers -maxanswers for this option
	//                                                                            ->full - whether this option is full or not. 0=not full, 1=full
	//									      ->maxoverbooking - waitinglist places dor option
	//									      ->waitingfull - whether waitinglist is full or not 0=not, 1=full
	$bookingfull = false;
	$cdisplay = new stdClass();

	if ($booking->limitanswers) { //set bookingfull to true by default if limitanswers.
		$bookingfull = true;
		$waitingfull = true;
	}

	$context = get_context_instance(CONTEXT_MODULE, $cm->id);
	$table = NULL;
	$displayoptions = new stdClass();
	$displayoptions->para = false;
	$tabledata = array();
	$current = array();
	foreach ($booking->option as $option) {
		$optiondisplay = new stdClass();
		$optiondisplay->delete = "";
		$optiondisplay->button = "";
		$hiddenfields = array('answer' => $option->id);
		// determine the ranking in order of booking time. necessary to decide whether user is on waitinglist or in regular booking
		if(@$allresponses[$option->id]){
			foreach($allresponses[$option->id] as $rank => $userobject){
				if ($user->id == $userobject->id){
					$current[$option->id] = $rank; //ranking of the user in order of subscription time
				}
			}
		}

		if (!empty($current[$option->id])) {
			if (!$option->limitanswers){
				$optiondisplay->booked = get_string('booked','booking');
				$rowclasses[] = "mod-booking-booked";
				if(($booking->allowupdate and $option->status != 'closed') or has_capability('mod/booking:deleteresponses', $context)){
					$buttonoptions = array('id' => $cm->id, 'action' => 'delbooking', 'optionid' => $option->id, 'sesskey' => $user->sesskey);
					$url = new moodle_url('view.php',$buttonoptions);
					$url->params($buttonoptions);
					$optiondisplay->delete = $OUTPUT->single_button($url,get_string('cancelbooking','booking'),'post').'<br />';
				}
			} elseif ($current[$option->id] > $option->maxanswers + $option->maxoverbooking){
				$optiondisplay->booked = "Problem, please contact the admin";
			} elseif ($current[$option->id] > $option->maxanswers) { // waitspaceavailable
				$optiondisplay->booked = get_string('onwaitinglist','booking');
				$rowclasses[] = "mod-booking-watinglist";
				if(($booking->allowupdate and $option->status != 'closed') or has_capability('mod/booking:deleteresponses', $context)){
					$buttonoptions = array('id' => $cm->id, 'action' => 'delbooking', 'optionid' => $option->id, 'sesskey' => $user->sesskey);
					$url = new moodle_url('view.php',$buttonoptions);
					$optiondisplay->delete = $OUTPUT->single_button($url,get_string('cancelbooking','booking'),'post').'<br />';
				}
			} elseif ($current[$option->id] <= $option->maxanswers) {
				$optiondisplay->booked = get_string('booked','booking');
				$rowclasses[] = "mod-booking-booked";
				if(($booking->allowupdate and $option->status != 'closed') or has_capability('mod/booking:deleteresponses', $context)){
					$buttonoptions = array('id' => $cm->id, 'action' => 'delbooking', 'optionid' => $option->id, 'sesskey' => $user->sesskey);
					$url = new moodle_url('view.php',$buttonoptions);
					$optiondisplay->delete = $OUTPUT->single_button($url,get_string('cancelbooking','booking'),'post').'<br />';
				}
			}
			if(!$booking->allowupdate){
				$optiondisplay->button = "";
			}
		} else {
			$optiondisplay->booked = get_string('notbooked','booking');
			if (!$singleuser){
				$rowclasses[] = "";
			} else {
				$rowclasses[] = "mod-booking-invisible";
			}
			$buttonoptions = array('answer' => $option->id, 'id' => $cm->id,'sesskey' => $user->sesskey);
			$url = new moodle_url('view.php',$buttonoptions);
			$url->params($hiddenfields);
			$optiondisplay->button = $OUTPUT->single_button($url, get_string('booknow','booking'),'post');
		}
		if ( $booking->option[$option->id]->limitanswers &&	($booking->option[$option->id]->status == "full")) {
			$optiondisplay->button = '';
		} elseif ($booking->option[$option->id]->status == "closed") {
			$optiondisplay->button = '';
		}
		// check if user ist logged in
		if (has_capability('mod/booking:choose', $context, $user->id, false)) { //don't show booking button if the logged in user is the guest user.
			$bookingbutton = $optiondisplay->button;
		} else {
			$bookingbutton = get_string('havetologin', 'booking')."<br />";
		}
		if (!$option->limitanswers){
			$stravailspaces = get_string("unlimited", 'booking');
		} else {
			$stravailspaces = get_string("placesavailable", "booking").": ".$option->availspaces." / ".$option->maxanswers."<br />".get_string("waitingplacesavailable", "booking").": ".$option->availwaitspaces." / ".$option->maxoverbooking;
		}
		if (has_capability('mod/booking:readresponses', $context)){
			$numberofresponses = 0;
			if(isset($allresponses[$option->id])){
				$numberofresponses = count($allresponses[$option->id]);					
			}
			$optiondisplay->manage = "<a href=\"report.php?id=$cm->id&optionid=$option->id\">".get_string("viewallresponses", "booking", $numberofresponses)."</a>";
		} else {
			$optiondisplay->manage = "";
		}
		$tabledata[] = array ($bookingbutton.$optiondisplay->booked."<br />".get_string($option->status, "booking")."<br />".$optiondisplay->delete.$optiondisplay->manage,
				"<b>".format_text($option->text. ' ', FORMAT_MOODLE, $displayoptions)."</b>"."<p>".$option->description."</p>",
				$option->coursestarttime." - <br />".$option->courseendtime,
				$stravailspaces);
	}
	$table = new html_table();
	$table->attributes['class'] = 'box generalbox boxaligncenter mod-booking-table';
	$table->attributes['style'] = 'width: auto;';
	$table->data = array();
	$strselect =  get_string("select", "booking");
	$strbooking = get_string("booking", "booking");
	$strdate = get_string("coursedate", "booking");
	$stravailability = get_string("availability", "booking");

	$table->head = array($strselect, $strbooking, $strdate, $stravailability);
	$table->align = array ("left", "left", "left", "left");
	$table->rowclasses = $rowclasses;
	$table->data = $tabledata;
	echo (html_writer::table($table));
}

/**
 * Saves the booking for the user
 * @return true if booking was possible, false if meanwhile the booking got full
 */
function booking_user_submit_response($optionid, $booking, $user, $courseid, $cm) {
	global $DB;
	$context = get_context_instance(CONTEXT_MODULE, $cm->id);
	// check if optionid exists as real option
	if(!$DB->get_field('booking_options','id', array('id' => $optionid))){
		return false;
	}
	if($booking->option[$optionid]->limitanswers) {
		// retrieve all answers for this option ID
		$answers[$optionid] = $DB->get_records("booking_answers", array("optionid" => $optionid));
		if ($answers[$optionid]) {
			$countanswers[$optionid] = 0;
			foreach ($answers[$optionid] as $a) { //only return enrolled users.
				if (has_capability('mod/booking:choose', $context, $a->userid, false)) {
					$countanswers[$optionid]++;
				}
			}
		}
		$maxans[$optionid] = $booking->option[$optionid]->maxanswers + $booking->option[$optionid]->maxoverbooking;
		// if answers for one option are limited and total answers are not exceeded then
		$countanswers = 0;
		if (!($booking->option[$optionid]->limitanswers && ($countanswers[$optionid] >= $maxans[$optionid]) )) {
			// check if actual answer is also already made by this user
			if(!($currentanswerid = $DB->get_field('booking_answers','id', array('userid' => $user->id, 'optionid' => $optionid)))){
				$newanswer->bookingid = $booking->id;
				$newanswer->userid = $user->id;
				$newanswer->optionid = $optionid;
				$newanswer->timemodified = time();
				if (!$DB->insert_record("booking_answers", $newanswer)) {
					error("Could not register your booking because of a database error");
				}
                booking_check_enrol_user($booking->option[$optionid], $booking, $user->id);
			}
			add_to_log($courseid, "booking", "choose", "view.php?id=$cm->id", $booking->id, $cm->id);
			if ($booking->sendmail){
				booking_send_confirmation_email($user, $booking, $optionid,$cm->id);
			}
			return true;
		} else { //check to see if current booking already selected - if not display error
			$optionname = $DB->get_field('booking_options', 'text', array('id' => $optionid));
			return false;
		}

	} else if (!($booking->option[$optionid]->limitanswers)){
		if(!($currentanswerid = $DB->get_field('booking_answers','id', array('userid' => $user->id, 'optionid' => $optionid)))){
			$newanswer->bookingid = $booking->id;
			$newanswer->userid = $user->id;
			$newanswer->optionid = $optionid;
			$newanswer->timemodified = time();
			if (!$DB->insert_record("booking_answers", $newanswer)) {
				error("Could not register your booking because of a database error");
			}
            booking_check_enrol_user($booking->option[$optionid], $booking, $user->id);
		}
		add_to_log($courseid, "booking", "choose", "view.php?id=$cm->id", $booking->id, $cm->id);
		if ($booking->sendmail){
			booking_send_confirmation_email($user, $booking, $optionid,$cm->id);
		}
		return true;
	}
}

/**
 * Automatically enrol the user in the relevant course, if that setting is on and a
 * course has been specified.
 * @param object $option
 * @param object $booking
 * @param int $userid
 */
function booking_check_enrol_user($option, $booking, $userid) {
    global $DB;

    if (!$booking->autoenrol) {
        return; // Autoenrol not enabled.
    }
    if (!$option->courseid) {
        return; // No course specified.
    }

    if (!enrol_is_enabled('manual')) {
        return; // Manual enrolment not enabled.
    }

    if (!$enrol = enrol_get_plugin('manual')) {
        return; // No manual enrolment plugin
    }
    if (!$instances = $DB->get_records('enrol', array('enrol'=>'manual', 'courseid'=>$option->courseid, 'status'=>ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
        return; // No manual enrolment instance on this course.
    }
    $instance = reset($instances); // Use the first manual enrolment plugin in the course.

    $enrol->enrol_user($instance, $userid, $instance->roleid); // Enrol using the default role.
}

/**
 * Automatically unenrol the user from the relevant course, if that setting is on and a
 * course has been specified.
 * @param object $option
 * @param object $booking
 * @param int $userid
 */
function booking_check_unenrol_user($option, $booking, $userid) {
    global $DB;

    if (!$booking->autoenrol) {
        return; // Autoenrol not enabled.
    }
    if (!$option->courseid) {
        return; // No course specified.
    }
    if (!enrol_is_enabled('manual')) {
        return; // Manual enrolment not enabled.
    }
    if (!$enrol = enrol_get_plugin('manual')) {
        return; // No manual enrolment plugin
    }
    if (!$instances = $DB->get_records('enrol', array('enrol'=>'manual', 'courseid'=>$option->courseid, 'status'=>ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
        return; // No manual enrolment instance on this course.
    }
    $instance = reset($instances); // Use the first manual enrolment plugin in the course.

    $enrol->unenrol_user($instance, $userid); // Unenrol the user.
}


// this function is not yet implemented and needs to be changed a lot before using it
function booking_show_statistic (){
	global $DB;
	echo "<table cellpadding=\"5\" cellspacing=\"0\" class=\"results anonymous\">";
	echo "<tr>";

	foreach ($booking->option as $optionid => $option) {
		echo "<th class=\"col$count header\" scope=\"col\">";
		echo format_string($option->text);
		echo "</th>";

		$column[$optionid] = 0;
		if (isset($allresponses[$optionid])) {
			$column[$optionid] = count($allresponses[$optionid]);
			if ($column[$optionid] > $maxcolumn) {
				$maxcolumn = $column[$optionid];
			}
		} else {
			$column[$optionid] = 0;
		}
	}
	echo "</tr><tr>";

	$height = 0;


	$count = 1;
	foreach ($booking->option as $optionid => $option) {
		if ($maxcolumn) {
			$height = $COLUMN_HEIGHT * ((float)$column[$optionid] / (float)$maxcolumn);
		}
		echo "<td style=\"vertical-align:bottom\" align=\"center\" class=\"col$count data\">";
		echo "<img src=\"column.png\" height=\"$height\" width=\"49\" alt=\"\" />";
		echo "</td>";
		$count++;
	}
	echo "</tr><tr>";



	$count = 1;
	foreach ($booking->option as $optionid => $option) {
		echo "<td align=\"center\" class=\"col$count count\">";
		if ($booking->limitanswers) {
			echo get_string("taken", "booking").":";
			echo $column[$optionid].'<br />';
			echo get_string("limit", "booking").":";
			$option = $GB->get_record("booking_options", array("id", $optionid));
			echo $option->maxanswers;
		} else {
			echo $column[$optionid];
			echo '<br />('.format_float(((float)$column[$optionid]/(float)$totalresponsecount)*100.0,1).'%)';
		}
		echo "</td>";
		$count++;
	}
	echo "</tr></table>";
}

function booking_confirm_booking($optionid, $booking, $user, $cm, $url){
	global $OUTPUT;
	echo $OUTPUT->header();
	$optionidarray['answer'] = $optionid;
	$optionidarray['confirm'] = 1;
	$optionidarray['sesskey'] = $user->sesskey;
	$optionidarray['id'] = $cm->id;
	$requestedcourse = "<br />".$booking->option[$optionid]->text;
	if($booking->option[$optionid]->coursestarttime != get_string('starttimenotset','booking')){
		$requestedcourse .= "<br />".$booking->option[$optionid]->coursestarttime." - ".$booking->option[$optionid]->courseendtime;
	}
	$message = "<h2>".get_string('confirmbookingoffollowing','booking')."</h2>".$requestedcourse;
	$message .= "<p><b>".get_string('agreetobookingpolicy','booking').":</b></p>";
	$message .= "<p>".$booking->bookingpolicy."<p>";
	echo $OUTPUT->confirm($message, new moodle_url('/mod/booking/view.php', $optionidarray),$url);
	echo $OUTPUT->footer();
}
/**
 * deletes a single booking of a user if user cancels the booking, sends mail to supportuser and newbookeduser
 * @return true if booking was deleted successfully, otherwise false
 */
function booking_delete_singlebooking($answerid,$booking,$optionid,$newbookeduserid,$cmid) {
	global $COURSE, $USER, $CFG, $DB;
	if(!$DB->delete_records('booking_answers', array('id' => $answerid))){
		return false;
	}
    booking_check_unenrol_user($booking->option[$optionid], $booking, $USER->id);
	$supportuser = generate_email_supportuser();
	$supportuserparams = new stdClass();
	$supportuserparams->bookingname = $booking->option[$optionid]->text;
	$supportuserparams->name = fullname($USER);
	$supportuserparams->link = $CFG->wwwroot .'/user/view.php?id='.$USER->id.'&course='.$COURSE->id;
	$messagetext = get_string('deletedbookingmessage','booking',$supportuserparams);
	$deletedbookingmessage = get_string('deletedbookingusermessage','booking', $supportuserparams);
	$deletedbookingusermessage = get_string('deletedbookingusermessage', 'booking', $supportuserparams);
	$bookingmanager = $DB->get_record('user', array('username' => $booking->bookingmanager));
	if ($booking->sendmail){
		email_to_user($USER, $supportuser, get_string('deletedbookingusersubject','booking', $supportuserparams), $deletedbookingusermessage);
	}
	if ($booking->copymail){
		email_to_user($bookingmanager, $supportuser, get_string('deletedbookingsubject','booking', $supportuserparams), $messagetext);
	}
	if ($booking->limitanswers == 1 && $booking->sendmail == 1 && $newbookeduserid){
		$newbookeduser = $DB->get_record('user', array('id' => $newbookeduserid));
		$messageparams = new stdClass();
		$messageparams->status = booking_get_user_status($newbookeduserid, $optionid, $booking->id, $cmid);
		$messageparams->date = $booking->option[$optionid]->coursestarttime. " - ".$booking->option[$optionid]->courseendtime;
		$messageparams->name = fullname($newbookeduser);
		$messageparams->link = $CFG->wwwroot .'/mod/booking/view.php?id='.$cmid;
		$messageparams->bookingname = $booking->option[$optionid]->text;
		$messageparams->canceluntil = $booking->option[$optionid]->bookingclosingtime;
		$messagetextnewuser = get_string('statuschangebookedmessage','booking',$messageparams);
		email_to_user($newbookeduser, $supportuser, get_string('statuschangebookedsubject','booking', $messageparams), $messagetextnewuser);
	}
	return true;
}

function booking_delete_responses($attemptidsarray, $booking) {
	global $DB;
	if(!is_array($attemptidsarray) || empty($attemptidsarray)) {
		return false;
	}
	foreach($attemptidsarray as $optionid => $attemptids){
		if(!is_array($attemptids) || empty($attemptids)) {
			return false;
		}
		foreach($attemptids as $num => $attemptid) {
			if(empty($attemptid)) {
				unset($attemptids[$num]);
			}
		}
		foreach($attemptids as $attemptid) {
			if ($todelete = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $attemptid, 'optionid' => $optionid))) {
				$DB->delete_records('booking_answers', array('bookingid' => $booking->id, 'userid' => $attemptid, 'optionid' => $optionid));
                booking_check_unenrol_user($booking->option[$optionid], $booking, $attemptid);
			}
		}
	}
	return true;
}


function booking_delete_instance($id) {
	global $DB;
	// Given an ID of an instance of this module,
	// this function will permanently delete the instance
	// and any data that depends on it.

	if (! $booking = $DB->get_record("booking", array("id" => "$id"))) {
		return false;
	}

	$result = true;

	if (! $DB->delete_records("booking_answers", array("bookingid" => "$booking->id"))) {
		$result = false;
	}

	if (! $DB->delete_records("booking_options", array("bookingid" => "$booking->id"))) {
		$result = false;
	}

	if (! $DB->delete_records("booking", array("id" => "$booking->id"))) {
		$result = false;
	}

	return $result;
}

function booking_get_participants($bookingid) {
	global $CFG, $DB;
	//Returns the users with data in one booking
	//(users with records in booking_responses, students)

	//Get students
	$students = $DB->get_records_sql("SELECT DISTINCT u.id, u.id
			FROM {$CFG->prefix}user u,
			{$CFG->prefix}booking_answers a
			WHERE a.bookingid = '$bookingid' and
			u.id = a.userid");

			//Return students array (it contains an array of unique users)
			return ($students);
}


function booking_get_option_text($booking, $id) {
	global $DB;
	// Returns text string which is the answer that matches the id
	if ($result = $DB->get_record("booking_options", array("id" => $id))) {
		return $result->text;
	} else {
		return get_string("notanswered", "booking");
	}
}

function booking_get_groupmodedata() {

}
/**
 * Gets the principal information of booking status and booking options
 * to be used by other functions
 * @param $bookingid id of the module
 * @return object with $booking->option as an array for the booking option valus for each booking option
 */
function booking_get_booking($cm) {
	global $DB;
	$bookingid = $cm->instance;
	// Gets a full booking record
	$context = get_context_instance(CONTEXT_MODULE, $cm->id);

	/// Initialise the returned array, which is a matrix:  $allresponses[responseid][userid] = responseobject
	$allresponses = array();
	/// bookinglist $bookinglist[optionid][sortnumber] = userobject;
	$bookinglist = array();

	/// First get all the users who have access here
	$allresponses = get_users_by_capability($context, 'mod/booking:choose', 'u.id, u.picture, u.firstname, u.lastname, u.idnumber, u.email', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);

	if (($options = $DB->get_records('booking_options', array('bookingid' => $bookingid), 'id')) && ($booking = $DB->get_record("booking", array("id" => $bookingid)))) {
		$answers = $DB->get_records('booking_answers', array('bookingid' => $bookingid), 'id');
		foreach ($options as $option){
			$booking->option[$option->id] = $option;
			if(!$option->coursestarttime == 0){
				$booking->option[$option->id]->coursestarttime = userdate($option->coursestarttime, get_string('strftimedate'));
			} else {
				$booking->option[$option->id]->coursestarttime = get_string("starttimenotset", 'booking');
			}
			if(!$option->courseendtime == 0){
				$booking->option[$option->id]->courseendtime = userdate($option->courseendtime, get_string('strftimedate'),'',false);
			} else {
				$booking->option[$option->id]->courseendtime = get_string("endtimenotset", 'booking');
			}
			// we have to change $taken is different from booking_show_results
			$answerstocount = array();
			if($answers){
				foreach($answers as $answer){
					if ($answer->optionid == $option->id && isset($allresponses[$answer->userid])){
						$answerstocount[] = $answer;
					}
				}
			}
			$taken = count($answerstocount);
			$totalavailable = $option->maxanswers + $option->maxoverbooking;
			if (!$option->limitanswers){
				$booking->option[$option->id]->status = "available";
				$booking->option[$option->id]->taken = $taken;
				$booking->option[$option->id]->availspaces = "unlimited";
			} else {
				if ($taken < $option->maxanswers) {
					$booking->option[$option->id]->status = "available";
					$booking->option[$option->id]->availspaces = $option->maxanswers - $taken;
					$booking->option[$option->id]->taken = $taken;
					$booking->option[$option->id]->availwaitspaces = $option->maxoverbooking;
				} elseif ($taken >= $option->maxanswers && $taken < $totalavailable ){
					$booking->option[$option->id]->status = "waitspaceavailable";
					$booking->option[$option->id]->availspaces = 0;
					$booking->option[$option->id]->taken = $option->maxanswers;
					$booking->option[$option->id]->availwaitspaces = $option->maxoverbooking - ($taken - $option->maxanswers);
				} elseif ($taken >= $totalavailable){
					$booking->option[$option->id]->status = "full";
					$booking->option[$option->id]->availspaces = 0;
					$booking->option[$option->id]->taken = $option->maxanswers;
					$booking->option[$option->id]->availwaitspaces = 0;
				}
			}
			if(time() > $booking->option[$option->id]->bookingclosingtime and $booking->option[$option->id]->bookingclosingtime != 0){
				$booking->option[$option->id]->status = "closed";
			}
			if ($option->bookingclosingtime){
				$booking->option[$option->id]->bookingclosingtime = userdate($option->bookingclosingtime, get_string('strftimedate'),'',false);
			} else {
				$booking->option[$option->id]->bookingclosingtime = false;
			}
		}
		return $booking;
	} elseif ($booking = $DB->get_record("booking", array("id" => $bookingid))) {
		return $booking;
	}
	return false;
}

function booking_get_view_actions() {
	return array('view','view all','report');
}

function booking_get_post_actions() {
	return array('choose','choose again');
}


/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the booking.
 * @param $mform form passed by reference
 */
function booking_reset_course_form_definition(&$mform) {
	$mform->addElement('header', 'bookingheader', get_string('modulenameplural', 'booking'));
	$mform->addElement('advcheckbox', 'reset_booking', get_string('removeresponses','booking'));
}

/**
 * Course reset form defaults.
 */
function booking_reset_course_form_defaults($course) {
	return array('reset_booking'=>1);
}

/**
 * Actual implementation of the rest coures functionality, delete all the
 * booking responses for course $data->courseid.
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function booking_reset_userdata($data) {
	global $CFG;

	$componentstr = get_string('modulenameplural', 'booking');
	$status = array();

	if (!empty($data->reset_booking)) {
		$bookingssql = "SELECT ch.id
		FROM {$CFG->prefix}booking ch
		WHERE ch.course={$data->courseid}";

		delete_records_select('booking_answers', "bookingid IN ($bookingssql)");
		$status[] = array('component'=>$componentstr, 'item'=>get_string('removeresponses', 'booking'), 'error'=>false);
	}

	/// updating dates - shift may be negative too
	if ($data->timeshift) {
		shift_course_mod_dates('booking', array('timeopen', 'timeclose'), $data->timeshift, $data->courseid);
		$status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
	}
	return $status;
}

/**
 * Gives a list of booked users sorted in an array by booking option
 * @param $cm module id
 * @param $booking booking object
 * @return array sorted list by booking date of all users, booking option as key
 */
function booking_get_spreadsheet_data($booking, $cm) {
	global $CFG, $USER, $DB;
	$bookinglistsorted = array();
	$context = get_context_instance(CONTEXT_MODULE, $cm->id);

	/// Initialise the returned array, which is a matrix:  $allresponses[responseid][userid] = responseobject
	$allresponses = array();
	/// bookinglist $bookinglist[optionid][sortnumber] = userobject;
	$bookinglist = array();

	/// First get all the users who have access here
	$allresponses = get_users_by_capability($context, 'mod/booking:choose', 'u.id, u.picture, u.firstname, u.lastname, u.idnumber, u.email', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);

	/// Get all the recorded responses for this booking
	$rawresponses = $DB->get_records('booking_answers', array('bookingid' => $booking->id), "optionid, timemodified ASC");
	$optionids = $DB->get_records_select('booking_options', "bookingid = $booking->id",array(),'id','id');
	/// Use the responses to move users into the correct column
	$sortnumber = 1;
	if ($rawresponses) {
		foreach ($rawresponses as $response) {
			if (isset($allresponses[$response->userid])) {   // This person is enrolled and in correct group
				$bookinglist[$response->optionid][$sortnumber++] = $allresponses[$response->userid];
			}
		}
	}
	if (empty($bookinglist)) {
		unset($bookinglist);
	} else {
		foreach($optionids as $optionid => $optionobject){
			if(!empty($bookinglist[$optionid])){
				$userperbookingoption = count($bookinglist[$optionid]);
				$i = 1;
				foreach($bookinglist[$optionid] as $key => $value){
					unset($bookinglist[$optionid][$key]);
					$bookinglistsorted[$optionid][$i++] = $value;
				}
			} else {
				unset($bookinglist[$optionid]);
			}
		}
	}
	return $bookinglistsorted;
}

/**
 * Divides all users in booked and waiting list users
 * @param $bookingoption bookingoption object
 * @param $allresponses all responses for that bookingoption
 * @return array of arrays with keys waitinglist and booked
 */
function booking_user_status($bookingoption,$allresponses){
	$userarray['waitinglist'] = false;
	$userarray['booked'] = false;
	$i =1;
	if(is_array($allresponses)){
		foreach ($allresponses as $user) {
			if ($i <= $bookingoption->maxanswers || !$bookingoption->limitanswers){ //booked user
				$userarray['booked'][] = $user;
			} else if ($i <= $bookingoption->maxoverbooking + $bookingoption->maxanswers){ //waitlistusers;
				$userarray['waitinglist'][] = $user;
			}
			$i++;
		}
	}
	return $userarray;
}
/**
 * Sends confirmation mail after user successfully booked
 * @param $user the user who booked
 * @param $booking the booking instance
 * @param $optionid the booking optionid the user chose
 * @param $cmid module id in the url
 * @return true or false according to success of operation
 */
function booking_send_confirmation_email($user,$booking,$optionid,$cmid){

	global $CFG, $DB;

	$site = get_site();
	$supportuser = generate_email_supportuser();
	$bookingmanager = $DB->get_record('user', array('username' => $booking->bookingmanager));
	$data = new stdClass();
	$data->name = fullname($user);
	$data->status = booking_get_user_status($user->id,$optionid,$booking->id,$cmid);
	$data->bookingname = $booking->option[$optionid]->text;
	$data->date = $booking->option[$optionid]->coursestarttime. " - ".$booking->option[$optionid]->courseendtime;
	$data->link = $CFG->wwwroot .'/mod/booking/view.php?id='.$cmid;
	$data->canceluntil = $booking->option[$optionid]->bookingclosingtime;

	if ($data->status == get_string('booked', 'booking')){
		$subject = get_string('confirmationsubject','booking', $data);
		$subjectmanager = get_string('confirmationsubjectbookingmanager','booking', $data);
		$message     = get_string('confirmationmessage', 'booking', $data);
	} elseif ($data->status == get_string('onwaitinglist', 'booking')){
		$subject = get_string('confirmationsubjectwaitinglist','booking', $data);
		$subjectmanager = get_string('confirmationsubjectwaitinglistmanager','booking', $data);
		$message     = get_string('confirmationmessagewaitinglist', 'booking', $data);
	} else {
		$subject ="test";
		$subjectmanager ="tester";
		$message = "message";
	}
	$messagehtml = text_to_html($message, false, false, true);
	$errormessage = get_string('error:failedtosendconfirmation','booking', $data);
	$errormessagehtml =  text_to_html($errormessage, false, false, true);
	$user->mailformat = 1;  // Always send HTML version as well
	// send mail to user, copy to bookingmanager and if mail fails send errormessage to bookingmanager
	if (email_to_user($user, $supportuser, $subject, $message, $messagehtml)) {
		if ($booking->copymail){
			return email_to_user($bookingmanager, $supportuser, $subjectmanager, $message, $messagehtml);
		} else {
			return true;
		}
	} else {
		email_to_user($bookingmanager, $supportuser, $subjectmanager, $errormessage, $errormessagehtml);
		return false;
	}
}
/**
 * Checks if user on waitinglist gets normal place if a user is deleted
 * @param $userid of user who will be deleted
 * @return false if no user gets from waitinglist to booked list or userid of user now on booked list
 */
function booking_check_statuschange($optionid,$booking,$cancelleduserid,$cmid) {
	global $DB;
	if (booking_get_user_status($cancelleduserid, $optionid, $booking->id,$cmid) !== get_string('booked','booking')) {
		return false;
	}
	$option = $booking->option[$optionid];
	$current = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $cancelleduserid, 'optionid' => $optionid));
	$allresponses = $DB->get_records_select('booking_answers', "bookingid = $booking->id AND optionid = $optionid", array(),'timemodified', 'userid');
	$context  = get_context_instance(CONTEXT_MODULE,$cmid);
	$firstuseronwaitinglist = $option->maxanswers + 1;
	$i=1;
	foreach($allresponses as $answer){
		if (has_capability('mod/booking:choose', $context, $answer->userid)){
			$sortedresponses[$i++] = $answer->userid;
		}
	}
	if (count($sortedresponses) <= $option->maxanswers){
		return false;
	} else if (isset($sortedresponses[$firstuseronwaitinglist])) {
		return $sortedresponses[$firstuseronwaitinglist];
	} else {
		return false;
	}
}
/**
 * Checks if required user profile fields are filled out
 * @param $userid to be checked
 * @return false if no redirect necessery true if necessary
 */
function booking_check_user_profile_fields($userid){
	global $DB;
	$redirect = false;
	if ($categories = $DB->get_records('user_info_category', array(), 'sortorder ASC')) {
		foreach ($categories as $category) {
			if ($fields = $DB->get_records_select('user_info_field', "categoryid=$category->id",array(), 'sortorder ASC')) {
				// check first if *any* fields will be displayed and if there are required fields
				$requiredfields = array();
				$redirect = false;
				foreach ($fields as $field) {
					if ($field->visible != 0 && $field->required == 1) {
						if (!$userdata = $DB->get_field('user_info_data','data', array("userid" => $userid, "fieldid"=>$field->id))){
							$redirect = true;
						}
					}
				}
			}
		}
	}
	return $redirect;
}

/**
 * Deletes a booking option and the associated user answers
 * @param $bookingid the booking instance
 * @param $optionid the booking option
 * @return false if not successful, true on success
 */
function booking_delete_booking_option($booking, $optionid) {
	global $DB;

	if (! $option = $DB->get_record("booking_options", array("id" => $optionid))) {
		return false;
	}

	$result = true;

    $params = array('bookingid' => $booking->id, 'optionid' => $optionid);
    $userids = $DB->get_fieldset_select('booking_answers', 'userid', 'bookingid = :bookingid AND optionid = :optionid', $params);
    foreach ($userids as $userid) {
        booking_check_unenrol_user($option, $booking, $userid); // Unenrol any users enroled via this option.
    }
	if (! $DB->delete_records("booking_answers", array("bookingid" => $booking->id, "optionid" => $optionid))) {
		$result = false;
	}

	if (! $DB->delete_records("booking_options", array("id" => $optionid))) {
		$result = false;
	}
	return $result;
}


function booking_profile_definition(&$mform) {
	global $CFG, $DB;

	// if user is "admin" fields are displayed regardless
	$update = has_capability('moodle/user:update', get_context_instance(CONTEXT_SYSTEM));

	if ($categories = $DB->get_records('user_info_category', array(), 'sortorder ASC')) {
		foreach ($categories as $category) {
			if ($fields = $DB->get_records_select('user_info_field', "categoryid=$category->id", array(),'sortorder ASC')) {

				// check first if *any* fields will be displayed
				$display = false;
				foreach ($fields as $field) {
					if ($field->visible != PROFILE_VISIBLE_NONE) {
						$display = true;
					}
				}

				// display the header and the fields
				if ($display or $update) {
					$mform->addElement('header', 'category_'.$category->id, format_string($category->name));
					foreach ($fields as $field) {
						require_once($CFG->dirroot.'/user/profile/field/'.$field->datatype.'/field.class.php');
						$newfield = 'profile_field_'.$field->datatype;
						$formfield = new $newfield($field->id);
						$formfield->edit_field($mform);
					}
				}
			}
		}
	}
}

/**
 * Returns all other caps used in module
 */
function booking_get_extra_capabilities() {
	return array('moodle/site:accessallgroups');
}

?>
