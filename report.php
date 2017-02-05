<?php
/**
 * Manage bookings
 *
 * @package Booking
 * @copyright 2012 David Bogner www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("locallib.php");
require_once("{$CFG->libdir}/tablelib.php");
require_once("{$CFG->dirroot}/mod/booking/classes/all_userbookings.php");
require_once("{$CFG->dirroot}/user/profile/lib.php");
require_once($CFG->dirroot . '/rating/lib.php');

// Find only matched... http://blog.codinghorror.com/a-visual-explanation-of-sql-joins/

$id = required_param('id', PARAM_INT); // moduleid
$optionid = required_param('optionid', PARAM_INT);
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
$searchWaitingList = optional_param('searchWaitingList', '', PARAM_TEXT);

// from view.php
$searchText = optional_param('searchText', '', PARAM_TEXT);
$searchLocation = optional_param('searchLocation', '', PARAM_TEXT);
$searchInstitution = optional_param('searchInstitution', '', PARAM_TEXT);
$whichview = optional_param('whichview', '', PARAM_ALPHA);

// form values
$ratingarea = optional_param('ratingarea', '', PARAM_ALPHAEXT);
$scaleid = optional_param('scaleid', '', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$aggregation = optional_param('aggregate', '', PARAM_INT);

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
    $timestamp = strtotime(
            "{$urlParams['searchDateDay']}-{$urlParams['searchDateMonth']}-{$urlParams['searchDateYear']}");
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

$urlParams['searchWaitingList'] = "";
if (strlen($searchWaitingList) > 0) {
    $urlParams['searchWaitingList'] = $searchWaitingList;
    $sqlValues['searchwaitinglist'] = $searchWaitingList;
    $addSQLWhere .= ' AND ba.waitinglist = :searchwaitinglist ';
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
$currenturl = new moodle_url('/mod/booking/report.php', $urlParams);

$PAGE->set_url($url);
$PAGE->requires->yui_module('moodle-mod_booking-utility', 'M.mod_booking.utility.init');

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);

$bookingdata = new \mod_booking\booking_option($cm->id, $optionid, $urlParams, $page, $perPage,
        false);
$bookingdata->apply_tags();
$bookingdata->get_url_params();
$bookingdata->get_teachers();

if (!(booking_check_if_teacher($bookingdata->option, $USER) ||
         has_capability('mod/booking:readresponses', $context))) {
    require_capability('mod/booking:readresponses', $context);
}

$event = \mod_booking\event\report_viewed::create(
        array('objectid' => $optionid, 'context' => context_module::instance($cm->id)));
$event->trigger();

if ($action == 'downloadsigninsheet') {
    download_sign_in_sheet($bookingdata);
    die();
}

if ($action == 'deletebookingoption' && $confirm == 1 &&
         has_capability('mod/booking:updatebooking', $context) && confirm_sesskey()) {
    booking_delete_booking_option($bookingdata->booking, $optionid); // delete booking_option
    redirect("view.php?id=$cm->id");
} elseif ($action == 'deletebookingoption' && has_capability('mod/booking:updatebooking', $context) &&
         confirm_sesskey()) {
    echo $OUTPUT->header();
    $confirmarray['action'] = 'deletebookingoption';
    $confirmarray['confirm'] = 1;
    $confirmarray['optionid'] = $optionid;
    $continue = $url;
    $cancel = new moodle_url('/mod/booking/report.php', array('id' => $id, 'optionid' => $optionid));
    $continue->params($confirmarray);
    echo $OUTPUT->confirm(get_string('confirmdeletebookingoption', 'booking'), $continue, $cancel);
    echo $OUTPUT->footer();
    die();
}

$PAGE->navbar->add($bookingdata->option->text);
$PAGE->set_title(format_string($bookingdata->booking->name) . ": " . $bookingdata->option->text);
$PAGE->set_heading($course->fullname);

if (isset($action) && $action == 'sendpollurlteachers' &&
         has_capability('mod/booking:communicate', $context)) {
    booking_sendpollurlteachers($bookingdata, $cm->id, $optionid);
    $url->remove_params('action');
    redirect($url, get_string('allmailssend', 'booking'), 5);
}

$bookingdata->option->courseurl = new moodle_url('/course/view.php',
        array('id' => $bookingdata->option->courseid));
$bookingdata->option->urltitle = $DB->get_field('course', 'shortname',
        array('id' => $bookingdata->option->courseid));
$bookingdata->option->cmid = $cm->id;
$bookingdata->option->autoenrol = $bookingdata->booking->autoenrol;

$tableallbookings = new all_userbookings('mod_booking_all_users_sort_new', $bookingdata, $cm, $USER,
        $DB, $optionid);
$tableallbookings->is_downloading($download, $bookingdata->option->text, $bookingdata->option->text);

$tableallbookings->define_baseurl($currenturl);
$tableallbookings->defaultdownloadformat = 'ods';
$tableallbookings->sortable(true, 'firstname');
if (has_capability('mod/booking:downloadresponses', $context)) {
    $tableallbookings->is_downloadable(true);
} else {
    $tableallbookings->is_downloadable(false);
}
$tableallbookings->show_download_buttons_at(array(TABLE_P_BOTTOM));
$tableallbookings->no_sorting('selected');
$tableallbookings->no_sorting('rating');

if (!$tableallbookings->is_downloading()) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

        $allselectedusers = array();

        if (isset($_POST['generaterecnum']) &&
                 (booking_check_if_teacher($bookingdata->option, $USER) ||
                 has_capability('mod/booking:updatebooking', $context))) {
            if (isset($_POST['user'])) {
                foreach ($_POST['user'] as $value) {
                    $allselectedusers[] = array_keys($value)[0];
                }
            }

            booking_generatenewnumners($bookingdata->booking, $cm->id, $optionid, $allselectedusers);

            redirect($url, get_string('generaterecnumnotification', 'booking'), 5);
        }

        $allselectedusers = array();

        if (isset($_POST['user'])) {
            foreach ($_POST['user'] as $value) {
                $allselectedusers[] = array_keys($value)[0];
            }
        } else {
            redirect($url,
                    get_string('selectatleastoneuser', 'booking',
                            $bookingdata->option->howmanyusers), 5);
        }

        if (isset($_POST['deleteusers']) && has_capability('mod/booking:deleteresponses', $context)) {
            $res = $bookingdata->delete_responses($allselectedusers);

            $data = new stdClass();

            $data->all = count($res);
            $data->del = 0;
            foreach ($res as $value) {
                if ($value == true) {
                    $data->del++;
                }
            }

            redirect($url, get_string('delnotification', 'booking', $data), 5);
        } else if (isset($_POST['subscribetocourse'])) { // subscription submitted
            if ($bookingdata->option->courseid != 0) {
                foreach ($allselectedusers as $selecteduserid) {
                    booking_enrol_user($bookingdata->option, $bookingdata->booking, $selecteduserid);
                }
                redirect($url, get_string('userrssucesfullenroled', 'booking'), 5);
            } else {
                redirect($url, get_string('nocourse', 'booking'), 5);
            }
            die();
        } else if (isset($_POST['sendpollurl']) &&
                 has_capability('mod/booking:communicate', $context)) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }

            booking_sendpollurl($allselectedusers, $bookingdata, $cm->id, $optionid);
            redirect($url, get_string('allmailssend', 'booking'), 5);
        } else if (isset($_POST['sendcustommessage']) &&
                 has_capability('mod/booking:communicate', $context)) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }

            $sendmessageurl = new moodle_url('/mod/booking/sendmessage.php',
                    array('id' => $id, 'optionid' => $optionid,
                        'uids' => serialize($allselectedusers)));
            redirect($sendmessageurl);
        } else if (isset($_POST['activitycompletion']) &&
                 (booking_check_if_teacher($bookingdata->option, $USER) ||
                 has_capability('mod/booking:readresponses', $context))) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }

            booking_activitycompletion($allselectedusers, $bookingdata->booking, $cm->id, $optionid);
            redirect($url,
                    (empty($bookingdata->option->notificationtext) ? get_string(
                            'activitycompletionsuccess', 'booking') : $bookingdata->option->notificationtext),
                    5);
        } else if (isset($_POST['postratingsubmit']) &&
                 (booking_check_if_teacher($bookingdata->option, $USER) ||
                 has_capability('moodle/rating:rate', $context))) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }
            $bookingdata->get_users();
            $bookedusers = array();
            $ratings = array();
            foreach ($bookingdata->users as $baid => $object) {
                if (in_array($object->id, $allselectedusers) && $object->userid != $USER->id) {
                    $rating = new stdClass();
                    $bookedusers[$object->userid] = $baid;
                    $bookinganswerid = "rating" . $bookedusers[$object->userid];

                    $rating->rateduserid = $object->userid;
                    $rating->itemid = $baid;
                    $rating->rating = $_POST[$bookinganswerid];
                    $ratings[$baid] = $rating;
                    // params valid for all ratings
                    $params = new stdClass();
                    $params->contextid = $context->id;
                    $params->scaleid = $scaleid;
                    $params->returnurl = $returnurl;
                }
            }
            booking_rate($ratings, $params);
            redirect($url,
                    (empty($bookingdata->option->notificationtext) ? get_string('ratingsuccess',
                            'booking') : $bookingdata->option->notificationtext), 5);
        } else if (isset($_POST['sendreminderemail']) &&
                 has_capability('mod/booking:communicate', $context)) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }

            booking_sendreminderemail($allselectedusers, $bookingdata->booking, $cm->id, $optionid);
            redirect($url, get_string('sendreminderemailsuccess', 'booking'), 5);
        } else if (isset($_POST['booktootherbooking']) &&
                 (booking_check_if_teacher($bookingdata->option, $USER) ||
                 has_capability('mod/booking:readresponses', $context))) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }

            if (!isset($_POST['selectoptionid']) || empty($_POST['selectoptionid'])) {
                redirect($url, get_string('selectoptionid', 'booking'), 5);
            }

            if (count($allselectedusers) >
                     $bookingdata->calculateHowManyCanBookToOther($_POST['selectoptionid'])) {
                redirect($url,
                        get_string('toomuchusersbooked', 'booking',
                                $bookingdata->calculateHowManyCanBookToOther(
                                        $_POST['selectoptionid'])), 5);
            }

            $connectedBooking = $DB->get_record("booking",
                    array('conectedbooking' => $bookingdata->booking->id), 'id', IGNORE_MULTIPLE);

            $tmpcmid = $DB->get_record_sql(
                    "SELECT cm.id FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module JOIN {booking} m ON m.id = cm.instance WHERE md.name = 'booking' AND cm.instance = ?",
                    array($connectedBooking->id));
            $tmpBooking = new \mod_booking\booking_option($tmpcmid->id, $_POST['selectoptionid']);

            foreach ($allselectedusers as $value) {
                $user = new stdClass();
                $user->id = $value;
                if (!$tmpBooking->user_submit_response($user, $optionid)) {
                    redirect($url, get_string('bookingfulldidntregister', 'booking'), 5);
                }
            }

            redirect($url, get_string('userssucesfullybooked', 'booking'), 5);
        }
    }

    $columns = array();
    $headers = array();

    $columns[] = 'selected';
    $headers[] = '<input type="checkbox" id="usercheckboxall" name="selectall" value="0" />';
    $columns[] = 'completed';
    $headers[] = get_string('activitycompleted', 'mod_booking');

    if ($bookingdata->booking->assessed != RATING_AGGREGATE_NONE) {
        $columns[] = 'rating';
        $headers[] = get_string('rating', 'core_rating');
    }

    if ($bookingdata->booking->numgenerator) {
        $columns[] = 'numrec';
        $headers[] = get_string('numrec', 'mod_booking');
    }

    $columns[] = 'fullname';
    $headers[] = get_string('fullname', 'mod_booking');
    $columns[] = 'timecreated';
    $headers[] = get_string('timecreated', 'mod_booking');
    $columns[] = 'institution';
    $headers[] = get_string('institution', 'mod_booking');
    if ($bookingdata->option->limitanswers == 1 && $bookingdata->option->maxoverbooking > 0) {
        $columns[] = 'waitinglist';
        $headers[] = get_string('searchWaitingList', 'mod_booking');
    }

    $strbooking = get_string("modulename", "booking");
    $strbookings = get_string("modulenameplural", "booking");
    $strresponses = get_string("responses", "booking");

    if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
        $settingnode = $PAGE->settingsnav->add(get_string("optionmenu", "booking"), null,
                navigation_node::TYPE_CONTAINER);
        $settingnode->add(get_string('updatebooking', 'booking'),
                new moodle_url('/mod/booking/editoptions.php',
                        array('id' => $bookingdata->option->cmid,
                            'optionid' => $bookingdata->option->id)));
        $settingnode->add(get_string('duplicatebooking', 'booking'),
                new moodle_url('/mod/booking/editoptions.php',
                        array('id' => $bookingdata->option->cmid, 'optionid' => 'add',
                            'copyoptionid' => $bookingdata->option->id)));
        $settingnode->add(get_string('deletebookingoption', 'booking'),
                new moodle_url('/mod/booking/report.php',
                        array('id' => $bookingdata->option->cmid,
                            'optionid' => $bookingdata->option->id,
                            'action' => 'deletebookingoption', 'sesskey' => sesskey())));
        $settingnode->add(get_string('optiondates', 'booking'),
                new moodle_url('/mod/booking/optiondates.php',
                        array('id' => $bookingdata->option->cmid,
                            'optionid' => $bookingdata->option->id)));
    }

    if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id)) &&
             $bookingdata->booking->conectedbooking > 0) {
        $settingnode->add(get_string('editotherbooking', 'booking'),
                new moodle_url('/mod/booking/otherbooking.php',
                        array('cmid' => $id, 'optionid' => $optionid)));
    }

    // ALL USERS - START
    $fields = 'ba.id, ' . get_all_user_name_fields(true, 'u') . ',
            u.username,
            u.institution,
            ba.completed,
            ba.timecreated,
            ba.userid,
            ba.waitinglist,
            otherbookingoption.text AS otheroptions,
            ba.numrec';
    $from = ' {booking_answers} AS ba
            JOIN {user} AS u ON u.id = ba.userid
            JOIN {booking_options} AS bo ON bo.id = ba.optionid
            LEFT JOIN {booking_options} AS otherbookingoption ON otherbookingoption.id = ba.frombookingid ';
    $where = ' ba.optionid = :optionid ' . $addSQLWhere;

    $tableallbookings->set_sql($fields, $from, $where, $sqlValues);

    $tableallbookings->define_columns($columns);
    $tableallbookings->define_headers($headers);

    // ALL USERS - STOP

    echo $OUTPUT->header();

    echo $OUTPUT->heading(
            html_writer::link(new moodle_url('/mod/booking/view.php', array('id' => $cm->id)),
                    $bookingdata->booking->name) . ' > ' . $bookingdata->option->text, 4);

    $teachers = array();

    foreach ($bookingdata->option->teachers as $value) {
        $teachers[] = html_writer::link(
                new moodle_url('/user/profile.php', array('id' => $value->userid)),
                "{$value->firstname} {$value->lastname}", array());
    }

    $linkst = '';
    if (has_capability('mod/booking:communicate', context_module::instance($cm->id)) ||
             has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
        $linkst = array();

        if (has_capability('mod/booking:updatebooking', context_module::instance($cm->id))) {
            $linkst[] = html_writer::link(
                    new moodle_url('/mod/booking/teachers.php',
                            array('id' => $id, 'optionid' => $optionid)),
                    get_string('editteachers', 'booking'), array());
        }

        if (has_capability('mod/booking:communicate', context_module::instance($cm->id))) {
            $linkst[] = html_writer::link(
                    new moodle_url('/mod/booking/report.php',
                            array('id' => $cm->id, 'optionid' => $optionid,
                                'action' => 'sendpollurlteachers')),
                    (empty($bookingdata->booking->lblsputtname) ? get_string(
                            'sendpollurltoteachers', 'booking') : $bookingdata->booking->lblsputtname),
                    array());
        }

        $linkst = "(" . implode(", ", $linkst) . ")";
    }

    echo "<p>" .
             ($bookingdata->option->coursestarttime == 0 ? get_string('nodateset', 'booking') : userdate(
                    $bookingdata->option->coursestarttime, get_string('strftimedatetime')) . " - " .
             userdate($bookingdata->option->courseendtime, get_string('strftimedatetime'))) . " | " .
             (empty($bookingdata->booking->lblteachname) ? get_string('teachers', 'booking') : $bookingdata->booking->lblteachname) .
             implode(', ', $teachers) . " {$linkst}</p>";

    $links = array();

    if (has_capability('mod/booking:subscribeusers', $context)) {
        $links[] = html_writer::link(
                new moodle_url('/mod/booking/subscribeusers.php',
                        array('id' => $cm->id, 'optionid' => $optionid)),
                get_string('bookotherusers', 'booking'), array('style' => 'float:right;'));
    }

    $links[] = '<a href="#" style="float:right;" id="showHideSearch">' . get_string('search') .
             '</a>';

    echo implode("<br>", $links);

    if ($bookingdata->option->courseid != 0) {
        echo '<br>' . html_writer::start_span('') . get_string('associatedcourse', 'booking') . ': ' .
                 html_writer::link(new moodle_url($bookingdata->option->courseurl, array()),
                        $bookingdata->option->urltitle, array()) . html_writer::end_span();
    }

    $hidden = "";

    foreach ($urlParams as $key => $value) {
        $arr = array('searchDate', 'searchFinished');
        if (!in_array($key, $arr)) {
            $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
        }
    }

    $row = new html_table_row(
            array(get_string('searchDate', "booking"),
                $hidden .
                         html_writer::checkbox('searchDate', '1', $checked, '',
                                array('id' => 'searchDate')) .
                         html_writer::select_time('days', 'searchDateDay', $timestamp, 5) . ' ' .
                         html_writer::select_time('months', 'searchDateMonth', $timestamp, 5) . ' ' .
                         html_writer::select_time('years', 'searchDateYear', $timestamp, 5), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
            array(get_string('searchFinished', "booking"),
                html_writer::select(
                        array('0' => get_string('no', "booking"),
                            '1' => get_string('yes', "booking")), 'searchFinished',
                        $urlParams['searchFinished']), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
            array(get_string('searchWaitingList', "booking"),
                html_writer::select(
                        array('0' => get_string('no', "booking"),
                            '1' => get_string('yes', "booking")), 'searchWaitingList',
                        $urlParams['searchWaitingList']), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
            array("",
                '<input type="submit" id="searchButton" value="' . get_string('search') .
                         '"><input id="buttonclear" type="button" value="' .
                         get_string('reset', 'booking') . '">', "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $table = new html_table();
    $table->head = array('', '', '', '');
    $table->data = $tabledata;
    $table->id = "tableSearch";
    if (!$searching) {
        $table->attributes = array('style' => "display: none;");
    }
    echo html_writer::tag('form', html_writer::table($table));

    echo '<h5>' . get_string('bookedusers', 'booking') . '</h5>';

    $tableallbookings->setup();
    $tableallbookings->query_db($bookingdata->booking->paginationnum, true);
    if ($bookingdata->booking->assessed != RATING_AGGREGATE_NONE &&
             !empty($tableallbookings->rawdata)) {
        // Get all bookings from all booking options: only that guarantees correct use of rating

        $ratingoptions = new stdClass();
        $ratingoptions->context = $bookingdata->get_context();
        $ratingoptions->component = 'mod_booking';
        $ratingoptions->ratingarea = 'bookingoption';
        $ratingoptions->items = $tableallbookings->rawdata;
        $ratingoptions->aggregate = $bookingdata->booking->assessed; // the aggregation method
        $ratingoptions->scaleid = $bookingdata->booking->scale;
        $ratingoptions->userid = $USER->id;
        $ratingoptions->returnurl = "$CFG->wwwroot/mod/booking/report.php?id=$cm->id&optionid=$optionid";
        $ratingoptions->assesstimestart = $bookingdata->booking->assesstimestart;
        $ratingoptions->assesstimefinish = $bookingdata->booking->assesstimefinish;

        $rm = new rating_manager();
        $tableallbookings->rawdata = $rm->get_ratings($ratingoptions);

        // Hidden input fields for the rating
        $ratinginputs = array();
        $ratinginputs['ratingarea'] = $ratingoptions->ratingarea;
        $ratinginputs['scaleid'] = $ratingoptions->scaleid;
        $ratinginputs['returnurl'] = $ratingoptions->returnurl;
        $ratinginputs['aggregation'] = $ratingoptions->aggregate;
        $ratinginputs['sesskey'] = sesskey();
        $tableallbookings->set_ratingoptions($ratinginputs);

        // Set menu for modifying all ratings at once
        // Get an example rating and modify it
        $newarray = array_values($tableallbookings->rawdata);
        $firstentry = array_shift($newarray);

        $strrate = get_string("rate", "rating");
        $scalearray = array(RATING_UNSET_RATING => $strrate . '...') +
                 $firstentry->rating->settings->scale->scaleitems;
        $scaleattrs = array('class' => 'postratingmenu ratinginput', 'id' => 'menuratingall');
        $menuhtml = html_writer::label(get_string('rating', 'core_rating'), 'menuratingall', false,
                array('class' => 'accesshide'));
        $menuhtml .= html_writer::select($scalearray, 'rating', $scalearray[RATING_UNSET_RATING],
                false, $scaleattrs);
        $tableallbookings->headers[2] .= $menuhtml;
    }

    $tableallbookings->build_table();
    $tableallbookings->finish_output();

    $onlyOneURL = new moodle_url('/mod/booking/view.php',
            array('id' => $id, 'optionid' => $optionid, 'action' => 'showonlyone',
                'whichview' => 'showonlyone'));
    $onlyOneURL->set_anchor('goenrol');

    $pollurl = trim($bookingdata->option->pollurl);
    if (!empty($pollurl)) {
        echo html_writer::link($bookingdata->option->pollurl, get_string('copypollurl', 'booking'),
                array('onclick' => 'copyToClipboard("' . $pollurl . '"); return false;')) .
                 ($bookingdata->option->pollsend ? ' &#x2713;' : '') . ' | ';
    }

    echo html_writer::link($onlyOneURL, get_string('onlythisbookingurl', 'booking'), array());
    echo ' | ' .
             html_writer::link($onlyOneURL, get_string('copyonlythisbookingurl', 'booking'),
                    array('onclick' => 'copyToClipboard("' . $onlyOneURL . '"); return false;')) .
             ' | ';

    $sign_in_sheet_URL = new moodle_url('/mod/booking/report.php',
            array('id' => $id, 'optionid' => $optionid, 'action' => 'downloadsigninsheet'));

    echo html_writer::link($sign_in_sheet_URL, get_string('sign_in_sheet_download', 'booking'),
            array('target' => '_blank'));

    echo "<script>
  function copyToClipboard(text) {
    window.prompt('" .
             get_string('copytoclipboard', 'booking') . "', text);
  }
</script>";

    echo $OUTPUT->footer();
} else {
    $columns = array();
    $headers = array();

    $customfields = '';

    $columns[] = 'optionid';
    $headers[] = get_string("optionid", "booking");
    $columns[] = 'booking';
    $headers[] = get_string("booking", "booking");
    $columns[] = 'institution';
    $headers[] = get_string("institution", "booking");
    $columns[] = 'location';
    $headers[] = get_string("location", "booking");
    $columns[] = 'coursestarttime';
    $headers[] = get_string("coursestarttime", "booking");
    $columns[] = 'courseendtime';
    $headers[] = get_string("courseendtime", "booking");
    if ($bookingdata->booking->numgenerator) {
        $columns[] = 'numrec';
        $headers[] = get_string("numrec", "booking");
    }
    $columns[] = 'userid';
    $headers[] = get_string("userid", "booking");
    $columns[] = 'username';
    $headers[] = get_string("username");
    $columns[] = 'firstname';
    $headers[] = get_string("firstname");
    $columns[] = 'lastname';
    $headers[] = get_string("lastname");
    $columns[] = 'email';
    $headers[] = get_string("email");
    $columns[] = 'completed';
    $headers[] = get_string("searchFinished", "booking");
    $columns[] = 'waitinglist';
    $headers[] = get_string("waitinglist", "booking");

    $addfields = explode(',', $bookingdata->booking->additionalfields);
    $addquoted = "'" . implode("','", $addfields) . "'";
    if ($userprofilefields = $DB->get_records_select('user_info_field',
            'id > 0 AND shortname IN (' . $addquoted . ')', array(), 'id', 'id, shortname, name')) {
        foreach ($userprofilefields as $profilefield) {
            $columns[] = "cust" . strtolower($profilefield->shortname);
            $headers[] = $profilefield->name;
            $customfields .= ", (SELECT " . $DB->sql_concat('uif.datatype', "'|'", ',uid.data') .
                     " as custom FROM {user_info_data} AS uid LEFT JOIN {user_info_field} AS uif ON uid.fieldid = uif.id WHERE userid = ba.userid AND uif.shortname = '{$profilefield->shortname}') AS cust" .
                     strtolower($profilefield->shortname);
        }
    }

    $columns[] = 'groups';
    $headers[] = get_string("group");

    $fields = "u.id AS userid,
        ba.optionid AS optionid,
                bo.text AS booking,
                u.institution AS institution,
                bo.location AS location,
                bo.coursestarttime AS coursestarttime,
                bo.courseendtime AS courseendtime,
                ba.numrec AS numrec,
                u.firstname AS firstname,
                u.lastname AS lastname,
                u.username AS username,
                u.email AS email,
                ba.completed AS completed,
                ba.numrec,
                ba.waitinglist AS waitinglist {$customfields}";
    $from = '{booking_answers} AS ba JOIN {user} AS u ON u.id = ba.userid JOIN {booking_options} AS bo ON bo.id = ba.optionid';
    $where = 'ba.optionid = :optionid ' . $addSQLWhere;

    $tableallbookings->define_columns($columns);
    $tableallbookings->define_headers($headers);

    $tableallbookings->set_sql($fields, $from, $where, $sqlValues);
    $tableallbookings->setup();
    $tableallbookings->query_db(10);
    if (!empty($tableallbookings->rawdata)) {
        foreach ($tableallbookings->rawdata as $option) {
            $option->otheroptions = "";
            $option->groups = "";
            $groups = groups_get_user_groups($course->id, $option->userid);
            if (!empty($groups[0])) {
                $groupids = implode(',', $groups[0]);
                $groupnames = $DB->get_fieldset_select('groups', 'name', ' id IN (' . $groupids .
                         ')');
                $option->groups = implode(', ', $groupnames);
            }
        }
    }

    $tableallbookings->build_table();
    $tableallbookings->finish_output();

    exit();
}
?>