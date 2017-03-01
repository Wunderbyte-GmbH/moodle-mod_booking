<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Manage bookings for a booking option
 *
 * @package mod_booking
 * @copyright 2012 David Bogner www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("locallib.php");
require_once("{$CFG->libdir}/tablelib.php");
require_once("{$CFG->dirroot}/mod/booking/classes/all_userbookings.php");
require_once("{$CFG->dirroot}/user/profile/lib.php");
require_once($CFG->dirroot . '/rating/lib.php');

$id = required_param('id', PARAM_INT); // moduleid
$optionid = required_param('optionid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHANUM);
$confirm = optional_param('confirm', '', PARAM_INT);
$page = optional_param('page', '0', PARAM_INT);

// Search
$searchdate = optional_param('searchdate', 0, PARAM_INT);
$searchdateday = optional_param('searchdateday', null, PARAM_INT);
$searchdatemonth = optional_param('searchdatemonth', null, PARAM_INT);
$searchdateyear = optional_param('searchdateyear', null, PARAM_INT);
$searchfinished = optional_param('searchfinished', 0, PARAM_INT) - 1;
$searchwaitinglist = optional_param('searchwaitinglist', 0, PARAM_INT) - 1;

// from view.php
$searchtext = optional_param('searchtext', '', PARAM_TEXT);
$searchlocation = optional_param('searchlocation', '', PARAM_TEXT);
$searchinstitution = optional_param('searchinstitution', '', PARAM_TEXT);
$whichview = optional_param('whichview', '', PARAM_ALPHA);

// form values
$ratingarea = optional_param('ratingarea', '', PARAM_ALPHAEXT);
$scaleid = optional_param('scaleid', '', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$aggregation = optional_param('aggregate', '', PARAM_INT);
$searching = false;

$urlparams = array();
$urlparams['id'] = $id;
$urlparams['page'] = $page;

$sqlvalues = array();
$addsqlwhere = '';

if ($optionid > 0) {
    $urlparams['optionid'] = $optionid;
    $sqlvalues['optionid'] = $optionid;
}

$timestamp = time();

$urlparams['searchdateday'] = "";
if ($searchdateday > 0) {
    $urlparams['searchdateday'] = $searchdateday;
}

$urlparams['searchdatemonth'] = "";
if (!is_null($searchdatemonth)) {
    $urlparams['searchdatemonth'] = $searchdatemonth;
}

$urlparams['searchdateyear'] = "";
if (!is_null($searchdateyear)) {
    $urlparams['searchdateyear'] = $searchdateyear;
}

$checked = false;
$urlparams['searchdate'] = "";
if ($searchdate == 1) {
    $urlparams['searchdate'] = $searchdate;
    $checked = true;
    $beginofday = strtotime("{$urlparams['searchdateday']}-{$urlparams['searchdatemonth']}-{$urlparams['searchdateyear']}");
    $endofday = strtotime("tomorrow", $beginofday) - 1;
    $addsqlwhere .= " AND ba.timecreated BETWEEN :beginofday AND :endofday";
    $sqlvalues['beginofday'] = $beginofday;
    $sqlvalues['endofday'] = $endofday;
    $searching = true;
}

$urlparams['searchfinished'] = "";
if ($searchfinished > -1) {
    $urlparams['searchfinished'] = $searchfinished + 1;
    $sqlvalues['completed'] = $searchfinished;
    $addsqlwhere .= ' AND ba.completed = :completed ';
    $searching = true;
}

$urlparams['searchwaitinglist'] = "";
if ($searchwaitinglist > -1) {
    $urlparams['searchwaitinglist'] = $searchwaitinglist + 1;
    $sqlvalues['searchwaitinglist'] = $searchwaitinglist;
    $addsqlwhere .= ' AND ba.waitinglist = :searchwaitinglist ';
    $searching = true;
}

$urlparams['searchtext'] = "";
if (strlen($searchtext) > 0) {
    $urlparams['searchtext'] = $searchtext;
}

$urlparams['searchlocation'] = "";
if (strlen($searchlocation) > 0) {
    $urlparams['searchlocation'] = $searchlocation;
}

$urlparams['searchinstitution'] = "";
if (strlen($searchinstitution) > 0) {
    $urlparams['searchinstitution'] = $searchinstitution;
}

if (!empty($whichview)) {
    $urlparams['whichview'] = $whichview;
} else {
    $urlparams['whichview'] = 'showactive';
}

if ($action !== '') {
    $urlparams['action'] = $action;
}

$url = new moodle_url('/mod/booking/report.php', $urlparams);
$currenturl = new moodle_url('/mod/booking/report.php', $urlparams);

$PAGE->set_url($url);
$PAGE->requires->yui_module('moodle-mod_booking-utility', 'M.mod_booking.utility.init');

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);

$bookingdata = new \mod_booking\booking_option($cm->id, $optionid, $urlparams, $page, 25,
        false);
$bookingdata->urparams = $urlparams;
$bookingdata->apply_tags();
$bookingdata->get_url_params();
$bookingdata->get_teachers();
$paging = $bookingdata->booking->paginationnum;
if ($paging < 1) {
    $paging = 25;
}
if (!(booking_check_if_teacher($bookingdata->option) ||
         has_capability('mod/booking:readresponses', $context))) {
    require_capability('mod/booking:readresponses', $context);
}

$event = \mod_booking\event\report_viewed::create(
        array('objectid' => $optionid, 'context' => context_module::instance($cm->id)));
$event->trigger();

if ($action == 'downloadsigninsheet') {
    booking_download_sign_in_sheet($bookingdata);
    die();
}

if ($action == 'deletebookingoption' && $confirm == 1 &&
         has_capability('mod/booking:updatebooking', $context) && confirm_sesskey()) {
    booking_delete_booking_option($bookingdata->booking, $optionid);
    redirect("view.php?id=$cm->id");
} else if ($action == 'deletebookingoption' && has_capability('mod/booking:updatebooking', $context) &&
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

$tableallbookings = new all_userbookings('mod_booking_all_users_sort_new', $bookingdata, $cm, $optionid);
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

        if (isset($_POST['generaterecnum']) && (booking_check_if_teacher($bookingdata->option,
                $USER) || has_capability('mod/booking:updatebooking', $context))) {
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
        } else if (isset($_POST['activitycompletion']) && (booking_check_if_teacher(
                $bookingdata->option, $USER) || has_capability('mod/booking:readresponses', $context))) {
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
        } else if (isset($_POST['postratingsubmit']) && (booking_check_if_teacher(
                $bookingdata->option, $USER) || has_capability('moodle/rating:rate', $context))) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }
            $alluserids = $bookingdata->get_all_userids();
            $bookedusers = array();
            $ratings = array();
            foreach ($alluserids as $baid => $userid) {
                if (in_array($userid, $allselectedusers) && $userid != $USER->id) {
                    $rating = new stdClass();
                    $bookedusers[$userid] = $baid;
                    $bookinganswerid = "rating" . $bookedusers[$userid];

                    $rating->rateduserid = $userid;
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
            if (!empty($ratings)) {
                booking_rate($ratings, $params);
                redirect($url,
                        (empty($bookingdata->option->notificationtext) ? get_string('ratingsuccess',
                                'booking') : $bookingdata->option->notificationtext), 5);
            }
        } else if (isset($_POST['sendreminderemail']) &&
                 has_capability('mod/booking:communicate', $context)) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }

            booking_sendreminderemail($allselectedusers, $bookingdata->booking, $cm->id, $optionid);
            redirect($url, get_string('sendreminderemailsuccess', 'booking'), 5);
        } else if (isset($_POST['booktootherbooking']) && (booking_check_if_teacher(
                $bookingdata->option, $USER) || has_capability('mod/booking:readresponses', $context))) {
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingdata->option->howmanyusers), 5);
            }

            if (!isset($_POST['selectoptionid']) || empty($_POST['selectoptionid'])) {
                redirect($url, get_string('selectoptionid', 'booking'), 5);
            }

            if (count($allselectedusers) > $bookingdata->calculate_how_many_can_book_to_other($_POST['selectoptionid'])) {
                redirect($url,
                        get_string('toomuchusersbooked', 'booking',
                                $bookingdata->calculate_how_many_can_book_to_other(
                                        $_POST['selectoptionid'])), 5);
            }

            $connectedbooking = $DB->get_record("booking",
                    array('conectedbooking' => $bookingdata->booking->id), 'id', IGNORE_MULTIPLE);

            $tmpcmid = $DB->get_record_sql(
                    "SELECT cm.id FROM {course_modules} cm
                    JOIN {modules} md ON md.id = cm.module
                    JOIN {booking} m ON m.id = cm.instance
                    WHERE md.name = 'booking' AND cm.instance = ?",
                    array($connectedbooking->id));
            $tmpbooking = new \mod_booking\booking_option($tmpcmid->id, $_POST['selectoptionid']);

            foreach ($allselectedusers as $value) {
                $user = new stdClass();
                $user->id = $value;
                if (!$tmpbooking->user_submit_response($user, $optionid)) {
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
        $headers[] = get_string('searchwaitinglist', 'mod_booking');
    }

    $strbooking = get_string("modulename", "booking");
    $strbookings = get_string("modulenameplural", "booking");
    $strresponses = get_string("responses", "booking");

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
    $from = ' {booking_answers} ba
            JOIN {user} u ON u.id = ba.userid
            JOIN {booking_options} bo ON bo.id = ba.optionid
            LEFT JOIN {booking_options} otherbookingoption ON otherbookingoption.id = ba.frombookingid ';
    $where = ' ba.optionid = :optionid ' . $addsqlwhere;

    $tableallbookings->set_sql($fields, $from, $where, $sqlvalues);

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
        echo '<br>' . html_writer::start_span('') . get_string('associatedcourse', 'booking') . ': ' . html_writer::link(
                new moodle_url($bookingdata->option->courseurl, array()), $bookingdata->option->urltitle,
                array()) . html_writer::end_span();
    }

    $hidden = "";

    foreach ($urlparams as $key => $value) {
        $arr = array('searchdate', 'searchfinished');
        if (!in_array($key, $arr)) {
            $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
        }
    }

    $row = new html_table_row(
            array(get_string('searchdate', "booking"),
                $hidden . html_writer::checkbox('searchdate', '1', $checked, '',
                        array('id' => 'searchdate')) .
                         html_writer::select_time('days', 'searchdateday', $timestamp, 5) . ' ' .
                         html_writer::select_time('months', 'searchdatemonth', $timestamp, 5) . ' ' .
                         html_writer::select_time('years', 'searchdateyear', $timestamp, 5), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
            array(get_string('searchfinished', "booking"),
                html_writer::select(
                        array('1' => get_string('no', "booking"),
                            '2' => get_string('yes', "booking")), 'searchfinished',
                        $urlparams['searchfinished']), "", ""));
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
            array(get_string('searchwaitinglist', "booking"),
                html_writer::select(
                        array('1' => get_string('no', "booking"),
                            '2' => get_string('yes', "booking")), 'searchwaitinglist',
                        $urlparams['searchwaitinglist']), "", ""));
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
    $tableallbookings->query_db($paging, true);
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
        $scalearray = array(RATING_UNSET_RATING => $strrate . '...') + $firstentry->rating->settings->scale->scaleitems;
        $scaleattrs = array('class' => 'postratingmenu ratinginput', 'id' => 'menuratingall');
        $menuhtml = html_writer::label(get_string('rating', 'core_rating'), 'menuratingall', false,
                array('class' => 'accesshide'));
        $menuhtml .= html_writer::select($scalearray, 'rating', $scalearray[RATING_UNSET_RATING],
                false, $scaleattrs);
        $tableallbookings->headers[2] .= $menuhtml;
    }

    $tableallbookings->build_table();
    $tableallbookings->finish_output();

    $onlyoneurl = new moodle_url('/mod/booking/view.php',
            array('id' => $id, 'optionid' => $optionid, 'action' => 'showonlyone',
                'whichview' => 'showonlyone'));
    $onlyoneurl->set_anchor('goenrol');

    $pollurl = trim($bookingdata->option->pollurl);
    if (!empty($pollurl)) {
        echo html_writer::link($bookingdata->option->pollurl, get_string('copypollurl', 'booking'),
                array('onclick' => 'copyToClipboard("' . $pollurl . '"); return false;')) .
                 ($bookingdata->option->pollsend ? ' &#x2713;' : '') . ' | ';
    }

    echo html_writer::link($onlyoneurl, get_string('onlythisbookingurl', 'booking'), array());
    echo ' | ' . html_writer::link($onlyoneurl, get_string('copyonlythisbookingurl', 'booking'),
            array('onclick' => 'copyToClipboard("' . $onlyoneurl . '"); return false;')) . ' | ';

    $signinsheeturl = new moodle_url('/mod/booking/report.php',
            array('id' => $id, 'optionid' => $optionid, 'action' => 'downloadsigninsheet'));

    echo html_writer::link($signinsheeturl, get_string('sign_in_sheet_download', 'booking'),
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
    if (has_capability('moodle/site:viewuseridentity', $context)) {
        $columns[] = 'institution';
        $headers[] = get_string("institution", "booking");
    }
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
    $headers[] = get_string("searchfinished", "booking");
    $columns[] = 'waitinglist';
    $headers[] = get_string("waitinglist", "booking");

    $addfields = explode(',', $bookingdata->booking->additionalfields);
    $addquoted = "'" . implode("','", $addfields) . "'";
    if ($userprofilefields = $DB->get_records_select('user_info_field',
            'id > 0 AND shortname IN (' . $addquoted . ')', array(), 'id', 'id, shortname, name')) {
        foreach ($userprofilefields as $profilefield) {
            $columns[] = "cust" . strtolower($profilefield->shortname);
            $headers[] = $profilefield->name;
            $customfields .= ", (SELECT " . $DB->sql_concat('uif.datatype', "'|'", 'uid.data') . " as custom
                     FROM {user_info_data} uid
                     LEFT JOIN {user_info_field}  uif ON uid.fieldid = uif.id
                     WHERE userid = ba.userid
                     AND uif.shortname = '{$profilefield->shortname}') AS cust" .
                     strtolower($profilefield->shortname);
        }
    }

    $columns[] = 'groups';
    $headers[] = get_string("group");
    if ($DB->count_records_select('user', ' idnumber <> \'\'') > 0 && has_capability('moodle/site:viewuseridentity', $context)) {
        $columns[] = 'idnumber';
        $headers[] = get_string("idnumber");
    }

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
                    ba.waitinglist AS waitinglist,
                    u.idnumber as idnumber
                    {$customfields}";
    $from = '{booking_answers} ba
            JOIN {user}  u ON u.id = ba.userid
            JOIN {booking_options} bo ON bo.id = ba.optionid';
    $where = 'ba.optionid = :optionid ' . $addsqlwhere;

    $tableallbookings->define_columns($columns);
    $tableallbookings->define_headers($headers);

    $tableallbookings->set_sql($fields, $from, $where, $sqlvalues);
    $tableallbookings->setup();
    $tableallbookings->query_db(10);
    if (!empty($tableallbookings->rawdata)) {
        foreach ($tableallbookings->rawdata as $option) {
            $option->otheroptions = "";
            $option->groups = "";
            $groups = groups_get_user_groups($course->id, $option->userid);
            if (!empty($groups[0])) {
                list ($insql, $paramsin) = $DB->get_in_or_equal($groups[0]);
                $groupnames = $DB->get_fieldset_select('groups', 'name',
                        ' id ' . $insql , $paramsin);
                $option->groups = implode(', ', $groupnames);
            }
        }
    }

    $tableallbookings->build_table();
    $tableallbookings->finish_output();

    exit();
}