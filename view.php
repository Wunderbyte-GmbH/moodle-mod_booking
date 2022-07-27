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

use mod_booking\all_options;
use mod_booking\booking;
use mod_booking\output\business_card;
use mod_booking\output\instance_description;
use mod_booking\booking_elective;
use mod_booking\booking_option;

require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");
require_once($CFG->libdir . '/completionlib.php');
require_once("{$CFG->libdir}/tablelib.php");
require_once($CFG->dirroot . '/comment/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$action = optional_param('action', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$whichview = optional_param('whichview', '', PARAM_ALPHA);
$optionid = optional_param('optionid', '', PARAM_INT);
$confirm = optional_param('confirm', '', PARAM_INT);
$answer = optional_param('answer', '', PARAM_ALPHANUM);
$sorto = optional_param('sort', '1', PARAM_INT);
$searchtext = optional_param('searchtext', '', PARAM_TEXT);
$searchlocation = optional_param('searchlocation', '', PARAM_TEXT);
$searchinstitution = optional_param('searchinstitution', '', PARAM_TEXT);
$searchname = optional_param('searchname', '', PARAM_TEXT);
$searchsurname = optional_param('searchsurname', '', PARAM_TEXT);
$page = optional_param('page', '0', PARAM_INT);
$done = optional_param('done', '0', PARAM_INT);

// This Param is for electives, to know in which order booking was done.
$listorder = optional_param('list', '', PARAM_TEXT);

$perpage = 10;
$conditions = array();
$conditionsparams = array();
$urlparams = array();
$urlparamssort = array();
$urlparams['id'] = $id;

list($course, $cm) = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);
$context = context_module::instance($cm->id);

$booking = new booking($cm->id);

if (!empty($action)) {
    $urlparams['action'] = $action;
}

if (!empty($whichview)) {
    $urlparams['whichview'] = $whichview;
} else {
    $urlparams['whichview'] = $booking->settings->whichview;
    $whichview = $booking->settings->whichview;
}

// Store selected electives in user preferences.
$iselective = $booking->is_elective();

if ($iselective && $answer) {

    $arrayofoptions = json_decode($listorder);
    // $arrayofoptions = [];
    if (in_array($answer, $arrayofoptions)) {
        $key = array_search($answer, $arrayofoptions);
        array_splice($arrayofoptions, $key, 1);

    } else {
        $arrayofoptions[] = (int)$answer;
    }
    $listorder = json_encode($arrayofoptions);
    $_GET['list'] = $listorder;
}

if ($optionid > 0) {
    $urlparams['optionid'] = $optionid;
}

$urlparams['searchtext'] = "";
if (strlen($searchtext) > 0) {
    $urlparams['searchtext'] = $searchtext;
    $conditions[] = "bo.text LIKE :searchtext";
    $conditionsparams['searchtext'] = "%{$searchtext}%";
}

$urlparams['searchlocation'] = "";
if (strlen($searchlocation) > 0) {
    $urlparams['searchlocation'] = $searchlocation;
    $conditions[] = "bo.location LIKE :searchlocation";
    $conditionsparams['searchlocation'] = "%{$searchlocation}%";
}

$urlparams['searchinstitution'] = "";
if (strlen($searchinstitution) > 0) {
    $urlparams['searchinstitution'] = $searchinstitution;
    $conditions[] = "bo.institution LIKE :searchinstitution";
    $conditionsparams['searchinstitution'] = "%{$searchinstitution}%";
}

$urlparams['searchname'] = "";
$urlparams['searchsurname'] = "";

$searchnyname = array();

if (strlen($searchname) > 0) {
    $urlparams['searchname'] = $searchname;
    $conditionsparams['searchname'] = "%{$searchname}%";
    $searchnyname[] = 'u.firstname LIKE :searchname';
}

if (strlen($searchsurname) > 0) {
    $urlparams['searchsurname'] = $searchsurname;
    $conditionsparams['searchsurname'] = "%{$searchsurname}%";
    $searchnyname[] = 'u.lastname LIKE :searchsurname';
}

if (!empty($searchnyname)) {
    $conditions[] = "bo.id IN (SELECT DISTINCT optionid
            FROM (SELECT userid, optionid
            FROM {booking_teachers}
            WHERE bookingid = :snbookingid1 UNION SELECT userid, optionid
            FROM {booking_answers} WHERE bookingid = :snbookingid2) AS un
            LEFT JOIN {user} u ON u.id = un.userid
            WHERE " . implode(' AND ', $searchnyname) . ")";
    $conditionsparams['snbookingid1'] = $booking->id;
    $conditionsparams['snbookingid2'] = $booking->id;
}

$urlparamssort = $urlparams;

if ($sorto == 1) {
    $urlparams['sort'] = 1;
    $urlparamssort['sort'] = 0;
} else if ($sorto == 0) {
    $urlparams['sort'] = 0;
    $urlparamssort['sort'] = 1;
}

$url = new moodle_url('/mod/booking/view.php', $urlparams);
$urlcancel = new moodle_url('/mod/booking/view.php', array('id' => $id));
$sorturl = new moodle_url('/mod/booking/view.php', $urlparamssort);
$sorturl->set_anchor('goenrol');

$booking->checkautocreate();

$PAGE->set_url($url);
$PAGE->requires->js_call_amd('mod_booking/view_actions', 'setup', array($id));

$booking->apply_tags();
$booking->get_url_params();

$strbooking = get_string('modulename', 'booking');
$strbookings = get_string('modulenameplural', 'booking');

// Check if data has been submitted to be processed.
if ($action == 'delbooking' and confirm_sesskey() && $confirm == 1 and
         has_capability('mod/booking:choose', $context) and
         ($booking->settings->allowupdate or has_capability('mod/booking:deleteresponses', $context))) {
    $bookingdata = new \mod_booking\booking_option($cm->id, $optionid);
    $bookingdata->apply_tags();

    if ($bookingdata->user_delete_response($USER->id)) {
        echo $OUTPUT->header();
        $contents = html_writer::tag('p', get_string('bookingdeleted', 'booking'));
        $options = array('id' => $cm->id);
        $contents .= $OUTPUT->single_button(new moodle_url('/mod/booking/view.php', $options),
                get_string('continue'), 'get');
        echo $OUTPUT->box($contents, 'box generalbox', 'notice');
        echo $OUTPUT->footer();
        die();
    } else {
        echo $OUTPUT->header();
        $contents = get_string('cannotremovesubscriber', 'booking');
        $options = array('id' => $cm->id);
        $contents .= $OUTPUT->single_button(new moodle_url('/mod/booking/view.php', $options),
                get_string('continue'), 'get');
        echo $OUTPUT->box($contents, 'box generalbox', 'notice');
        echo $OUTPUT->footer();
        die();
    }
} else if ($action == 'delbooking' and confirm_sesskey() and
         has_capability('mod/booking:choose', $context) and
         ($booking->settings->allowupdate or has_capability('mod/booking:deleteresponses', $context))) {
    echo $OUTPUT->header();

    $bookingdata = new \mod_booking\booking_option($cm->id, $optionid);
    $bookingdata->apply_tags();

    $options = array('id' => $cm->id, 'action' => 'delbooking', 'confirm' => 1,
        'optionid' => $optionid, 'sesskey' => $USER->sesskey);

    $deletemessage = format_string($bookingdata->option->text);

    if ($bookingdata->option->coursestarttime != 0) {
        $deletemessage .= "<br />" .
                 userdate($bookingdata->option->coursestarttime, get_string('strftimedatetime', 'langconfig')) .
                 " - " .
                 userdate($bookingdata->option->courseendtime, get_string('strftimedatetime', 'langconfig'));
    }

    echo $OUTPUT->confirm(get_string('deletebooking', 'booking', $deletemessage),
            new moodle_url('/mod/booking/view.php', $options), $urlcancel);
    echo $OUTPUT->footer();
    die();
}

// Before processing data user has to agree to booking policy and confirm booking.
if ($form = data_submitted() && has_capability('mod/booking:choose', $context) && $download == '' &&
         confirm_sesskey() && $confirm != 1 && $answer) {
    booking_confirm_booking($answer, $USER, $cm, $url);
    die();
}

$PAGE->set_title(format_string($booking->settings->name));
$PAGE->set_heading(format_string($booking->settings->name));

// Submit any new data if there is any.
if ($download == '' && $form = data_submitted() && has_capability('mod/booking:choose', $context)) {
    echo $OUTPUT->header();
    $timenow = time();

    $url = new moodle_url("/mod/booking/view.php", array('id' => $cm->id));
    $url->set_anchor("option" . $answer);
    if (!empty($answer)) {
        $bookingdata = new \mod_booking\booking_option($cm->id, $answer, array(), 0, 0, false);
        $bookingdata->apply_tags();
        if ($bookingdata->user_submit_response($USER)) {
            $contents = html_writer::tag('p', get_string('bookingsaved', 'booking'));
            if ($booking->settings->sendmail) {
                $contents .= html_writer::tag('p', get_string('mailconfirmationsent', 'booking') . ".");
            }
            $contents .= $OUTPUT->single_button($url,
                    get_string('continue'), 'get');
            echo $OUTPUT->box($contents, 'box generalbox', 'notice');
            echo $OUTPUT->footer();
            die();
        } else if (is_numeric($answer)) {
            $contents = get_string('bookingmeanwhilefull', 'booking') . " " . format_string($bookingdata->option->text);
            $contents .= $OUTPUT->single_button($url,
                    get_string('continue'), 'get');
            echo $OUTPUT->box($contents, 'box generalbox', 'notice');
            echo $OUTPUT->footer();
            die();
        }
    } else {
        $contents = get_string('nobookingselected', 'booking');
        $contents .= $OUTPUT->single_button($url, get_string('continue'));
        echo $OUTPUT->box($contents, 'box generalbox', 'notice');
        echo $OUTPUT->footer();
        die();
    }
} else if ($iselective  && !$done && $download == '' && $action == 'multibooking' && has_capability('mod/booking:choose', $context)) {
    // Button to "book all selected electives" has been pressed.

    if ($listorder) {
        $electivesarray = json_decode($listorder);
        // booking_elective::set_electivesarray_to_user_prefs($electivesarray);
    }

    if ( count($electivesarray) == 1 && $electivesarray[0] == '') {
        array_pop($electivesarray);
    }

    $success = true;
    if (!empty($electivesarray)) { //  && !
        foreach ($electivesarray as $answer) {
            if (!empty($answer)) {
                // Now submit a response for every selected elective.
                $bookingdata = new booking_option($cm->id, (int)$answer, array(), 0, 0, false);
                $bookingdata->apply_tags();

                if (!$bookingdata->user_submit_response($USER)) {
                    $success = false;
                }
            }
        }

        $urlparameters['id'] = $cm->id;
        $urlparameters['done'] = 1;
        $url = new moodle_url("view.php", $urlparameters);

        if ($success) {
            redirect($url, get_string('electivesbookedsuccess', 'booking'), null, core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect($url, get_string('errormultibooking', 'booking'), null, core\output\notification::NOTIFY_ERROR);
        }
        die();
    } else {
        $urlparameters['id'] = $cm->id;
        $urlparameters['done'] = 1;
        $url = new moodle_url("view.php", $urlparameters);
        redirect($url, get_string('nobookingselected', 'booking'), null, core\output\notification::NOTIFY_WARNING);
        die();
    }
}

$event = \mod_booking\event\course_module_viewed::create(
        array('objectid' => $PAGE->cm->instance, 'context' => $PAGE->context));
$event->add_record_snapshot('course', $PAGE->course);
$event->trigger();

// Display the booking and possibly results.

$mybookings = $DB->get_record_sql(
        "SELECT COUNT(*) AS mybookings FROM {booking_answers} WHERE userid = :userid AND bookingid = :bookingid",
        array('userid' => $USER->id, 'bookingid' => $booking->id));
$myoptions = $DB->get_record_sql(
        "SELECT COUNT(*) AS myoptions FROM {booking_teachers} WHERE userid = :userid AND bookingid = :bookingid",
        array('userid' => $USER->id, 'bookingid' => $booking->id));

// Initialise for later if user has already made a selection, show the selected answer.
$current = false;
$bookingopen = true;
$timenow = time();

if (!$current and $bookingopen and has_capability('mod/booking:choose', $context)) {

    switch ($whichview) {
        case 'mybooking':
            $conditions[] = "bo.id IN (SELECT optionid FROM {booking_answers}
                             WHERE userid = :myuserid AND bookingid = :mybookingid)";
            $conditionsparams['myuserid'] = $USER->id;
            $conditionsparams['mybookingid'] = $booking->id;
            break;

        case 'myoptions':
            $conditions[] = "bo.id IN (SELECT optionid FROM {booking_teachers}
                             WHERE userid = :myuserid AND bookingid = :mybookingid)";
            $conditionsparams['myuserid'] = $USER->id;
            $conditionsparams['mybookingid'] = $booking->id;
            break;

        case 'showall':
            $conditions[] = "bo.bookingid = :bookingid1";
            $conditionsparams['bookingid1'] = $booking->id;
            break;

        case 'showonlyone':
            $conditions[] = "bo.id = :optionid";
            $conditionsparams['optionid'] = $optionid;
            break;

        case 'showactive':
            $conditions[] = "(bo.courseendtime > :time OR bo.courseendtime = 0)";
            $conditionsparams['time'] = time();
            break;

        case 'myinstitution':
            $conditions[] = "bo.institution LIKE :institution";
            $conditionsparams['institution'] = "%{$USER->institution}%";
            break;

        default:
            break;
    }

    $tablealloptions = new all_options('mod_booking_all_options', $booking, $cm, $context);
    $tablealloptions->is_downloading($download, $booking->settings->name, $booking->settings->name);

    $tablealloptions->define_baseurl($sorturl);
    $tablealloptions->defaultdownloadformat = 'ods';
    if (has_capability('mod/booking:downloadresponses', $context)) {
        $tablealloptions->is_downloadable(true);
    } else {
        $tablealloptions->is_downloadable(false);
    }
    $tablealloptions->show_download_buttons_at(array(TABLE_P_BOTTOM));

    $columns = array();
    $headers = array();

    if (!$tablealloptions->is_downloading()) {
        comment::init();
        echo $OUTPUT->header();

        echo '<div class="clearer"></div>';

        if ($booking->settings->showhelpfullnavigationlinks) {
            echo $html = html_writer::tag('div',
                    '<a id="gotop" href="#goenrol">' . get_string('goenrol', 'booking') . '</a>',
                    array('style' => 'width:100%; font-weight: bold; text-align: right;'));
        }

        // If we have specified a teacher as organizer, we show a "busines_card" with photo, else legacy organizer description.
        if (!empty($booking->settings->organizatorname)
            && ($organizerid = (int)$booking->settings->organizatorname)) {
                $data = new business_card($booking, $organizerid);
                $output = $PAGE->get_renderer('mod_booking');
                echo $output->render_business_card($data);
        } else {
            $data = new instance_description($booking->settings);
            $output = $PAGE->get_renderer('mod_booking');
            echo $output->render_instance_description($data);
        }

        $out = array();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_booking', 'myfilemanager',
                $booking->settings->id);

        if (count($files) > 0) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string("attachedfiles", "booking") . ': ',
                    array('class' => 'bold'));

            foreach ($files as $file) {
                if ($file->get_filesize() > 0) {
                    $filename = $file->get_filename();
                    $url = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(),
                        $file->get_itemid(), $file->get_filepath(), $file->get_filename(), true);
                    $out[] = html_writer::link($url, $filename);
                }
            }
            echo html_writer::tag('span', implode(', ', $out));
            echo html_writer::end_tag('div');
        }
        if (!empty($CFG->usetags)) {
            $tags = core_tag_tag::get_item_tags('mod_booking', 'booking', $booking->settings->id);
            echo $OUTPUT->tag_list($tags, null, 'booking-tags');
        }

        if ($booking->settings->categoryid != '0' && $booking->settings->categoryid != '') {
            $categoryies = explode(',', $booking->settings->categoryid);

            if (count($categoryies) > 0) {
                $links = array();
                foreach ($categoryies as $category) {
                    $tmpcat = $DB->get_record('booking_category', array('id' => $category));
                    if ($tmpcat) {
                        $surl = new moodle_url('/mod/booking/category.php', array('id' => $id, 'category' => $tmpcat->id));
                        $links[] = html_writer::link($surl, $tmpcat->name, array());
                    }
                }

                echo html_writer::start_tag('div');
                echo html_writer::tag('label', get_string('category', 'booking') . ': ',
                        array('class' => 'bold'));
                echo html_writer::tag('span', implode(', ', $links));
                echo html_writer::end_tag('div');
            }
        }

        if (strlen($booking->settings->bookingpolicy) > 0) {
            $link = new moodle_url('/mod/booking/viewpolicy.php', array('id' => $cm->id));
            echo $OUTPUT->action_link($link, get_string("bookingpolicy", "booking"),
                    new popup_action('click', $link));
        }

        if ($booking->settings->showhelpfullnavigationlinks) {
            echo $html = html_writer::tag('div',
                    '<a id="goenrol" href="#gotop">' . get_string('gotop', 'booking') . '</a>',
                    array('style' => 'width:100%; font-weight: bold; text-align: right;'));
        }

        if ($booking->settings->timeclose != 0) {
            if ($booking->settings->timeopen > $timenow &&
                     !has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->box(
                        get_string("notopenyet", "booking",
                                userdate($booking->settings->timeopen, get_string('strftimedate', 'langconfig'))),
                        "center");
                echo $OUTPUT->footer();
                exit();
            } else if ($booking->settings->timeclose < $timenow &&
                     !has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->box(
                        get_string("expired", "booking", userdate($booking->settings->timeclose)),
                        "center");
                $bookingopen = false;
                echo $OUTPUT->footer();
                exit();
            }
        }

        if (!$iselective) {
            echo $OUTPUT->box($booking->show_maxperuser($USER), 'mdl-align');
        } else {
            $message = booking_elective::show_credits_message($booking, $optionid);
            echo $OUTPUT->box($message, 'mdl-align');
        }

        $output = $PAGE->get_renderer('mod_booking');
        $output->print_booking_tabs($urlparams, $whichview, $mybookings->mybookings,
                $myoptions->myoptions, $booking);

        $search = '<a class="btn btn-default" href="#" id="showHideSearch">' . get_string('search') . "</a>";

        if ($whichview != 'showonlyone') {
            echo $OUTPUT->box("{$search}", 'booking-search mdl-align');
        }

        $hidden = "";

        foreach ($urlparams as $key => $value) {
            if (!in_array($key, array('searchtext', 'searchlocation', 'searchinstitution'))) {
                $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
            }
        }
        $labelbooking = (empty($booking->settings->lblbooking) ? get_string('booking', 'booking') : $booking->settings->lblbooking);
        $labellocation = (empty($booking->settings->lbllocation) ? get_string('location', 'booking') :
            $booking->settings->lbllocation);
        $labelinstitution = (empty($booking->settings->lblinstitution) ? get_string('institution', 'booking') :
            $booking->settings->lblinstitution);
        $labelsearchname = (empty($booking->settings->lblname) ? get_string('searchname', 'booking') : $booking->settings->lblname);
        $labelsearchsurname = (empty($booking->settings->lblsurname) ? get_string('searchsurname',
                'booking') : $booking->settings->lblsurname);

        $row = new html_table_row(
                array($labelbooking,
                    $hidden . '<input value="' . $urlparams['searchtext'] .
                             '" type="text" id="searchtext" name="searchtext">', "", ""));
        $row->id = 'booking-searchtext-row';
        $tabledata[] = $row;
        $rowclasses[] = "";

        $row = new html_table_row(
                array($labellocation,
                    '<input value="' . $urlparams['searchlocation'] .
                             '" type="text" id="searchlocation" name="searchlocation">', "", ""));
        $row->id = 'booking-searchlocation-row';
        $tabledata[] = $row;
        $rowclasses[] = "";

        $row = new html_table_row(
                array($labelinstitution,
                    '<input value="' . $urlparams['searchinstitution'] .
                             '" type="text" id="searchinstitution" name="searchinstitution">', "",
                            ""));
        $row->id = 'booking-searchinstitution-row';
        $tabledata[] = $row;
        $rowclasses[] = "";

        $row = new html_table_row(
                array($labelsearchname,
                    '<input value="' . $urlparams['searchname'] .
                             '" type="text" id="searchname" name="searchname">', "", ""));
        $row->id = 'booking-searchname-row';
        $tabledata[] = $row;
        $rowclasses[] = "";

        $row = new html_table_row(
                array($labelsearchsurname,
                    '<input value="' . $urlparams['searchsurname'] .
                             '" type="text" id="searchsurname" name="searchsurname">', "", ""));
        $row->id = 'booking-searchsurname-row';
        $tabledata[] = $row;
        $rowclasses[] = "";

        $row = new html_table_row(
                array("",
                    '<input id="searchButton" type="submit" value="' . get_string('search') .
                             '"><input id="buttonclear" type="button" value="' .
                             get_string('reset', 'booking') . '">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";

        $table = new html_table();
        $table->head = array('', '', '', '');
        $table->data = $tabledata;
        $table->id = "tableSearch";
        $table->attributes['class'] = "table table-striped ";
        if (empty($urlparams['searchtext']) && empty($urlparams['searchlocation']) &&
                 empty($urlparams['searchname']) && empty($urlparams['searchinstitution']) &&
                 empty($urlparams['searchsurname'])) {
            $table->attributes['style'] = "display: none;";
        }
        echo html_writer::tag('form', html_writer::table($table));

        $optionsfields = explode(',', $booking->settings->optionsfields);

        $optionsfields[] = 'availableplaces';

        foreach ($optionsfields as $value) {
            switch ($value) {
                case 'text':
                    $columns[] = 'text';
                    $headers[] = get_string("select", "mod_booking");
                    break;
                case 'coursestarttime':
                    $columns[] = 'coursestarttime';
                    $headers[] = get_string("coursedate", "mod_booking");
                    break;
                case 'availableplaces':
                    $columns[] = 'availableplaces';
                    $headers[] = get_string("availability", "mod_booking");
                    break;
            }
        }

        $columns[] = 'id';
        $headers[] = "";
        $usersofgroupsql = '';
        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
            !has_capability('moodle/site:accessallgroups', \context_course::instance($course->id))) {
            list ($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql($course->id);
            $conditionsparams = array_merge($conditionsparams, $groupparams);
            $usersofgroupsql = "
                (SELECT COUNT(*)
                   FROM {booking_answers} ba
                  WHERE ba.optionid = bo.id
                    AND ba.userid IN ( $groupsql )) AS allbookedsamegroup,";
        }

        $fields = "DISTINCT bo.id,
                         bo.text,
                         bo.address,
                         bo.credits,
                         bo.description,
                         bo.coursestarttime,
                         bo.courseendtime,
                         bo.limitanswers,
                         bo.maxanswers,
                         bo.maxoverbooking,
                         bo.invisible,
                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 0) AS booked,

                          $usersofgroupsql

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 1) AS waiting,
                         bo.location,
                         bo.institution,

                  (SELECT bo.maxanswers - (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 0)) AS availableplaces,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.userid = :userid) AS iambooked,
                         b.allowupdate,
                         b.allowupdatedays,
                         bo.bookingclosingtime,
                         b.btncancelname,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.completed = 1
                     AND ba.userid = :userid4) AS completed,

                  (SELECT status
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.status > 0
                     AND ba.userid = :userid6) AS status,

                  (SELECT DISTINCT(ba.waitinglist)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.userid = :userid1) AS waitinglist,
                         b.btnbooknowname,
                         b.maxperuser,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                    LEFT JOIN
                        {booking_options} bo ON bo.id = ba.optionid
                   WHERE ba.bookingid = b.id
                     AND ba.userid = :userid2
                    AND (bo.courseendtime = 0
                    OR bo.courseendtime > :timestampnow)) AS bookinggetuserbookingcount,
                         b.cancancelbook,
                         bo.disablebookingusers,

                  (SELECT COUNT(*)
                   FROM {booking_teachers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid3) AS isteacher,

                  (SELECT AVG(rate)
                   FROM {booking_ratings} br
                  WHERE br.optionid = bo.id) AS rating,

                  (SELECT COUNT(*)
                   FROM {booking_ratings} br
                  WHERE br.optionid = bo.id) AS ratingcount,

                  (SELECT rate
                  FROM {booking_ratings} br
                  WHERE br.optionid = bo.id
                    AND br.userid = :userid5) AS myrating
                ";
        $from = "{booking} b LEFT JOIN {booking_options} bo ON bo.bookingid = b.id";
        if ($iselective) {

            if (!isset($_GET['list'])
                    || (!$electivesarray = json_decode($_GET['list']))) {
                $electivesarray = [];
                $listorder = '[]';
            } else {
                $listorder = $_GET['list'];
            }

            $electivesarray = json_decode($listorder);

            $selectedarray = $electivesarray && $electivesarray[0] != '' ? implode(",", $electivesarray) : '0';
            $selectedarray = rtrim($selectedarray, ',');

            if ($selectedarray == "") {
                $selectedarray = 0;
            }

            if ($whichview !== 'mybooking') {
                $from .= " LEFT JOIN {booking_combinations} bc ON bo.id = bc.optionid
                    AND bc.cancombine=0
                    AND bc.otheroptionid IN (". $selectedarray . ")";
                $conditions[] = "(bc.cancombine IS null)";

                if ($booking->uses_credits()) {
                    $conditions[] = "((bo.credits <= :creditsleft) 
                OR bo.id IN (". $selectedarray . "))";
                }
            }

            $conditionsparams['creditsleft'] = booking_elective::return_credits_left($booking);
        }

        $where = "b.id = :bookingid " .
                 (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions));

        // If the user does not have the capability to see invisible options...
        if (!has_capability('mod/booking:canseeinvisibleoptions', $context)) {
            // ... then only show visible options.
            $where .= " AND bo.invisible = 0";
        }

        $defaultorder = ($booking->settings->defaultoptionsort !== 'availableplaces') ? SORT_ASC : SORT_DESC;
        if (!in_array('coursestarttime', $columns) && ($booking->settings->defaultoptionsort === 'coursestarttime')) {
            // Fixed: If sort by is set to coursestarttime but coursestarttime column is missing ...
            // ... we still want to order by coursestarttime.
            $tablealloptions->sortable(false);
            $where .= " ORDER BY bo.coursestarttime ASC";
        } else {
            // Else we can use the sortable method of table_sql.
            $tablealloptions->sortable(true, $booking->settings->defaultoptionsort, $defaultorder);
        }

        $conditionsparams['userid'] = $USER->id;
        $conditionsparams['userid1'] = $USER->id;
        $conditionsparams['userid2'] = $USER->id;
        $conditionsparams['timestampnow'] = time();
        $conditionsparams['userid3'] = $USER->id;
        $conditionsparams['userid4'] = $USER->id;
        $conditionsparams['userid5'] = $USER->id;
        $conditionsparams['userid6'] = $USER->id;
        $conditionsparams['bookingid'] = $booking->settings->id;

        $tablealloptions->set_sql($fields, $from, $where, $conditionsparams);

        $tablealloptions->define_columns($columns);
        $tablealloptions->define_headers($headers);
        unset($tablealloptions->attributes['cellspacing']);

        $paging = $booking->settings->paginationnum;
        if ($paging == 0) {
            $paging = 25;
        }
        $tablealloptions->setup();
        $tablealloptions->query_db($paging, true);

        // Prepare rawdata for adding teachers and times.
        foreach ($tablealloptions->rawdata as $optionid => $option) {
            $option->times = null;
            $option->teachers = "";
        }

        // Add teachers to rawdata.
        $teachers = array();
        $tachernamesql = $DB->sql_fullname('u.firstname', 'u.lastname');
        $bookingoptionids = array_keys($tablealloptions->rawdata);
        if (!empty($bookingoptionids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($bookingoptionids);
            $teachersql = "SELECT t.id, bo.id AS boid, u.id AS uid, $tachernamesql as teachername
                            FROM {booking_options} bo, {booking_teachers} t
                            LEFT JOIN {user} u ON u.id = t.userid
                            WHERE bo.id = t.optionid
                            AND t.optionid $insql";
            $teachers = $DB->get_records_sql($teachersql, $inparams);

            $optionteachers = array();
            foreach ($teachers as $teacher) {
                if (empty($optionteachers[$teacher->boid])) {
                    $optionteachers[$teacher->boid] = $teacher->teachername;
                } else {
                    $optionteachers[$teacher->boid] .= ", " . $teacher->teachername;
                }
            }
            if (!empty($optionteachers)) {
                foreach ($optionteachers as $key => $teacher) {
                    $tablealloptions->rawdata[$key]->teachers = $teacher;
                }
            }

            $timessql = 'SELECT bod.id AS dateid, bo.id AS optionid, ' .
                     $DB->sql_concat('bod.coursestarttime', "'-'", 'bod.courseendtime') . ' AS times
                   FROM {booking_optiondates} bod, {booking_options} bo
                   WHERE bo.id = bod.optionid
                   AND bo.id ' . $insql . '
                   ORDER BY bod.coursestarttime ASC';
            $times = $DB->get_records_sql($timessql, $inparams);

            if (!empty($times)) {
                foreach ($times as $time) {
                    // Optiondateid will be needed to render custom fields in all_options.php.
                    $timeobject = new stdClass();
                    $timeobject->optiondateid = $time->dateid;
                    $timeobject->times = $time->times;
                    $optiontimes[$time->optionid][] = $timeobject;
                }
                if (!empty($optiontimes)) {
                    foreach ($optiontimes as $key => $timeobject) {
                        $tablealloptions->rawdata[$key]->timeobjects = $timeobject;
                    }
                }
            }
        }

        $tablealloptions->build_table();
        if ($tablealloptions->count_records() > 0) {
            $tablealloptions->finish_output();
        } else {
            if (has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->notification(
                        get_string('infonobookingoption', 'mod_booking',
                                get_string('pluginname', 'block_settings')));
            } else {
                $tablealloptions->finish_output();
            }
        }
    } else {
        // Downloading the data as CSV or similar.
        $columns = array();
        $headers = array();

        $customfields = '';

        list($columns, $headers, $userprofilefields) = $booking->get_fields();

        if ($userprofilefields) {
            foreach ($userprofilefields as $profilefield) {
                $columns[] = "cust" . strtolower($profilefield->shortname);
                $headers[] = $profilefield->name;
                $customfields .= ", (SELECT " . $DB->sql_concat('uif.datatype', "'|'", 'uid.data') . " as custom
                FROM {user_info_data} uid
                LEFT JOIN {user_info_field}  uif ON uid.fieldid = uif.id
                WHERE userid = tba.userid
                AND uif.shortname = '{$profilefield->shortname}') AS cust" .
                strtolower($profilefield->shortname);
            }
        }

        if ($myoptions->myoptions > 0 && !has_capability('mod/booking:readresponses', $context)) {
            $conditionsparams['onlyinstitution1'] = $USER->institution;
            $conditions[] = 'tu.institution LIKE :onlyinstitution1';
        }
        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
            !has_capability('moodle/site:accessallgroups', \context_course::instance($course->id))) {

            list ($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql($course->id);
            array_push($conditions, "tu.id IN ($groupsql)");
            $conditionsparams = array_merge($conditionsparams, $groupparams);
        }

        $fields = "tba.id,
                        tu.id AS userid,
                        tba.optionid AS optionid,
                        tbo.text AS booking,
                        tu.institution AS institution,
                        tbo.location AS location,
                        tbo.coursestarttime AS coursestarttime,
                        tbo.courseendtime AS courseendtime,
                        tba.numrec AS numrec,
                        tu.firstname AS firstname,
                        tu.lastname AS lastname,
                        tu.city AS city,
                        tu.department AS department,
                        tu.username AS username,
                        tu.email AS email,
                        tba.completed AS completed,
                        tba.status,
                        tba.numrec,
                        tba.notes,
                        otherbookingoption.text AS otheroptions,
                        tba.waitinglist AS waitinglist,
                        tu.idnumber AS idnumber {$customfields}";
        $from = '{booking_answers} tba
                JOIN {user} tu ON tu.id = tba.userid
                JOIN {booking_options} tbo ON tbo.id = tba.optionid
                LEFT JOIN {booking_options} otherbookingoption ON otherbookingoption.id = tba.frombookingid';
        $where = 'tu.deleted = 0 AND tu.suspended = 0 AND tba.optionid IN (SELECT DISTINCT bo.id FROM {booking} b
                                    LEFT JOIN {booking_options} bo ON bo.bookingid = b.id WHERE b.id = :bookingid ' .
                 (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions)) . ')';

        $conditionsparams['userid'] = $USER->id;
        $conditionsparams['userid1'] = $USER->id;
        $conditionsparams['userid2'] = $USER->id;
        $conditionsparams['userid3'] = $USER->id;
        $conditionsparams['bookingid'] = $booking->settings->id;
        $conditionsparams['tcourseid'] = $course->id;
        $tablealloptions->define_columns($columns);
        $tablealloptions->define_headers($headers);
        $tablealloptions->set_sql($fields, $from, $where, $conditionsparams);
        unset($tablealloptions->attributes['cellspacing']);
        $tablealloptions->setup();
        $tablealloptions->query_db(10);
        if (!empty($tablealloptions->rawdata)) {
            foreach ($tablealloptions->rawdata as $option) {
                $option->otheroptions = "";
                $option->groups = "";
            }
        }
        if (!empty($tablealloptions->rawdata)) {
            foreach ($tablealloptions->rawdata as $option) {
                $option->otheroptions = "";
                $option->groups = "";
                $groups = groups_get_user_groups($course->id, $option->userid);
                if (!empty($groups[0])) {
                    $groupids = implode(',', $groups[0]);
                    list($groupids, $groupidsparams) = $DB->get_in_or_equal($groups[0]);
                    $groupnames = $DB->get_fieldset_select('groups', 'name', " id $groupids", $groupidsparams);
                    $option->groups = implode(', ', $groupnames);
                }
            }
        }
        $tablealloptions->build_table();
        $tablealloptions->finish_output();
        exit();
    }
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->error_text(get_string("norighttobook", "booking"));
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', array('id' => $course->id)));
}

// Render this only if we are in elective mode.
if ($iselective) {

    $rawdata = $tablealloptions->rawdata;

    if ($rawdata) {
        $data = new \mod_booking\output\elective_modal($booking, $rawdata, $listorder);
        $renderer = $PAGE->get_renderer('mod_booking');
        echo $renderer->render_elective_modal($data);
    }
}

echo $OUTPUT->box('<a href="http://www.wunderbyte.at">' . get_string('createdbywunderbyte', 'mod_booking') . "</a>",
        'box mdl-align');
echo $OUTPUT->footer();
