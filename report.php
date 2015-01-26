<?php
/**
 * Manage bookings
 *
 * @package   Booking
 * @copyright 2012 David Bogner www.edulabs.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
require_once("../../config.php");
require_once("locallib.php");
require_once("bookingmanageusers.class.php");
require_once("$CFG->dirroot/user/profile/lib.php");

// Find only matched... http://blog.codinghorror.com/a-visual-explanation-of-sql-joins/

$id = required_param('id', PARAM_INT);   //moduleid
$optionid = optional_param('optionid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHANUM);
$confirm = optional_param('confirm', '', PARAM_INT);
$page = optional_param('page', '0', PARAM_INT);

// Search 
$searchName = optional_param('searchName', '', PARAM_TEXT);
$searchSurname = optional_param('searchSurname', '', PARAM_TEXT);
$searchDate = optional_param('searchDate', '', PARAM_TEXT);
$searchDateDay = optional_param('searchDateDay', '', PARAM_TEXT);
$searchDateMonth = optional_param('searchDateMonth', '', PARAM_TEXT);
$searchDateYear = optional_param('searchDateYear', '', PARAM_TEXT);
$searchFinished = optional_param('searchFinished', '', PARAM_TEXT);
// from view.php
$searchText = optional_param('searchText', '', PARAM_TEXT);
$searchLocation = optional_param('searchLocation', '', PARAM_TEXT);
$searchInstitution = optional_param('searchInstitution', '', PARAM_TEXT);
$whichview = optional_param('whichview', '', PARAM_ALPHA);

$perPage = 25;

$searching = FALSE;

$urlParams = array();
$urlParams['id'] = $id;

if ($optionid > 0) {
    $urlParams['optionid'] = $optionid;
}

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

$urlParams['searchText'] = "";
if (strlen($searchText) > 0) {
    $urlParams['searchText'] = $searchText;
}

$urlParams['searchLocation'] = "";
if (strlen($searchLocation) > 0) {
    $urlParams['searchLocation'] = $searchLocation;
}

$urlParams['searchInstitution'] = "";
if (strlen($searchInstitution) > 0) {
    $urlParams['searchInstitution'] = $searchInstitution;
}

if (!empty($whichview)) {
    $urlParams['whichview'] = $whichview;
} else {
    $urlParams['whichview'] = 'showactive';
}

if ($action !== '') {
    $urlParams['action'] = $action;
}

$url = new moodle_url('/mod/booking/report.php', $urlParams);

$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    error("Course Module ID was incorrect");
}

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);

if ($optionid == 0) {
    $bookingData = new booking_options($cm->id, FALSE, $urlParams);
    $bookingData->apply_tags();
    $bookinglist = $bookingData->allbookedusers;

    if (has_capability('mod/booking:readresponses', $context)) {
        require_capability('mod/booking:readresponses', $context);
    }
} else {
    $bookingData = new booking_option($cm->id, $optionid, $urlParams, $page, $perPage);
    $bookingData->apply_tags();
    $bookingData->get_url_params();

    if (!(booking_check_if_teacher($bookingData->option, $USER) || has_capability('mod/booking:readresponses', $context))) {
        require_capability('mod/booking:readresponses', $context);
    }

    $event = \mod_booking\event\report_viewed::create(array(
                'objectid' => $optionid,
                'context' => context_module::instance($cm->id)
    ));
    $event->trigger();
}

$strbooking = get_string("modulename", "booking");
$strbookings = get_string("modulenameplural", "booking");
$strresponses = get_string("responses", "booking");

if ($action == 'deletebookingoption' && $confirm == 1 && has_capability('mod/booking:updatebooking', $context) && confirm_sesskey()) {
    booking_delete_booking_option($bookingData->booking, $optionid); //delete booking_option
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

$PAGE->navbar->add($strresponses);
$PAGE->set_title(format_string($bookingData->booking->name) . ": $strresponses");
$PAGE->set_heading($course->fullname);

if (isset($action) && $action == 'sendpollurlteachers' && has_capability('mod/booking:communicate', $context)) {
    booking_sendpollurlteachers($bookingData, $cm->id, $optionid);
    $url->remove_params('action');
    redirect($url, get_string('allmailssend', 'booking'), 5);
}

if (!$download) {
    $bookingData->option->courseurl = new moodle_url('/course/view.php', array('id' => $bookingData->option->courseid));
    $bookingData->option->urltitle = $DB->get_field('course', 'shortname', array('id' => $bookingData->option->courseid));
    $bookingData->option->cmid = $cm->id;
    $bookingData->option->autoenrol = $bookingData->booking->autoenrol;
    $mform = new mod_booking_manageusers_form(null, array('cm' => $cm, 'bookingdata' => $bookingData->option, 'waitinglistusers' => $bookingData->usersOnWaitingList, 'bookedusers' => $bookingData->usersOnList)); //name of the form you defined in file above.
//managing the form
    if ($mform->is_cancelled()) {
        redirect("view.php?id=$cm->id");
    } else if ($fromform = $mform->get_data()) {
//this branch is where you process validated data.
        if (isset($fromform->deleteusers) && has_capability('mod/booking:deleteresponses', $context) && confirm_sesskey()) {
            $bookingData->delete_responses(array_keys($fromform->user, 1));
            redirect($url);
        } else if (isset($fromform->subscribetocourse) && confirm_sesskey()) { // subscription submitted            
            if ($bookingData->option->courseid != 0) {
                foreach (array_keys($fromform->user, 1) as $selecteduserid) {
                    booking_enrol_user($bookingData->option, $bookingData->booking, $selecteduserid);
                }
                redirect($url, get_string('userrssucesfullenroled', 'booking'), 5);
            } else {
                redirect($url, get_string('nocourse', 'booking'), 5);
            }
            die;
        } else if (isset($fromform->sendpollurl) && has_capability('mod/booking:communicate', $context) && confirm_sesskey()) {
            $selectedusers[$optionid] = array_keys($fromform->user, 1);

            if (empty($selectedusers[$optionid])) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            booking_sendpollurl($selectedusers, $bookingData, $cm->id, $optionid);
            redirect($url, get_string('allmailssend', 'booking'), 5);
        } else if (isset($fromform->sendcustommessage) && has_capability('mod/booking:communicate', $context) && confirm_sesskey()) {
            $selectedusers = array_keys($fromform->user, 1);

            if (empty($selectedusers[$optionid])) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            $sendmessageurl = new moodle_url('/mod/booking/sendmessage.php', array('id' => $id, 'optionid' => $optionid, 'uids' => serialize($selectedusers)));
            redirect($sendmessageurl);
        } else if (isset($fromform->activitycompletion) && (booking_check_if_teacher($bookingData->option, $USER) || has_capability('mod/booking:readresponses', $context)) && confirm_sesskey()) {
            $selectedusers[$optionid] = array_keys($fromform->user, 1);

            if (empty($selectedusers[$optionid])) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            booking_activitycompletion($selectedusers, $bookingData->booking, $cm->id, $optionid);
            redirect($url, get_string('activitycompletionsuccess', 'booking'), 5);
        } else if (isset($fromform->booktootherbooking) && (booking_check_if_teacher($bookingData->option, $USER) || has_capability('mod/booking:readresponses', $context)) && confirm_sesskey()) {

            $selectedusers[$optionid] = array_keys($fromform->user, 1);

            if (empty($selectedusers[$optionid])) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            if (count($selectedusers[$optionid]) > $bookingData->canBookToOtherBooking) {
                redirect($url, get_string('toomuchusersbooked', 'booking', $bookingData->canBookToOtherBooking), 5);
            }

            $tmpcmid = $DB->get_record_sql("SELECT cm.id FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module JOIN {booking} m ON m.id = cm.instance WHERE md.name = 'booking' AND cm.instance = ?", array($bookingData->booking->conectedbooking));
            $tmpBooking = new booking_option($tmpcmid->id, $bookingData->option->conectedoption);

            foreach ($selectedusers[$optionid] as $value) {
                $user = new stdClass();
                $user->id = $value;
                $tmpBooking->user_submit_response($user);
            }

            redirect($url, get_string('userssucesfullybooked', 'booking', $bookingData->canBookToOtherBooking), 5);
        }
    } else {
        echo $OUTPUT->header();
    }

    echo $OUTPUT->heading($bookingData->option->text, 4, '', '');

    $urlParamsODS = $urlParams;
    $urlParamsXLS = $urlParams;
    $urlParamsODS['id'] = $bookingData->option->cmid;
    $urlParamsXLS['id'] = $bookingData->option->cmid;
    $urlParamsODS['action'] = $bookingData->option->id;
    $urlParamsXLS['action'] = $bookingData->option->id;
    $urlParamsODS['download'] = 'ods';
    $urlParamsXLS['download'] = 'xls';
    $urlParamsODS['optionid'] = $bookingData->option->id;
    $urlParamsXLS['optionid'] = $bookingData->option->id;

    echo html_writer::link(new moodle_url('/mod/booking/editoptions.php', array('id' => $bookingData->option->cmid, 'optionid' => $bookingData->option->id)), get_string('updatebooking', 'booking'), array()) .
    ' | ' .
    html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $bookingData->option->cmid, 'optionid' => $bookingData->option->id, 'action' => 'deletebookingoption', 'sesskey' => sesskey())), get_string('deletebookingoption', 'booking'), array()) .
    ' | ' .
    html_writer::link(new moodle_url('/mod/booking/report.php', $urlParamsODS), get_string('downloadusersforthisoptionods', 'booking'), array()) .
    ' | ' .
    html_writer::link(new moodle_url('/mod/booking/report.php', $urlParamsXLS), get_string('downloadusersforthisoptionxls', 'booking'), array());

    echo html_writer::link(new moodle_url('/mod/booking/view.php', array('id' => $cm->id)), get_string('gotobooking', 'booking'), array('style' => 'float:right;'));

    echo "<br>";

    $links = array();

    if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
        $links[] = html_writer::link(new moodle_url('/mod/booking/teachers.php', array('id' => $id, 'optionid' => $optionid)), get_string('teachers', 'booking'), array());
    }

    if (has_capability('mod/booking:subscribeusers', $context)) {        
        $links[] = html_writer::link(new moodle_url('/mod/booking/subscribeusers.php', array('id' => $cm->id, 'optionid' => $optionid)), get_string('bookotherusers', 'booking'), array());
    }

    $links[] = '<a href="#" id="showHideSearch">' . get_string('search') . '</a>';

    if (has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
        $links[] = html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $cm->id, 'optionid' => $optionid, 'action' => 'sendpollurlteachers')), get_string('booking:sendpollurltoteachers', 'booking'), array());
    }

    echo implode(" | ", $links);

    if ($bookingData->option->courseid != 0) {
        echo '<br>' . html_writer::start_span('') . get_string('associatedcourse', 'booking') . ': ' . html_writer::link(new moodle_url($bookingData->option->courseurl, array()), $bookingData->option->urltitle, array()) . html_writer::end_span() . '<br>';
    }

    $onlyOneURL = new moodle_url('/mod/booking/view.php', array('id' => $id, 'optionid' => $optionid, 'action' => 'showonlyone', 'whichview' => 'showonlyone'));
    $onlyOneURL->set_anchor('goenrol');    
    echo '<br>' . html_writer::start_span('') . get_string('onlythisbookingurl', 'booking') . ': ' . html_writer::link($onlyOneURL, $onlyOneURL, array()) . html_writer::end_span() . '<br><br>';

    $hidden = "";

    foreach ($urlParams as $key => $value) {
        if (!in_array($key, array('searchName', 'searchSurname', 'searchDate', 'searchFinished'))) {
            $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
        }
    }

    $row = new html_table_row(array(get_string('searchName', "booking"), '<form>' . $hidden . '<input value="' . $urlParams['searchName'] . '" id="searchName" type="text" name="searchName">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";
    $row = new html_table_row(array(get_string('searchSurname', "booking"), '<input value="' . $urlParams['searchSurname'] . '" id="searchSurname" type="text" name="searchSurname">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";
    $row = new html_table_row(array(get_string('searchDate', "booking"), html_writer::checkbox('searchDate', '1', $checked, '', array('id' => 'searchDate')) . html_writer::select_time('days', 'searchDateDay', $timestamp, 5) . ' ' . html_writer::select_time('months', 'searchDateMonth', $timestamp, 5) . ' ' . html_writer::select_time('years', 'searchDateYear', $timestamp, 5), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(array(get_string('searchFinished', "booking"), html_writer::select(array('0' => get_string('no', "booking"), '1' => get_string('yes', "booking")), 'searchFinished', $urlParams['searchFinished']), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(array("", '<input type="submit" id="searchButton" value="' . get_string('search') . '"><input id="buttonclear" type="button" value="' . get_string('reset', 'booking') . '"></form>', "", ""));
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

    echo $OUTPUT->paging_bar($bookingData->count_users(), $page, $perPage, $url);

    echo $OUTPUT->footer();
} else {
    if ($download == "ods" OR $download == "xls" && has_capability('mod/booking:downloadresponses', $context)) {
        if ($action == "all") {
            $filename = clean_filename("$course->shortname " . strip_tags(format_string($bookingData->booking->name, true)));
        } else {
            $optionname = $bookingData->option->text;
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
            $cellformat = '';
            $cellformat1 = $workbook->add_format(array('bg_color' => 'red'));
        } else {
            $cellformat = '';
            $cellformat1 = $workbook->add_format(array('bg_color' => 'red'));
        }
/// Print names of all the fields

        if ($action == "all") {
            $myxls->write_string(0, 0, get_string("booking", "booking"));
            $myxls->write_string(0, 1, get_string("institution", "booking"));
            $myxls->write_string(0, 2, get_string("location", "booking"));
            $myxls->write_string(0, 3, get_string("coursestarttime", "booking"));
            $myxls->write_string(0, 4, get_string("courseendtime", "booking"));
            $myxls->write_string(0, 5, get_string("user") . " " . get_string("idnumber"));
            $myxls->write_string(0, 6, get_string("firstname"));
            $myxls->write_string(0, 7, get_string("lastname"));
            $myxls->write_string(0, 8, get_string("email"));
            $myxls->write_string(0, 9, get_string("searchFinished", "booking"));
            $i = 10;
        } else {
            $myxls->write_string(0, 0, get_string("booking", "booking"));
            $myxls->write_string(0, 1, get_string("user") . " " . get_string("idnumber"));
            $myxls->write_string(0, 2, get_string("firstname"));
            $myxls->write_string(0, 3, get_string("lastname"));
            $myxls->write_string(0, 4, get_string("email"));
            $i = 5;
        }
        $addfields = explode(',', $bookingData->booking->additionalfields);
        $addquoted = "'" . implode("','", $addfields) . "'";
        if ($userprofilefields = $DB->get_records_select('user_info_field', 'id > 0 AND shortname IN (' . $addquoted . ')', array(), 'id', 'id, shortname, name')) {
            foreach ($userprofilefields as $profilefield) {
                $myxls->write_string(0, $i++, $profilefield->name);
            }
        }
        $myxls->write_string(0, $i++, get_string("group"));
/// generate the data for the body of the spreadsheet
        $row = 1;

        if (isset($bookinglist) && ($action == "all")) { // get list of all booking options
            foreach ($bookinglist as $optionid => $optionvalue) {
                $bookingData = new booking_option($cm->id, $optionid);
                $bookingData->apply_tags();

                $option_text = $bookingData->option->text;
                $institution = $bookingData->option->institution;
                $location = $bookingData->option->location;
                $coursestarttime = $bookingData->option->coursestarttime;
                $courseendtime = $bookingData->option->courseendtime;


                foreach ($bookingData->users as $usernumber => $user) {
                    if ($user->waitinglist) {
                        $cellform = $cellformat1;
                    } else {
                        $cellform = $cellformat;
                    }

                    if (isset($option_text)) {
                        $myxls->write_string($row, 0, format_string($option_text, true));
                    }

                    if (isset($institution)) {
                        $myxls->write_string($row, 1, format_string($institution, true));
                    }

                    if (isset($location)) {
                        $myxls->write_string($row, 2, format_string($location, true));
                    }

                    if (isset($coursestarttime) && $coursestarttime > 0) {
                        $myxls->write_string($row, 3, userdate($coursestarttime, get_string('strftimedatetime')));
                    }

                    if (isset($courseendtime) && $courseendtime > 0) {
                        $myxls->write_string($row, 4, userdate($courseendtime, get_string('strftimedatetime')));
                    }

                    $myxls->write_string($row, 5, $user->id, $cellform);
                    $myxls->write_string($row, 6, $user->firstname, $cellform);
                    $myxls->write_string($row, 7, $user->lastname, $cellform);
                    $myxls->write_string($row, 8, $user->email, $cellform);
                    $myxls->write_string($row, 9, $user->completed, $cellform);
                    $i = 10;
                    if ($DB->get_records_select('user_info_data', 'userid = ' . $user->id, array(), 'fieldid')) {
                        foreach ($userprofilefields as $profilefieldid => $profilefield) {
                            $fType = $DB->get_field('user_info_field', 'datatype', array('shortname' => $profilefield->shortname));
                            $value = $DB->get_field('user_info_data', 'data', array('fieldid' => $profilefieldid, 'userid' => $user->id), NULL, IGNORE_MISSING);
                            if ($fType == 'datetime') {
                                if ($value != FALSE) {
                                    $myxls->write_string($row, $i++, userdate($value, get_string('strftimedate')), $cellform);
                                } else {
                                    $myxls->write_string($row, $i++, '', $cellform);
                                }
                            } else {
                                $myxls->write_string($row, $i++, strip_tags($value), $cellform);
                            }
                        }
                    } else {
                        $myxls->write_string($row, $i++, '', $cellform);
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
        } else { // get list of one specified booking option: $action is $optionid
            foreach ($bookingData->get_all_users() as $usernumber => $user) {
                $bookingData = new booking_option($cm->id, $optionid);
                $bookingData->apply_tags();
                $option_text = $bookingData->option->text;

                if ($user->waitinglist) {
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
                                $myxls->write_string($row, $i++, userdate($value, get_string('strftimedate')), $cellform);
                            } else {
                                $myxls->write_string($row, $i++, '', $cellform);
                            }
                        } else {
                            $myxls->write_string($row, $i++, strip_tags($value), $cellform);
                        }
                    }
                } else {
                    $myxls->write_string($row, $i++, '', $cellform);
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

    YUI().use('node-event-simulate', function (Y) {

        Y.one('#buttonclear').on('click', function () {
            Y.one('#menusearchFinished').set('value', '');
            Y.one('#searchName').set('value', '');
            Y.one('#searchSurname').set('value', '');
            Y.one('#searchDate').set('value', '');
            Y.one('#searchButton').simulate('click');
        });
    });

    YUI().use('node', function (Y) {
        Y.delegate('click', function (e) {
            var buttonID = e.currentTarget.get('id'),
                    node = Y.one('#tableSearch');

            if (buttonID === 'showHideSearch') {
                node.toggleView();
                e.preventDefault();
            }

        }, document, 'a');
    });
</script>