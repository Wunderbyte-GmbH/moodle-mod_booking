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
require_once("{$CFG->libdir}/tablelib.php");
require_once("{$CFG->dirroot}/mod/booking/classes/all_users.php");
require_once("{$CFG->dirroot}/mod/booking/classes/unbooked_users.php");
require_once("$CFG->dirroot/user/profile/lib.php");

// Find only matched... http://blog.codinghorror.com/a-visual-explanation-of-sql-joins/

$id = required_param('id', PARAM_INT);   //moduleid
$optionid = optional_param('optionid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHANUM);
$confirm = optional_param('confirm', '', PARAM_INT);
$page = optional_param('page', '0', PARAM_INT);

// Search 
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
$urlParams['page'] = $page;

$sqlValues = array();
$addSQLWhere = '';

if ($optionid > 0) {
    $urlParams['optionid'] = $optionid;
    $sqlValues['optionid'] = $optionid;
}

$timestamp = time();

$urlParams['searchDateDay'] = "";
if (strlen($searchDateDay) > 0) {
    $urlParams['searchDateDay'] = $searchDateDay;
}

$urlParams['searchDateMonth'] = "";
if (strlen($searchDateMonth) > 0) {
    $urlParams['searchDateMonth'] = $searchDateMonth;
}

$urlParams['searchDateYear'] = "";
if (strlen($searchDateYear) > 0) {
    $urlParams['searchDateYear'] = $searchDateYear;
}

$checked = FALSE;
$urlParams['searchDate'] = "";
if ($searchDate == 1) {
    $urlParams['searchDate'] = $searchDate;
    $checked = TRUE;
    $timestamp = strtotime("{$urlParams['searchDateDay']}-{$urlParams['searchDateMonth']}-{$urlParams['searchDateYear']}");
    $addSQLWhere .= " AND FROM_UNIXTIME(ba.timecreated, '%Y') = :searchdateyear AND FROM_UNIXTIME(ba.timecreated, '%c') = :searchdatemonth AND FROM_UNIXTIME(ba.timecreated, '%e') = :searchdateday";
    $sqlValues['searchdateyear'] = $urlParams['searchDateYear'];
    $sqlValues['searchdatemonth'] = $urlParams['searchDateMonth'];
    $sqlValues['searchdateday'] = $urlParams['searchDateDay'];
    $searching = TRUE;
}

$urlParams['searchFinished'] = "";
if (strlen($searchFinished) > 0) {
    $urlParams['searchFinished'] = $searchFinished;
    $sqlValues['completed'] = $searchFinished;
    $addSQLWhere .= ' AND ba.completed = :completed ';
    $searching = TRUE;
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

    $currenturl = new moodle_url('/mod/booking/report.php', $urlParams);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $allSelectedUsers = array();

        if (isset($_POST['user'])) {
            foreach ($_POST['user'] as $value) {
                $allSelectedUsers[] = array_keys($value)[0];
            }
        } else {
            redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
        }


        if (isset($_POST['deleteusers']) && has_capability('mod/booking:deleteresponses', $context)) {
            $bookingData->delete_responses($allSelectedUsers);
            redirect($url);
        } else if (isset($_POST['subscribetocourse'])) { // subscription submitted            
            if ($bookingData->option->courseid != 0) {
                foreach ($allSelectedUsers as $selecteduserid) {
                    booking_enrol_user($bookingData->option, $bookingData->booking, $selecteduserid);
                }
                redirect($url, get_string('userrssucesfullenroled', 'booking'), 5);
            } else {
                redirect($url, get_string('nocourse', 'booking'), 5);
            }
            die;
        } else if (isset($_POST['sendpollurl']) && has_capability('mod/booking:communicate', $context)) {
            if (empty($allSelectedUsers)) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            booking_sendpollurl($allSelectedUsers, $bookingData, $cm->id, $optionid);
            redirect($url, get_string('allmailssend', 'booking'), 5);
        } else if (isset($_POST['sendcustommessage']) && has_capability('mod/booking:communicate', $context)) {
            if (empty($allSelectedUsers)) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            $sendmessageurl = new moodle_url('/mod/booking/sendmessage.php', array('id' => $id, 'optionid' => $optionid, 'uids' => serialize($allSelectedUsers)));
            redirect($sendmessageurl);
        } else if (isset($_POST['activitycompletion']) && (booking_check_if_teacher($bookingData->option, $USER) || has_capability('mod/booking:readresponses', $context))) {
            if (empty($allSelectedUsers)) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            booking_activitycompletion($allSelectedUsers, $bookingData->booking, $cm->id, $optionid);
            redirect($url, (empty($bookingData->option->notificationtext) ? get_string('activitycompletionsuccess', 'booking') : $bookingData->option->notificationtext), 5);
        } else if (isset($_POST['booktootherbooking']) && (booking_check_if_teacher($bookingData->option, $USER) || has_capability('mod/booking:readresponses', $context))) {
            if (empty($allSelectedUsers)) {
                redirect($url, get_string('selectatleastoneuser', 'booking', $bookingData->option->howmanyusers), 5);
            }

            if (!isset($_POST['selectoptionid']) || empty($_POST['selectoptionid'])) {
                redirect($url, get_string('selectoptionid', 'booking'), 5);
            }
            
            if (count($allSelectedUsers) > $bookingData->calculateHowManyCanBookToOther($_POST['selectoptionid'])) {
                redirect($url, get_string('toomuchusersbooked', 'booking', $bookingData->calculateHowManyCanBookToOther($_POST['selectoptionid'])), 5);
            }                    

            $tmpcmid = $DB->get_record_sql("SELECT cm.id FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module JOIN {booking} m ON m.id = cm.instance WHERE md.name = 'booking' AND cm.instance = ?", array($bookingData->booking->conectedbooking));
            $tmpBooking = new booking_option($tmpcmid->id, $_POST['selectoptionid']);

            foreach ($allSelectedUsers as $value) {
                $user = new stdClass();
                $user->id = $value;
                $tmpBooking->user_submit_response($user, $optionid);
            }

            redirect($url, get_string('userssucesfullybooked', 'booking', $bookingData->calculateHowManyCanBookToOther($_POST['selectoptionid'])), 5);
        }
    }

    // ALL USERS - START    
    $tableAllUsers = new all_users('all_users');
    $tableAllUsers->is_downloading($download, 'all_users', 'testing123');

    $fields = 'u.id, ' . get_all_user_name_fields(true, 'u') . ', u.username, u.firstname, u.lastname, u.institution, ba.completed, ba.timecreated, ba.userid, (SELECT 
            GROUP_CONCAT(obo.text SEPARATOR \', \')
        FROM
            mdl_booking_answers AS oba
            LEFT JOIN mdl_booking_options AS obo ON obo.id = oba.optionid
        WHERE
            oba.frombookingid = ba.optionid
                AND oba.userid = ba.userid) AS otheroptions';
    $from = ' {booking_answers} AS ba JOIN {user} AS u ON u.id = ba.userid JOIN {booking_options} AS bo ON bo.id = ba.optionid';
    $where = ' ba.optionid = :optionid AND ba.waitinglist = 0 ' . $addSQLWhere;

    $tableAllUsers->set_sql(
            $fields, $from, $where, $sqlValues);

    $tableAllUsers->define_baseurl($currenturl);
    $tableAllUsers->is_downloadable(false);
    $tableAllUsers->show_download_buttons_at(array(TABLE_P_BOTTOM));
    // ALL USERS - STOP
    // ALL USERS - START    
    $tableUnbookedUsers = new all_users('unbooked_users');
    $tableUnbookedUsers->is_downloading($download, 'all_users', 'testing123');

    $fields = 'u.id, ' . get_all_user_name_fields(true, 'u') . ', u.username, u.firstname, u.lastname, u.institution, ba.completed, ba.timecreated, ba.userid';
    $from = ' {booking_answers} AS ba JOIN {user} AS u ON u.id = ba.userid JOIN {booking_options} AS bo ON bo.id = ba.optionid';

    $where = ' ba.optionid = :optionid AND ba.waitinglist = 1 ' . $addSQLWhere;

    $tableUnbookedUsers->set_sql(
            $fields, $from, $where, $sqlValues);

    $tableUnbookedUsers->define_baseurl($currenturl);
    $tableUnbookedUsers->is_downloadable(false);
    $tableUnbookedUsers->show_download_buttons_at(array(TABLE_P_BOTTOM));
    // ALL USERS - STOP

    echo $OUTPUT->header();

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
        $links[] = html_writer::link(new moodle_url('/mod/booking/teachers.php', array('id' => $id, 'optionid' => $optionid)), (empty($bookingData->booking->lblteachname) ? get_string('teachers', 'booking') : $bookingData->booking->lblteachname), array());
    }

    if (has_capability('mod/booking:subscribeusers', $context)) {
        $links[] = html_writer::link(new moodle_url('/mod/booking/subscribeusers.php', array('id' => $cm->id, 'optionid' => $optionid)), get_string('bookotherusers', 'booking'), array());
    }

    $links[] = '<a href="#" id="showHideSearch">' . get_string('search') . '</a>';

    if (has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
        $links[] = html_writer::link(new moodle_url('/mod/booking/report.php', array('id' => $cm->id, 'optionid' => $optionid, 'action' => 'sendpollurlteachers')), (empty($bookingData->booking->lblsputtname) ? get_string('booking:sendpollurltoteachers', 'booking') : $bookingData->booking->lblsputtname), array());
    }

    echo implode(" | ", $links);

    if ($bookingData->option->courseid != 0) {
        echo '<br>' . html_writer::start_span('') . get_string('associatedcourse', 'booking') . ': ' . html_writer::link(new moodle_url($bookingData->option->courseurl, array()), $bookingData->option->urltitle, array()) . html_writer::end_span() . '<br>';
    }

    $hidden = "";

    foreach ($urlParams as $key => $value) {
        if (!in_array($key, array('searchDate', 'searchFinished'))) {
            $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
        }
    }

    $row = new html_table_row(array(get_string('searchDate', "booking"), '<form>' . $hidden . html_writer::checkbox('searchDate', '1', $checked, '', array('id' => 'searchDate')) . html_writer::select_time('days', 'searchDateDay', $timestamp, 5) . ' ' . html_writer::select_time('months', 'searchDateMonth', $timestamp, 5) . ' ' . html_writer::select_time('years', 'searchDateYear', $timestamp, 5), "", ""));
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

    echo '<form action="' . $currenturl . '" method="post" id="studentsform">' . "\n";

    echo '<h5>' . get_string('bookedusers', 'booking') . '</h5>';
    $tableAllUsers->out(25, true);

    echo '<h5>' . get_string('waitinglistusers', 'booking') . '</h5>';
    $tableUnbookedUsers->out(25, true);


    echo '<div class="selectbuttons">';
    echo '<input type="button" id="checkall" value="' . get_string('selectall') . '" /> ';
    echo '<input type="button" id="checknone" value="' . get_string('deselectall') . '" /> ';

    echo '</div>';
    echo '<div>';
    if (!$bookingData->booking->autoenrol && has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
        if ($bookingData->option->courseid > 0) {
            echo '<input type="submit" name="subscribetocourse" value="' . get_string('subscribetocourse', 'booking') . '" />';
        }
    }

    if (has_capability('mod/booking:deleteresponses', context_module::instance($cm->id))) {
        echo '<input type="submit" name="deleteusers" value="' . get_string('booking:deleteresponses', 'booking') . '" />';
    }

    if (has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
        echo '<input type="submit" name="sendpollurl" value="' . get_string('booking:sendpollurl', 'booking') . '" />';
        echo '<input type="submit" name="sendcustommessage" value="' . get_string('sendcustommessage', 'booking') . '" />';
    }

    if (booking_check_if_teacher($bookingData->option, $USER) || has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
        echo '<input type="submit" name="activitycompletion" value="' . (empty($bookingData->booking->btncacname) ? get_string('confirmactivitycompletion', 'booking') : $bookingData->booking->btncacname) . '" />';
        if ($bookingData->booking->conectedbooking > 0) {
            $result = $DB->get_records_select("booking_options", "bookingid = {$bookingData->booking->conectedbooking} AND id <> {$optionid}", null, 'text ASC', 'id, text');
            
            $options = array();
            
            foreach ($result as $value) {
                $options[$value->id] = $value->text;
            }
            
            echo "<br>";
            
            echo html_writer::select($options, 'selectoptionid', '');

            echo '<input type="submit" name="booktootherbooking" value="' . get_string('booktootherbooking', 'booking') . '" />';
        }
    }

    echo '</div>';
    echo '</form>';


    $onlyOneURL = new moodle_url('/mod/booking/view.php', array('id' => $id, 'optionid' => $optionid, 'action' => 'showonlyone', 'whichview' => 'showonlyone'));
    $onlyOneURL->set_anchor('goenrol');
    echo '<br>' . html_writer::start_span('') . get_string('onlythisbookingurl', 'booking') . ': ' . html_writer::link($onlyOneURL, $onlyOneURL, array()) . html_writer::end_span();
    echo '<br>' . html_writer::start_span('') . get_string('pollurl', 'booking') . ': ' . html_writer::link($bookingData->option->pollurl, $bookingData->option->pollurl, array()) . ($bookingData->option->pollsend ? ' &#x2713;' : '') . html_writer::end_span();

    $PAGE->requires->js_init_call('M.mod_booking.init');

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
            $myxls->write_string(0, 0, get_string("optionid", "booking"));
            $myxls->write_string(0, 1, get_string("booking", "booking"));
            $myxls->write_string(0, 2, get_string("institution", "booking"));
            $myxls->write_string(0, 3, get_string("location", "booking"));
            $myxls->write_string(0, 4, get_string("coursestarttime", "booking"));
            $myxls->write_string(0, 5, get_string("courseendtime", "booking"));
            $myxls->write_string(0, 6, get_string("user") . " " . get_string("idnumber"));
            $myxls->write_string(0, 7, get_string("firstname"));
            $myxls->write_string(0, 8, get_string("lastname"));
            $myxls->write_string(0, 9, get_string("email"));
            $myxls->write_string(0, 10, get_string("searchFinished", "booking"));
            $i = 11;
        } else {
            $myxls->write_string(0, 0, get_string("optionid", "booking"));
            $myxls->write_string(0, 1, get_string("booking", "booking"));
            $myxls->write_string(0, 2, get_string("user") . " " . get_string("idnumber"));
            $myxls->write_string(0, 3, get_string("firstname"));
            $myxls->write_string(0, 4, get_string("lastname"));
            $myxls->write_string(0, 5, get_string("email"));
            $myxls->write_string(0, 6, get_string("searchFinished", "booking"));
            $i = 7;
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

                    $myxls->write_string($row, 0, format_string($bookingData->option->id, true));

                    if (isset($option_text)) {
                        $myxls->write_string($row, 1, format_string($option_text, true));
                    }

                    if (isset($institution)) {
                        $myxls->write_string($row, 2, format_string($institution, true));
                    }

                    if (isset($location)) {
                        $myxls->write_string($row, 3, format_string($location, true));
                    }

                    if (isset($coursestarttime) && $coursestarttime > 0) {
                        $myxls->write_string($row, 4, userdate($coursestarttime, get_string('strftimedatetime')));
                    }

                    if (isset($courseendtime) && $courseendtime > 0) {
                        $myxls->write_string($row, 5, userdate($courseendtime, get_string('strftimedatetime')));
                    }

                    $myxls->write_string($row, 6, $user->id, $cellform);
                    $myxls->write_string($row, 7, $user->firstname, $cellform);
                    $myxls->write_string($row, 8, $user->lastname, $cellform);
                    $myxls->write_string($row, 9, $user->email, $cellform);
                    $myxls->write_string($row, 10, $user->completed, $cellform);
                    $i = 11;
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
                $myxls->write_string($row, 0, format_string($bookingData->option->id, true));

                if (isset($option_text)) {
                    $myxls->write_string($row, 1, format_string($option_text, true));
                }
                $myxls->write_string($row, 2, $user->id, $cellform);
                $myxls->write_string($row, 3, $user->firstname, $cellform);
                $myxls->write_string($row, 4, $user->lastname, $cellform);
                $myxls->write_string($row, 5, $user->email, $cellform);
                $myxls->write_string($row, 6, $user->completed, $cellform);
                $i = 7;
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