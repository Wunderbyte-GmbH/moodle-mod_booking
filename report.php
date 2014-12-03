<?php

/**
 * Manage bookings
 *
 * @package   Booking
 * @copyright 2012 David Bogner www.edulabs.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
require_once("../../config.php");
require_once("lib.php");
require_once("bookingmanageusers.class.php");
require_once("$CFG->dirroot/user/profile/lib.php");

$id = required_param('id', PARAM_INT);   //moduleid
$optionid = required_param('optionid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHANUM);
$confirm = optional_param('confirm', '', PARAM_INT);

// Search 
$searchName = optional_param('searchName', '', PARAM_TEXT);
$searchSurname = optional_param('searchSurname', '', PARAM_TEXT);
$searchDate = optional_param('searchDate', '', PARAM_TEXT);
$searchDateDay = optional_param('searchDateDay', '', PARAM_TEXT);
$searchDateMonth = optional_param('searchDateMonth', '', PARAM_TEXT);
$searchDateYear = optional_param('searchDateYear', '', PARAM_TEXT);
$searchFinished = optional_param('searchFinished', '', PARAM_TEXT);

$searching = FALSE;

$urlParams = array();
$urlParams['id'] = $id;
$urlParams['optionid'] = $optionid;

$urlParams['searchName'] = "";
if (strlen($searchName) > 0) {
    $urlParams['searchName'] = $searchName;
    $searching = TRUE;
}

$urlParams['searchSurname'] = "";
if (strlen($searchSurname) > 0) {
    $urlParams['searchSurname'] = $searchSurname;
    $searching = TRUE;
}

$timestamp = time();

$urlParams['searchDateDay'] = "";
if (strlen($searchDateDay) > 0) {
    $urlParams['searchDateDay'] = $searchDateDay;
    $searching = TRUE;
}

$urlParams['searchDateMonth'] = "";
if (strlen($searchDateMonth) > 0) {
    $urlParams['searchDateMonth'] = $searchDateMonth;
    $searching = TRUE;
}

$urlParams['searchDateYear'] = "";
if (strlen($searchDateYear) > 0) {
    $urlParams['searchDateYear'] = $searchDateYear;
    $searching = TRUE;
}

$checked = FALSE;
$urlParams['searchDate'] = "";
if ($searchDate == 1) {
    $urlParams['searchDate'] = $searchDate;
    $checked = TRUE;
    $timestamp = strtotime("{$urlParams['searchDateDay']}-{$urlParams['searchDateMonth']}-{$urlParams['searchDateYear']}");
}

$urlParams['searchFinished'] = "";
if (strlen($searchFinished) > 0) {
    $urlParams['searchFinished'] = $searchFinished;
}

$url = new moodle_url('/mod/booking/report.php', array('id' => $id, 'optionid' => $optionid));

if ($action !== '') {
    $url->param('action', $action);
    $urlParams['action'] = $action;
}
$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    error("Course Module ID was incorrect");
}
if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);

if (!$booking = booking_get_booking($cm)) {
    print_error("Course module is incorrect");
}

$context = context_module::instance($cm->id);

$option = $DB->get_record('booking_options', array('id' => $optionid));

if (!(booking_check_if_teacher($option, $USER) || has_capability('mod/booking:readresponses', $context))) {
    require_capability('mod/booking:readresponses', $context);
}

$strbooking = get_string("modulename", "booking");
$strbookings = get_string("modulenameplural", "booking");
$strresponses = get_string("responses", "booking");

$event = \mod_booking\event\report_viewed::create(array(
            'objectid' => $optionid,
            'context' => context_module::instance($cm->id)
        ));
$event->trigger();

if ($action == 'deletebookingoption' && $confirm == 1 && has_capability('mod/booking:updatebooking', $context) && confirm_sesskey()) {
    booking_delete_booking_option($booking, $optionid); //delete booking_option
    redirect("view.php?id=$cm->id");
} elseif ($action == 'deletebookingoption' && has_capability('mod/booking:updatebooking', $context) && confirm_sesskey()) {
    echo $OUTPUT->header();
    $confirmarray['action'] = 'deletebookingoption';
    $confirmarray['confirm'] = 1;
    $confirmarray['optionid'] = $optionid;
    $continue = $url;
    $cancel = new moodle_url('/mod/booking/report.php', array('id' => $id));
    $continue->params($confirmarray);
    echo $OUTPUT->confirm(get_string('confirmdeletebookingoption', 'booking'), $continue, $cancel);
    echo $OUTPUT->footer();
    die;
}
$bookinglist = booking_get_spreadsheet_data($booking, $cm, $urlParams);
$PAGE->navbar->add($strresponses);
$PAGE->set_title(format_string($booking->name) . ": $strresponses");
$PAGE->set_heading($course->fullname);

if (isset($action) && $action == 'sendpollurlteachers' && has_capability('mod/booking:communicate', $context)) {
    booking_sendpollurlteachers($booking, $cm->id, $optionid);
    $url->remove_params('action');
    redirect($url, get_string('allmailssend', 'booking'), 5);
}

if (!$download) {
    if (!isset($bookinglist[$optionid])) {
        $bookinglist[$optionid] = false;
    }
    $sortedusers = booking_user_status($booking->option[$optionid], $bookinglist[$optionid]);
    $booking->option[$optionid]->courseurl = new moodle_url('/course/view.php', array('id' => $booking->option[$optionid]->courseid));
    $booking->option[$optionid]->urltitle = $DB->get_field('course', 'shortname', array('id' => $booking->option[$optionid]->courseid));
    $booking->option[$optionid]->cmid = $cm->id;
    $booking->option[$optionid]->autoenrol = $booking->autoenrol;
    $mform = new mod_booking_manageusers_form(null, array('cm' => $cm, 'bookingdata' => $booking->option[$optionid], 'waitinglistusers' => $sortedusers['waitinglist'], 'bookedusers' => $sortedusers['booked'])); //name of the form you defined in file above.
//managing the form
    if ($mform->is_cancelled()) {
        redirect("view.php?id=$cm->id");
    } else if ($fromform = $mform->get_data()) {
//this branch is where you process validated data.
        if (isset($fromform->deleteusers) && has_capability('mod/booking:deleteresponses', $context) && confirm_sesskey()) {
            $selectedusers[$optionid] = array_keys($fromform->user, 1);
            booking_delete_responses($selectedusers, $booking, $cm->id); //delete responses.
            redirect($url);
        } else if (isset($fromform->subscribetocourse) && confirm_sesskey()) { // subscription submitted            
            if ($option->courseid != 0) {
                foreach (array_keys($fromform->user, 1) as $selecteduserid) {
                    booking_enrol_user($booking->option[$optionid], $booking, $selecteduserid);
                }
                redirect($url, get_string('userrssucesfullenroled', 'booking'), 5);
            } else {
                redirect($url, get_string('nocourse', 'booking'), 5);
            }
            die;
        } else if (isset($fromform->sendpollurl) && has_capability('mod/booking:communicate', $context) && confirm_sesskey()) {
            $selectedusers[$optionid] = array_keys($fromform->user, 1);
            booking_sendpollurl($selectedusers, $booking, $cm->id, $optionid);
            redirect($url, get_string('allmailssend', 'booking'), 5);
        } else if (isset($fromform->sendcustommessage) && has_capability('mod/booking:communicate', $context) && confirm_sesskey()) {
            $selectedusers = array_keys($fromform->user, 1);
            $sendmessageurl = new moodle_url('/mod/booking/sendmessage.php', array('id' => $id, 'optionid' => $optionid, 'uids' => serialize($selectedusers)));
            redirect($sendmessageurl);
        } else if (isset($fromform->activitycompletion) && (booking_check_if_teacher($option, $USER) || has_capability('mod/booking:readresponses', $context)) && confirm_sesskey()) {
            $selectedusers[$optionid] = array_keys($fromform->user, 1);
            booking_activitycompletion($selectedusers, $booking, $cm->id, $optionid);
            redirect($url, get_string('activitycompletionsuccess', 'booking'), 5);
        }
    } else {
        echo $OUTPUT->header();
    }

    echo $OUTPUT->heading($booking->option[$optionid]->text, 4, '', '');

    echo html_writer::link(new moodle_url('/mod/booking/editoptions.php', array('id' => $booking->option[$optionid]->cmid, 'optionid' => $booking->option[$optionid]->id)), get_string('updatebooking', 'booking'), array()) .
    ' | ' .
    html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $booking->option[$optionid]->cmid, 'optionid' => $booking->option[$optionid]->id, 'action' => 'deletebookingoption', 'sesskey' => sesskey())), get_string('deletebookingoption', 'booking'), array()) .
    ' | ' .
    html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $booking->option[$optionid]->cmid, 'action' => $booking->option[$optionid]->id, 'download' => 'ods', 'optionid' => $booking->option[$optionid]->id)), get_string('downloadusersforthisoptionods', 'booking'), array()) .
    ' | ' .
    html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $booking->option[$optionid]->cmid, 'action' => $booking->option[$optionid]->id, 'download' => 'xls', 'optionid' => $booking->option[$optionid]->id)), get_string('downloadusersforthisoptionxls', 'booking'), array());
    
    echo html_writer::link(new moodle_url('/mod/booking/view.php', array('id' => $cm->id)), get_string('gotobooking', 'booking'), array('style' => 'float:right;'));

    echo "<br>";

    $links = array();

    if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
        $links[] = html_writer::link(new moodle_url('/mod/booking/teachers.php', array('id' => $id, 'optionid' => $optionid)), get_string('addteachers', 'booking'), array());
    }

    if (has_capability('mod/booking:subscribeusers', $context)) {
        $links[] = html_writer::link(new moodle_url('/mod/booking/subscribeusers.php', array('id' => $cm->id, 'optionid' => $optionid)), get_string('bookotherusers', 'booking'), array());
    }
    
    $links[] = '<a href="#" id="showHideSearch">' . get_string('search') . '</a>';

    if (has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
        $links[] = html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $cm->id, 'optionid' => $optionid, 'action' => 'sendpollurlteachers')), get_string('booking:sendpollurltoteachers', 'booking'), array());
    }

    echo implode(" | ", $links);

    if ($booking->option[$optionid]->courseid != 0) {
        echo '<br>' . html_writer::start_span('') . get_string('associatedcourse', 'booking') . ': ' . html_writer::link(new moodle_url($booking->option[$optionid]->courseurl, array()), $booking->option[$optionid]->urltitle, array()) . html_writer::end_span() . '<br>';
    }

    echo '<br>' . html_writer::start_span('') . get_string('onlythisbookingurl', 'booking') . ': ' . html_writer::link(new moodle_url('/mod/booking/view.php', array('id' => $id, 'optionid' => $optionid, 'action' => 'showonlyone')), new moodle_url('/mod/booking/view.php', array('id' => $id, 'optionid' => $optionid, 'action' => 'showonlyone')), array()) . html_writer::end_span() . '<br><br>';
    
    $hidden = "";

    foreach ($urlParams as $key => $value) {
        if (!in_array($key, array('searchName', 'searchSurname', 'searchDate', 'searchFinished'))) {
            $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
        }
    }

    $row = new html_table_row(array(get_string('searchName', "booking"), '<form>' . $hidden . '<input value="' . $urlParams['searchName'] . '" type="text" name="searchName">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";
    $row = new html_table_row(array(get_string('searchSurname', "booking"), '<input value="' . $urlParams['searchSurname'] . '" type="text" name="searchSurname">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";
    $row = new html_table_row(array(get_string('searchDate', "booking"), html_writer::checkbox('searchDate', '1', $checked) . html_writer::select_time('days', 'searchDateDay', $timestamp, 5) . ' ' . html_writer::select_time('months', 'searchDateMonth', $timestamp, 5) . ' ' . html_writer::select_time('years', 'searchDateYear', $timestamp, 5), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(array(get_string('searchFinished', "booking"), html_writer::select(array('0' => get_string('no', "booking"), '1' => get_string('yes', "booking")), 'searchFinished', $urlParams['searchFinished']), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(array("", '<input type="submit" value="' . get_string('search') . '"></form>', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $table = new html_table();
    $table->head = array('', '', '');
    $table->data = $tabledata;
    $table->id = "tableSearch";
    if (!$searching) {
        $table->attributes = array('style' => "display: none;");
    }
    echo html_writer::table($table);

    $mform->display();
    echo $OUTPUT->footer();
} else {
    if ($download == "ods" OR $download == "xls" && has_capability('mod/booking:downloadresponses', $context)) {
        if ($action == "all") {
            $filename = clean_filename("$course->shortname " . strip_tags(format_string($booking->name, true)));
        } else {
            $optionname = $booking->option[$action]->text;
            $filename = clean_filename(strip_tags(format_string($optionname, true)));
        }
        if ($download == "ods") {
            require_once("$CFG->libdir/odslib.class.php");
            $workbook = new MoodleODSWorkbook("-");
            $filename .= '.ods';
        } else {
            require_once("$CFG->libdir/excellib.class.php");
            $workbook = new MoodleExcelWorkbook("-");
            $filename .= '.xls';
        }


/// Send HTTP headers
        $workbook->send($filename);
/// Creating the first worksheet
        $myxls = $workbook->add_worksheet($strresponses);
        if ($download == "ods") {
            $cellformat = $workbook->add_format(array('bg_color' => 'white'));
            $cellformat1 = $workbook->add_format(array('bg_color' => 'red'));
        } else {
            $cellformat = '';
            $cellformat1 = $workbook->add_format(array('fg_color' => 'red'));
        }
/// Print names of all the fields
        $myxls->write_string(0, 0, get_string("booking", "booking"));
        $myxls->write_string(0, 1, get_string("user") . " " . get_string("idnumber"));
        $myxls->write_string(0, 2, get_string("firstname"));
        $myxls->write_string(0, 3, get_string("lastname"));
        $myxls->write_string(0, 4, get_string("email"));
        $i = 5;
        $addfields = explode(',', $booking->additionalfields);
        $addquoted = "'" . implode("','", $addfields) . "'";
        if ($userprofilefields = $DB->get_records_select('user_info_field', 'id > 0 AND shortname IN (' . $addquoted . ')', array(), 'id', 'id, shortname, name')) {
            foreach ($userprofilefields as $profilefield) {
                $myxls->write_string(0, $i++, $profilefield->name);
            }
        }
        $myxls->write_string(0, $i++, get_string("group"));
/// generate the data for the body of the spreadsheet
        $row = 1;

        if ($bookinglist && ($action == "all")) { // get list of all booking options
            foreach ($bookinglist as $optionid => $optionvalue) {

                $option_text = booking_get_option_text($booking, $optionid);
                foreach ($bookinglist[$optionid] as $usernumber => $user) {
                    if ($usernumber > $booking->option[$optionid]->maxanswers) {
                        $cellform = $cellformat1;
                    } else {
                        $cellform = $cellformat;
                    }
                    if (isset($option_text)) {
                        $myxls->write_string($row, 0, format_string($option_text, true));
                    }
                    $myxls->write_string($row, 1, $user->id, $cellform);
                    $myxls->write_string($row, 2, $user->firstname, $cellform);
                    $myxls->write_string($row, 3, $user->lastname, $cellform);
                    $myxls->write_string($row, 4, $user->email, $cellform);
                    $i = 5;
                    if ($DB->get_records_select('user_info_data', 'userid = ' . $user->id, array(), 'fieldid')) {
                        foreach ($userprofilefields as $profilefieldid => $profilefield) {
                            $myxls->write_string($row, $i++, strip_tags($DB->get_field('user_info_data', 'data', array('fieldid' => $profilefieldid, 'userid' => $user->id))), $cellform);
                            $neki = strip_tags($DB->get_field('user_info_data', 'data', array('fieldid' => $profilefieldid, 'userid' => $user->id)));
                        }
                    } else {
                        $myxls->write_string($row, $i++, '');
                    }
                    $studentid = (!empty($user->idnumber) ? $user->idnumber : " ");
                    $ug2 = '';
                    if ($usergrps = groups_get_all_groups($course->id, $user->id)) {
                        foreach ($usergrps as $ug) {
                            $ug2 = $ug2 . $ug->name;
                        }
                    }
                    $row++;
                    $pos = 4;
                }
            }
        } elseif ($bookinglist && !empty($bookinglist[$action])) { // get list of one specified booking option: $action is $optionid
            foreach ($bookinglist[$action] as $usernumber => $user) {
                if ($usernumber > $booking->option[$action]->maxanswers) {
                    $cellform = $cellformat1;
                } else {
                    $cellform = $cellformat;
                }
                if (isset($option_text)) {
                    $myxls->write_string($row, 0, format_string($option_text, true));
                }
                $myxls->write_string($row, 1, $user->id, $cellform);
                $myxls->write_string($row, 2, $user->firstname, $cellform);
                $myxls->write_string($row, 3, $user->lastname, $cellform);
                $myxls->write_string($row, 4, $user->email, $cellform);
                $i = 5;
                if ($DB->get_records_select('user_info_data', 'userid = ' . $user->id, array(), 'fieldid')) {
                    foreach ($userprofilefields as $profilefieldid => $profilefield) {
                        $fType = $DB->get_field('user_info_field', 'datatype', array('shortname' => $profilefield->shortname));
                        $value = $DB->get_field('user_info_data', 'data', array('fieldid' => $profilefieldid, 'userid' => $user->id), NULL, IGNORE_MISSING);
                        if ($fType == 'datetime') {
                            if ($value != FALSE) {
                                $myxls->write_string($row, $i++, userdate($value, get_string('strftimedatefullshort')), $cellform);
                            } else {
                                $myxls->write_string($row, $i++, '', $cellform);
                            }
                        } else {
                            $myxls->write_string($row, $i++, strip_tags($value), $cellform);
                        }
                    }
                } else {
                    $myxls->write_string($row, $i++, '');
                }
                $studentid = (!empty($user->idnumber) ? $user->idnumber : " ");
                $ug2 = '';
                if ($usergrps = groups_get_all_groups($course->id, $user->id)) {
                    foreach ($usergrps as $ug) {
                        $ug2 = $ug2 . $ug->name;
                    }
                }
//$myxls->write_string($row,12,$ug2);
                $row++;
                $pos = 4;
            }
        }
/// Close the workbook
        $workbook->close();
        exit;
    }
}
?>

<script type="text/javascript">
YUI().use('node', function(Y) {
    Y.delegate('click', function(e) {
        var buttonID = e.currentTarget.get('id'),
            node = Y.one('#tableSearch');

        if (buttonID === 'showHideSearch') {
            node.toggleView();
            e.preventDefault();
        }

    }, document, 'a');
});
</script>