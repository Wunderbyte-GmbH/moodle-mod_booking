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
 * @package     mod_booking
 * @copyright   2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Bogner
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_option;
use mod_booking\output\booked_users;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once("{$CFG->libdir}/tablelib.php");
require_once("{$CFG->dirroot}/mod/booking/classes/all_userbookings.php");
require_once("{$CFG->dirroot}/user/profile/lib.php");
require_once($CFG->dirroot . '/rating/lib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$optionid = required_param('optionid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHANUM);
$confirm = optional_param('confirm', '', PARAM_INT);
$page = optional_param('page', '0', PARAM_INT);
$orderby = optional_param('orderby', 'lastname', PARAM_ALPHANUM);
$orientation = optional_param('orientation', 'L', PARAM_ALPHA);
$pdfsessions = optional_param('pdfsessions', 0, PARAM_INT);
$signinextrasessioncols = optional_param('signinextrasessioncols', -1, PARAM_INT);
$pdftitle = optional_param('pdftitle', 1, PARAM_INT);
$addemptyrows = optional_param('addemptyrows', 0, PARAM_INT);
$includeteachers = optional_param('includeteachers', 0, PARAM_INT);

// Search.
$searchdate = optional_param('searchdate', 0, PARAM_INT);
$searchdateday = optional_param('searchdateday', null, PARAM_INT);
$searchdatemonth = optional_param('searchdatemonth', null, PARAM_INT);
$searchdateyear = optional_param('searchdateyear', null, PARAM_INT);
$searchcompleted = optional_param('searchcompleted', 0, PARAM_INT) - 1;
$searchwaitinglist = optional_param('searchwaitinglist', 0, PARAM_INT) - 1;

// Params from view.php.
$searchtext = optional_param('searchtext', '', PARAM_TEXT);
$searchlocation = optional_param('searchlocation', '', PARAM_TEXT);
$searchinstitution = optional_param('searchinstitution', '', PARAM_TEXT);
$whichview = optional_param('whichview', '', PARAM_ALPHA);

// Form valus.
$ratingarea = optional_param('ratingarea', '', PARAM_ALPHAEXT);
$scaleid = optional_param('scaleid', '', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$aggregation = optional_param('aggregate', '', PARAM_INT);
$searching = false;

$urlparams = [];
$urlparams['id'] = $id;
$urlparams['page'] = $page;

$sqlvalues = [];
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

$urlparams['searchcompleted'] = "";
if ($searchcompleted > -1) {
    $urlparams['searchcompleted'] = $searchcompleted + 1;
    $sqlvalues['completed'] = $searchcompleted;
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

$baseurl = new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]);
$url = new moodle_url('/mod/booking/report.php', $urlparams);
$currenturl = new moodle_url('/mod/booking/report.php', $urlparams);

$PAGE->set_url($url);
$PAGE->requires->js_call_amd('mod_booking/view_actions', 'setup');
$PAGE->force_settings_menu(true);
$PAGE->requires->js_call_amd('mod_booking/signinsheetdownload', 'init');

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$context = context_module::instance($cm->id);

$bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);
$bookingoption->urlparams = $urlparams;
$bookingoption->apply_tags();
$bookingoption->get_url_params();
$optionteachers = $bookingoption->get_teachers();

// Paging.
$paging = $bookingoption->booking->settings->paginationnum;
if ($paging < 1) {
    $paging = 25;
}

// Capability checks.
$isteacher = booking_check_if_teacher($bookingoption->option);
if (!($isteacher || has_capability('mod/booking:viewreports', $context))) {
    require_capability('mod/booking:readresponses', $context);
}

// Trigger report_viewed event.
$event = \mod_booking\event\report_viewed::create(
        ['objectid' => $optionid, 'context' => $context]);
$event->trigger();

if ($action == 'downloadpdf') {
    $pdfoptions = new stdClass();
    $pdfoptions->orientation = $orientation;
    $pdfoptions->orderby = $orderby;
    $pdfoptions->title = $pdftitle;
    $pdfoptions->sessions = $pdfsessions;
    $pdfoptions->extrasessioncols = $signinextrasessioncols;
    $pdfoptions->addemptyrows = $addemptyrows;
    $pdfoptions->includeteachers = $includeteachers;
    $pdf = new mod_booking\signinsheet\signinsheet_generator($bookingoption , $pdfoptions);
    $pdf->download_signinsheet();
    die();
}

if ($action == 'copytotemplate' && has_capability('mod/booking:manageoptiontemplates', $context) &&
         confirm_sesskey()) {
    $bookingoption->copytotemplate();
    redirect($baseurl, get_string('copytotemplatesucesfull', 'booking'), 5);
}

if ($action == 'deletebookingoption' && $confirm == 1 &&
         has_capability('mod/booking:updatebooking', $context) && confirm_sesskey()) {
             $bookingoption->delete_booking_option();
    redirect("view.php?id=$cm->id");
} else if ($action == 'deletebookingoption' && has_capability('mod/booking:updatebooking', $context) &&
         confirm_sesskey()) {
    echo $OUTPUT->header();
    $confirmarray['action'] = 'deletebookingoption';
    $confirmarray['confirm'] = 1;
    $confirmarray['optionid'] = $optionid;
    $continue = $url;
    $cancel = new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]);
    $continue->params($confirmarray);
    echo $OUTPUT->confirm(get_string('confirmdeletebookingoption', 'booking'), $continue, $cancel);
    echo $OUTPUT->footer();
    die();
}

// Create title string and add prefix if one exists.
$titlestring = $bookingoption->option->text;
if (!empty($bookingoption->option->titleprefix)) {
    $titlestring = $bookingoption->option->titleprefix . ' - ' . $titlestring;
}

$PAGE->navbar->add(format_string($titlestring));
$PAGE->set_title(format_string($bookingoption->booking->settings->name) . ": " . format_string($titlestring));
$PAGE->set_heading(format_string($course->fullname));

if (isset($action) && $action == 'sendpollurlteachers' &&
         has_capability('mod/booking:communicate', $context)) {

    // Send the Poll URL to the teacher(s).
    $bookingoption->sendmessage_pollurlteachers();

    $url->remove_params('action');
    redirect($url, get_string('allmailssend', 'booking'), 5);
}

$bookingoption->option->courseurl = new moodle_url('/course/view.php',
        ['id' => $bookingoption->option->courseid]);
$bookingoption->option->urltitle = $DB->get_field('course', 'shortname',
        ['id' => $bookingoption->option->courseid]);
$bookingoption->option->cmid = $cm->id;
$bookingoption->option->autoenrol = $bookingoption->booking->settings->autoenrol;

$tableallbookings = new \mod_booking\all_userbookings('mod_booking_all_users_sort_new', $bookingoption, $cm, $optionid);

// Bugfix: Replace special characters to prevent errors.
$filename = str_replace(' ', '_', $titlestring); // Replaces all spaces with underscores.
$filename = preg_replace('/[^A-Za-z0-9\_]/', '', $filename); // Removes special chars.
$filename = preg_replace('/\_+/', '_', $filename); // Replace multiple underscores with exactly one.
$filename = format_string($filename);
$sheetname = $filename; // Use the same name for the sheet as for the file.

$tableallbookings->is_downloading($download, $filename, $sheetname);

// Remove page number from url otherwise empty results are shown when searching via first/lastname letters.
$tablebaseurl = $currenturl;
$tablebaseurl->remove_params('page');
$tableallbookings->define_baseurl($tablebaseurl);
$tableallbookings->sortable(true, 'firstname');
if (has_capability('mod/booking:downloadresponses', $context)) {
    $tableallbookings->is_downloadable(true);
} else {
    $tableallbookings->is_downloadable(false);
}
$tableallbookings->show_download_buttons_at([TABLE_P_BOTTOM]);
$tableallbookings->no_sorting('selected');
$tableallbookings->no_sorting('rating');

if (!$tableallbookings->is_downloading()) {

    if ($action == 'postcustomreport') {
        $bookingoption->printcustomreport();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
        $allselectedusers = [];

        if (isset($_POST['generaterecnum']) && (($isteacher) || has_capability('mod/booking:updatebooking', $context))) {
            if (isset($_POST['user'])) {
                foreach ($_POST['user'] as $value) {
                    $allselectedusers[] = array_keys($value)[0];
                }
            }
            booking_generatenewnumbers($bookingoption->booking->settings, $cm->id, $optionid, $allselectedusers);
            redirect($url, get_string('generaterecnumnotification', 'booking'), 5);
        }

        if (isset($_POST['deleteusersactivitycompletion']) &&
                 has_capability('mod/booking:deleteresponses', $context)) {
            $res = $bookingoption->delete_responses_activitycompletion();

            $data = new stdClass();
            $data->all = count($res);
            $data->del = 0;
            foreach ($res as $value) {
                if ($value == true) {
                    $data->del++;
                }
            }
            redirect($url, get_string('delnotificationactivitycompletion', 'booking', $data), 5);
        }
        $allselectedusers = [];

        if (isset($_POST['user'])) {
            foreach ($_POST['user'] as $value) {
                $allselectedusers[] = array_keys($value)[0];
            }

            // Check when separated groups are activated, all users are same group of current user.
            if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
                    !has_capability('moodle/site:accessallgroups',
                            \context_course::instance($course->id))) {
                list($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql(
                        $course->id);
                $groupusers = $DB->get_fieldset_sql($groupsql, $groupparams);
                $allselectedusers = array_intersect($groupusers, $allselectedusers);
            }

            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingoption->option->howmanyusers), 5);
            }
        } else {
            redirect($url,
                    get_string('selectatleastoneuser', 'booking',
                            $bookingoption->option->howmanyusers), 5);
        }

        if (isset($_POST['deleteusers']) && has_capability('mod/booking:deleteresponses', $context)) {
            $res = $bookingoption->delete_responses($allselectedusers);

            $data = new stdClass();

            $data->all = count($res);
            $data->del = 0;
            foreach ($res as $value) {
                if ($value == true) {
                    $data->del++;
                }
            }

            redirect($url, get_string('delnotification', 'booking', $data), 5);
        } else if (isset($_POST['subscribetocourse'])) { // Subscription submitted.
            if ($bookingoption->option->courseid != 0) {
                foreach ($allselectedusers as $selecteduserid) {
                    $bookingoption->enrol_user($selecteduserid, true);
                }
                redirect($url, get_string('userssuccessfullenrolled', 'booking'), 5);
            } else {
                redirect($url, get_string('nocourse', 'booking'), 5);
            }
            die();
        } else if (isset($_POST['sendpollurl']) &&
                 has_capability('mod/booking:communicate', $context)) {

            // Send the poll URL to all selected users.
            $bookingoption->sendmessage_pollurl($allselectedusers);
            redirect($url, get_string('allmailssend', 'booking'), 5);

        } else if (isset($_POST['sendcustommsg']) &&
                 has_capability('mod/booking:communicate', $context)) {

            $sendmessageurl = new moodle_url('/mod/booking/sendmessage.php',
                    ['id' => $id, 'optionid' => $optionid, 'uids' => json_encode($allselectedusers)]);
            redirect($sendmessageurl);
        } else if (isset($_POST['activitycompletion']) && (booking_check_if_teacher(
                $bookingoption->option) || has_capability('mod/booking:readresponses', $context))) {

            booking_activitycompletion($allselectedusers, $bookingoption->booking->settings, $cm->id, $optionid);
            redirect($url,
                    (empty($bookingoption->option->notificationtext) ? get_string(
                            'activitycompletionsuccess', 'booking') : $bookingoption->option->notificationtext),
                    5);
        } else if (isset($_POST['postratingsubmit']) && (booking_check_if_teacher(
                $bookingoption->option) || has_capability('moodle/rating:rate', $context))) {

            $allusers = $bookingoption->get_all_users();
            $ratings = [];
            foreach ($allusers as $userid => $user) {
                if (in_array($user->userid, $allselectedusers) && $user->userid != $USER->id) {
                    $rating = new stdClass();
                    $baid = $user->baid;
                    $bookinganswerid = "rating" . $baid;
                    $rating->rateduserid = $user->userid;
                    $rating->itemid = $baid;
                    $rating->rating = $_POST[$bookinganswerid];
                    $ratings[$baid] = $rating;
                    // Params valid for all ratings.
                    $params = new stdClass();
                    $params->contextid = $context->id;
                    $params->scaleid = $scaleid;
                    $params->returnurl = $returnurl;
                }
            }
            if (!empty($ratings)) {
                booking_rate($ratings, $params);
                redirect($url,
                        (empty($bookingoption->option->notificationtext) ? get_string('ratingsuccessful',
                                'booking') : $bookingoption->option->notificationtext), 5);
            }
        } else if (isset($_POST['sendreminderemail']) &&
                 has_capability('mod/booking:communicate', $context)) {

            // Send a custom reminder email.
            $bookingoption->sendmessage_notification(MOD_BOOKING_MSGPARAM_REPORTREMINDER, $allselectedusers);

            redirect($url, get_string('sendreminderemailsuccess', 'booking'), 5);
        } else if (isset($_POST['booktootherbooking']) && (booking_check_if_teacher(
                $bookingoption->option) || has_capability('mod/booking:readresponses', $context))) {

            if (!isset($_POST['selectoptionid']) || empty($_POST['selectoptionid'])) {
                redirect($url, get_string('selectoptionid', 'booking'), 5);
            }

            if (count($allselectedusers) > $bookingoption->calculate_how_many_can_book_to_other(
                    $_POST['selectoptionid'])) {
                redirect($url,
                        get_string('toomuchusersbooked', 'booking',
                                $bookingoption->calculate_how_many_can_book_to_other(
                                        $_POST['selectoptionid'])), 5);
            }

            $connectedbooking = $DB->get_record("booking",
                    ['conectedbooking' => $bookingoption->booking->settings->id], 'id', IGNORE_MULTIPLE);

            $tmpcmid = $DB->get_record_sql(
                    "SELECT cm.id FROM {course_modules} cm
                    JOIN {modules} md ON md.id = cm.module
                    JOIN {booking} m ON m.id = cm.instance
                    WHERE md.name = 'booking' AND cm.instance = ?", [$connectedbooking->id]);
            $tmpbooking = singleton_service::get_instance_of_booking_option($tmpcmid->id, $_POST['selectoptionid']);

            foreach ($allselectedusers as $value) {
                $user = new stdClass();
                $user->id = $value;
                if (!$tmpbooking->user_submit_response($user, $optionid, 0, false, MOD_BOOKING_VERIFIED)) {
                    redirect($url, get_string('bookingfulldidntregister', 'mod_booking'), 5);
                }
            }

            redirect($url, get_string('userssuccessfullybooked', 'booking'), 5);
        } else if (isset($_POST['transfersubmit'])) {
            if ($_POST['transferoption'] == "") {
                redirect($url, get_string('selectanoption', 'mod_booking'), 5);
            }
            $result = $bookingoption->transfer_users_to_otheroption($_POST['transferoption'],
                    $allselectedusers);
            if ($result->success) {
                redirect($url, get_string('transfersuccess', 'mod_booking', $result), 5);
            } else {
                $output = '<br>';
                if (!empty($result->no)) {
                    foreach ($result->no as $user) {
                        $output .= $user->firstname . " $user->lastname <br>";
                    }
                }
                redirect($url, get_string('transferproblem', 'mod_booking', $output), 5, 'error');
            }
        } else if (isset($_POST['changepresencestatus']) && (booking_check_if_teacher(
                $bookingoption->option) || has_capability('mod/booking:readresponses', $context))) {
            // Change presence status.
            if (empty($allselectedusers)) {
                redirect($url,
                        get_string('selectatleastoneuser', 'booking',
                                $bookingoption->option->howmanyusers), 5);
            }
            if (!isset($_POST['selectpresencestatus']) || empty($_POST['selectpresencestatus'])) {
                redirect($url, get_string('selectpresencestatus', 'booking'), 5);
            }
            $bookingoption->changepresencestatus($allselectedusers, $_POST['selectpresencestatus']);
            redirect($url, get_string('userssucesfullygetnewpresencestatus', 'booking'), 5);
        }
    }

    $columns = [];
    $headers = [];

    $columns[] = 'selected';
    $headers[] = '<input type="checkbox" id="usercheckboxall" name="selectall" value="0" />';

    $responsesfields = explode(',', $bookingoption->booking->settings->responsesfields);
    list($addquoted, $addquotedparams) = $DB->get_in_or_equal($responsesfields);

    $userprofilefields = $DB->get_records_select('user_info_field',
            'id > 0 AND shortname ' . $addquoted, $addquotedparams, 'id', 'id, shortname, name');

    foreach ($responsesfields as $value) {
        switch ($value) {
            case 'completed':
                $columns[] = 'completed';
                $headers[] = get_string('completed', 'mod_booking');
                break;
            case 'status':
                if ($bookingoption->booking->settings->enablepresence) {
                    $columns[] = 'status';
                    $headers[] = get_string('presence', 'mod_booking');
                }
                break;
            case 'rating':
                if ($bookingoption->booking->settings->assessed != RATING_AGGREGATE_NONE) {
                    $columns[] = 'rating';
                    $headers[] = get_string('rating', 'core_rating');
                }
                break;
            case 'numrec':
                if ($bookingoption->booking->settings->numgenerator) {
                    $columns[] = 'numrec';
                    $headers[] = get_string('numrec', 'mod_booking');
                }
                break;
            case 'fullname':
                $columns[] = 'fullname';
                $headers[] = get_string('fullname', 'mod_booking');
                break;
            case 'timecreated':
                $columns[] = 'timecreated';
                $headers[] = get_string('timecreated', 'mod_booking');
                break;
            case 'institution':
                $columns[] = 'institution';
                if (empty($bookingoption->booking->settings->lblinstitution)) {
                    $headers[] = get_string('institution', 'booking');
                } else {
                    $headers[] = $bookingoption->booking->settings->lblinstitution;
                }
                break;
            case 'notes':
                $columns[] = 'notes';
                $headers[] = get_string('notes', 'mod_booking');
                break;
            case 'city':
                $columns[] = 'city';
                $headers[] = get_string('city');
                break;
            case 'department':
                $columns[] = 'department';
                $headers[] = get_string('department');
                break;
            case 'waitinglist':
                if ($bookingoption->option->limitanswers == 1 && $bookingoption->option->maxoverbooking > 0) {
                    $columns[] = 'waitinglist';
                    $headers[] = get_string('searchwaitinglist', 'mod_booking');
                }
                break;
        }
    }
    $customfields = '';
    if ($userprofilefields) {
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

    $strbooking = get_string("modulename", "booking");
    $strbookings = get_string("modulenameplural", "booking");
    $strresponses = get_string("responses", "booking");
    if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
            !has_capability('moodle/site:accessallgroups', \context_course::instance($course->id))) {
        list($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql(
                $course->id);
        $addsqlwhere .= " AND u.id IN ($groupsql)";
        $sqlvalues = array_merge($sqlvalues, $groupparams);
    }

    if ($CFG->version >= 2021051700) {
        // This only works in Moodle 3.11 and later.
        $mainuserfields = \core_user\fields::for_name()->get_sql('u')->selects;
        $mainuserfields = trim($mainuserfields, ', ');
    } else {
        // This is only here to support Moodle versions earlier than 3.11.
        $mainuserfields = get_all_user_name_fields(true, 'u');
    }

    // ALL USERS - START To make compatible MySQL and PostgreSQL - http://hyperpolyglot.org/db.
    $fields = 'ba.id, ' . $mainuserfields . ',
            u.username,
            u.institution,
            u.city,
            u.department,
            ba.completed,
            ba.status,
            ba.timecreated,
            ba.userid,
            ba.waitinglist,
            ba.notes,
            \'\' otheroptions,
            ba.numrec' . $customfields;
    $from = ' {booking_answers} ba
            JOIN {user} u ON u.id = ba.userid
            JOIN {booking_options} bo ON bo.id = ba.optionid
            LEFT JOIN {booking_options} otherbookingoption ON otherbookingoption.id = ba.frombookingid ';
    $where = ' ba.optionid = :optionid
             AND ba.waitinglist < 2 ' . $addsqlwhere;

    $tableallbookings->set_sql($fields, $from, $where, $sqlvalues);

    $tableallbookings->define_columns($columns);
    $tableallbookings->define_headers($headers);

    // ALL USERS - STOP.

    echo $OUTPUT->header();

    echo $OUTPUT->heading(
            html_writer::link(new moodle_url('/mod/booking/view.php', ['id' => $cm->id]),
                    format_string($bookingoption->booking->settings->name)) . ' > ' .
                        format_string($titlestring), 4);

    $teachers = [];

    foreach ($bookingoption->teachers as $value) {
        $teachers[] = html_writer::link(
                new moodle_url('/mod/booking/teacher.php', ['teacherid' => $value->userid]),
                "{$value->firstname} {$value->lastname}");
    }

    $isteacherofthisoption = booking_check_if_teacher($bookingoption->booking->settings);

    $linkst = '';
    if (has_capability('mod/booking:communicate', $context) ||
             has_capability('mod/booking:updatebooking', $context)) {
        $linkst = [];

        $haspollurl = (!empty($bookingoption->booking->settings->pollurlteachers) ||
            !empty($bookingoption->option->pollurlteachers));

        if (has_capability('mod/booking:communicate', $context) && $haspollurl) {
            $linkst[] = html_writer::link(
                    new moodle_url('/mod/booking/report.php',
                            ['id' => $cm->id, 'optionid' => $optionid, 'action' => 'sendpollurlteachers']),
                    (empty($bookingoption->booking->settings->lblsputtname) ? get_string(
                            'sendpollurltoteachers', 'booking') : $bookingoption->booking->settings->lblsputtname),
                    []);
        }

        $linkst = empty($linkst) ? "" : "(" . implode(", ", $linkst) . ")";
    }

    // Action buttons on top.
    $actionbuttonstop = '';
    if (has_capability('mod/booking:bookforothers', $context) &&
                (has_capability('mod/booking:subscribeusers', $context) ||
                $isteacherofthisoption)) {
        $url = new moodle_url('/mod/booking/subscribeusers.php',
            ['id' => $cm->id, 'optionid' => $optionid]);
        $actionbuttonstop .= "<span>" .
            html_writer::link($url, '<i class="fa fa-users fa-fw" aria-hidden="true"></i>&nbsp;' .
                get_string('bookotherusers', 'booking'), ['class' => 'btn btn-light mr-2']) .
        "</span>";
    }

    if (get_config('booking', 'teachersallowmailtobookedusers') && (
        has_capability('mod/booking:updatebooking', $context) ||
        (has_capability('mod/booking:addeditownoption', $context) && $isteacherofthisoption) ||
        (has_capability('mod/booking:limitededitownoption', $context) && $isteacherofthisoption)
    )) {
        $mailtolink = booking_option::get_mailto_link_for_partipants($optionid);
        if (!empty($mailtolink)) {
            $actionbuttonstop .= "<span>" .
                html_writer::link($mailtolink, '<i class="fa fa-envelope fa-fw" aria-hidden="true"></i>&nbsp;' .
                    get_string('sendmailtoallbookedusers', 'booking'), ['class' => 'btn btn-light mr-2']) .
            "</span>";
        }
    }

    echo "<p>" .
             ($bookingoption->option->coursestarttime == 0 ? get_string('nodateset', 'booking') : userdate(
                    $bookingoption->option->coursestarttime, get_string('strftimedatetime', 'langconfig')) . " - " .
             userdate($bookingoption->option->courseendtime, get_string('strftimedatetime', 'langconfig'))) . " | " .
             (empty($bookingoption->booking->settings->lblteachname) ? get_string('teachers', 'booking') . ': ' :
                $bookingoption->booking->settings->lblteachname . ': ') .
                    implode(', ', $teachers) . " {$linkst}</p>";

    echo "<div class='report-actionbuttons-top'>$actionbuttonstop</div>";

    $links = [];

    $links[] = '<a href="#" style="float:right;" id="showHideSearch">' . get_string('search') .
             '</a>';

    echo implode("<br>", $links);

    if ($bookingoption->option->courseid != 0) {
        echo '<br>' . html_writer::start_span('') . get_string('associatedcourse', 'booking') . ': ' . html_writer::link(
                new moodle_url($bookingoption->option->courseurl, []), $bookingoption->option->urltitle,
                []) . html_writer::end_span();
    }

    // We call the template render to display how many users are currently reserved.
    $data = new booked_users($optionid, false, false, true);
    $renderer = $PAGE->get_renderer('mod_booking');
    echo $renderer->render_booked_users($data);

    $hidden = "";

    foreach ($urlparams as $key => $value) {
        $arr = ['searchdate', 'searchcompleted'];
        if (!in_array($key, $arr)) {
            $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
        }
    }

    $row = new html_table_row(
        [
            get_string('searchdate', "booking"),
            $hidden . html_writer::checkbox('searchdate', '1', $checked, '',
            ['id' => 'searchdate']) .
                 html_writer::select_time('days', 'searchdateday', $timestamp, 5) . ' ' .
                 html_writer::select_time('months', 'searchdatemonth', $timestamp, 5) . ' ' .
                 html_writer::select_time('years', 'searchdateyear', $timestamp, 5),
            "",
            "",
        ]
    );
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
        [
            get_string('completed', 'mod_booking'),
            html_writer::select(
                ['1' => get_string('no', "booking"), '2' => get_string('yes', "booking")],
                'searchcompleted',
                $urlparams['searchcompleted']
            ),
            "",
            "",
        ],
    );
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
        [
            get_string('searchwaitinglist', "booking"),
            html_writer::select(
                ['1' => get_string('no', "booking"), '2' => get_string('yes', "booking")],
                'searchwaitinglist',
                $urlparams['searchwaitinglist']
            ),
            "",
            "",
        ]
    );
    $tabledata[] = $row;
    $rowclasses[] = "";

    $row = new html_table_row(
        [
            "",
            '<div class="singlebutton"><input class="btn btn-primary" type="submit" id="searchButton" value="' .
                get_string('search') . '"></div><div class="singlebutton"><input class="btn btn-secondary" id="buttonclear"
                type="button" value="' . get_string('reset', 'booking') . '"></div>',
            "",
            "",
        ]
    );
    $tabledata[] = $row;
    $rowclasses[] = "";

    $table = new html_table();
    $table->head = ['', '', '', ''];
    $table->data = $tabledata;
    $table->id = "tableSearch";
    if (!$searching) {
        $table->attributes = ['style' => "display: none;"];
    }
    echo html_writer::tag('form', html_writer::table($table));

    echo '<h5>' . get_string('bookedusers', 'booking') . '</h5>';

    $tableallbookings->setup();
    $tableallbookings->query_db($paging, true);
    if ($bookingoption->booking->settings->assessed != RATING_AGGREGATE_NONE &&
             !empty($tableallbookings->rawdata)) {
        // Get all bookings from all booking options: only that guarantees correct use of rating.

        $ratingoptions = new stdClass();
        $ratingoptions->context = $bookingoption->booking->get_context();
        $ratingoptions->component = 'mod_booking';
        $ratingoptions->ratingarea = 'bookingoption';
        $ratingoptions->items = $tableallbookings->rawdata;
        $ratingoptions->aggregate = $bookingoption->booking->settings->assessed; // The aggregation method.
        $ratingoptions->scaleid = $bookingoption->booking->settings->scale;
        $ratingoptions->userid = $USER->id;
        $ratingoptions->returnurl = "$CFG->wwwroot/mod/booking/report.php?id=$cm->id&optionid=$optionid";
        $ratingoptions->assesstimestart = $bookingoption->booking->settings->assesstimestart;
        $ratingoptions->assesstimefinish = $bookingoption->booking->settings->assesstimefinish;

        $rm = new rating_manager();
        $tableallbookings->rawdata = $rm->get_ratings($ratingoptions);

        // Hidden input fields for the rating.
        $ratinginputs = [];
        $ratinginputs['ratingarea'] = $ratingoptions->ratingarea;
        $ratinginputs['scaleid'] = $ratingoptions->scaleid;
        $ratinginputs['returnurl'] = $ratingoptions->returnurl;
        $ratinginputs['aggregation'] = $ratingoptions->aggregate;
        $ratinginputs['sesskey'] = sesskey();
        $tableallbookings->set_ratingoptions($ratinginputs);

        // Set menu for modifying all ratings at once. Get an example rating and modify it.
        $newarray = array_values($tableallbookings->rawdata);
        $firstentry = array_shift($newarray);

        $strrate = get_string("rate", "rating");
        $scalearray = [RATING_UNSET_RATING => $strrate . '...'] + $firstentry->rating->settings->scale->scaleitems;
        $scaleattrs = ['class' => 'postratingmenu ratinginput', 'id' => 'menuratingall'];
        $menuhtml = html_writer::label(get_string('rating', 'core_rating'), 'menuratingall', false,
                ['class' => 'accesshide']);
        $menuhtml .= html_writer::select($scalearray, 'rating', $scalearray[RATING_UNSET_RATING],
                false, $scaleattrs);
        foreach ($columns as $key => $value) {
            if ($value == "rating") {
                $tableallbookings->headers[$key] .= $menuhtml;
            }
        }

    }
    $otheroptions = $bookingoption->get_other_options();
    if (!empty($otheroptions)) {
        if (!empty($tableallbookings->rawdata)) {
            foreach ($tableallbookings->rawdata as $answer) {
                foreach ($otheroptions as $option) {
                    if ($answer->userid == $option->userid) {
                        $otheroptiontitle = $option->text;
                        if (!empty($option->titleprefix)) {
                            $otheroptiontitle = $option->titleprefix . " - " . $otheroptiontitle;
                        }
                        $answer->otheroptions .= format_string($otheroptiontitle) . ", ";
                    }
                }
                $answer->otheroptions = trim($answer->otheroptions, ', ');
            }
        }
    }

    $tableallbookings->build_table();
    $tableallbookings->finish_output();

    $onlyoneurl = new moodle_url('/mod/booking/view.php',
            [
                'id' => booking_option::get_cmid_from_optionid($optionid),
                'optionid' => $optionid,
                'whichview' => 'showonlyone',
            ]
        );

    // PHP 8.1 compatibility with extra safety if poolurl has changed outside option form.
    $pollurl = '';
    if (!empty($bookingoption->option->pollurl)) {
        $pollurl = trim($bookingoption->option->pollurl);
    }
    if (!empty($pollurl)) {
        echo html_writer::link($pollurl, get_string('copypollurl', 'booking'),
                ['onclick' => 'copyToClipboard("' . $pollurl . '"); return false;']) .
                 ($bookingoption->option->pollsend ? ' &#x2713;' : '') . ' | ';
    }

    echo html_writer::link($onlyoneurl, get_string('onlythisbookingoption', 'booking'), []);
    if (!empty($bookingoption->option->shorturl)) {
        echo " ({$bookingoption->option->shorturl})";
    }
    echo ' | ' . html_writer::link($onlyoneurl, get_string('copyonlythisbookingurl', 'booking'),
            ['onclick' => 'copyToClipboard("' . htmlspecialchars_decode($onlyoneurl, ENT_QUOTES) . '"); return false;']);

    echo ' | ' . html_writer::link($onlyoneurl, get_string('sign_in_sheet_download', 'booking'),
            ['id' => 'sign_in_sheet_download']);
    if (!empty($bookingoption->booking->settings->customtemplateid)) {
        echo ' | ' . html_writer::link(new moodle_url('/mod/booking/report.php',
                        ['id' => $cm->id, 'optionid' => $optionid, 'action' => 'postcustomreport']),
                        get_string('customdownloadreport', 'booking'), ['target' => '_blank']);
    }

    echo "<script>
  function copyToClipboard(text) {
    window.prompt('" . get_string('copytoclipboard', 'booking') . "', text);
  }
</script>";

    $signinform = new mod_booking\output\signin_downloadform($bookingoption, $baseurl);
    $renderer = $PAGE->get_renderer('mod_booking');
    echo $renderer->render_signin_pdfdownloadform($signinform);

    echo $OUTPUT->footer();
} else {
    $columns = [];
    $headers = [];

    $customfields = '';

    list($columns, $headers, $userprofilefields) = $bookingoption->booking->get_manage_responses_fields();

    if ($userprofilefields) {
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
    if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
            !has_capability('moodle/site:accessallgroups', \context_course::instance($course->id))) {
        list($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql(
                $course->id);
        $addsqlwhere .= " AND u.id IN ($groupsql)";
        $sqlvalues = array_merge($sqlvalues, $groupparams);
    }

    $fields = "ba.id uniqueid, u.id AS userid,
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
                    u.city,
                    u.department,
                    ba.completed AS completed,
                    ba.numrec,
                    ba.waitinglist AS waitinglist,
                    ba.status,
                    ba.notes,
                    u.idnumber as idnumber
                    {$customfields}";
    $from = '{booking_answers} ba
            JOIN {user}  u ON u.id = ba.userid
            JOIN {booking_options} bo ON bo.id = ba.optionid';

    if (!get_config('booking', 'alloptionsinreport')) {
        $individualbookingoption = " ba.optionid = :optionid AND ";
    }
    $where = $individualbookingoption ?? '' . ' ba.waitinglist < 2 ' . $addsqlwhere;
    $tableallbookings->define_columns($columns);
    $tableallbookings->define_headers($headers);
    $tableallbookings->set_sql($fields, $from, $where, $sqlvalues);
    $tableallbookings->setup();
    $tableallbookings->query_db(10);
    if (!empty($tableallbookings->rawdata)) {
        foreach ($tableallbookings->rawdata as $option) {
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
}
