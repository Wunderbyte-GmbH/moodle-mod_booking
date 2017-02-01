<?php
require_once("../../config.php");
require_once("locallib.php");
require_once($CFG->libdir . '/completionlib.php');
require_once("{$CFG->libdir}/tablelib.php");
require_once("{$CFG->dirroot}/mod/booking/classes/all_options.php");

$id = required_param('id', PARAM_INT); // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$whichview = optional_param('whichview', '', PARAM_ALPHA);
$optionid = optional_param('optionid', '', PARAM_INT);
$confirm = optional_param('confirm', '', PARAM_INT);
$answer = optional_param('answer', '', PARAM_ALPHANUM);
$sorto = optional_param('sort', '1', PARAM_INT);
$searchText = optional_param('searchText', '', PARAM_TEXT);
$searchLocation = optional_param('searchLocation', '', PARAM_TEXT);
$searchInstitution = optional_param('searchInstitution', '', PARAM_TEXT);
$searchName = optional_param('searchName', '', PARAM_TEXT);
$searchSurname = optional_param('searchSurname', '', PARAM_TEXT);
$page = optional_param('page', '0', PARAM_INT);

$perPage = 10;
$conditions = array();
$conditionsParams = array();
$urlParams = array();
$urlParamsSort = array();
$urlParams['id'] = $id;

list($course, $cm) = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);
$context = context_module::instance($cm->id);

$booking = new \mod_booking\booking_options($cm->id, TRUE, array(), 0, 0, false);

if (!empty($action)) {
    $urlParams['action'] = $action;
}

if (!empty($whichview)) {
    $urlParams['whichview'] = $whichview;
} else {
    $urlParams['whichview'] = $booking->booking->whichview;
    $whichview = $booking->booking->whichview;
}

if ($optionid > 0) {
    $urlParams['optionid'] = $optionid;
}

$urlParams['searchText'] = "";
if (strlen($searchText) > 0) {
    $urlParams['searchText'] = $searchText;
    $conditions[] = "bo.text LIKE :searchtext";
    $conditionsParams['searchtext'] = "%{$searchText}%";
}

$urlParams['searchLocation'] = "";
if (strlen($searchLocation) > 0) {
    $urlParams['searchLocation'] = $searchLocation;
    $conditions[] = "bo.location LIKE :searchlocation";
    $conditionsParams['searchlocation'] = "%{$searchLocation}%";
}

$urlParams['searchInstitution'] = "";
if (strlen($searchInstitution) > 0) {
    $urlParams['searchInstitution'] = $searchInstitution;
    $conditions[] = "bo.institution LIKE :searchinstitution";
    $conditionsParams['searchinstitution'] = "%{$searchInstitution}%";
}

$urlParams['searchName'] = "";
if (strlen($searchName) > 0) {
    $urlParams['searchName'] = $searchName;
    $conditions[] = "bo.id IN (SELECT DISTINCT optionid FROM (SELECT userid, optionid FROM {booking_teachers} WHERE bookingid = :snbookingid1 UNION SELECT userid, optionid FROM {booking_answers} WHERE bookingid = :snbookingid2) AS un LEFT JOIN mdl_user AS u ON u.id = un.userid WHERE u.firstname LIKE :searchname)";
    $conditionsParams['searchname'] = "%{$searchName}%";
    $conditionsParams['snbookingid1'] = $booking->id;
    $conditionsParams['snbookingid2'] = $booking->id;
}

$urlParams['searchSurname'] = "";
if (strlen($searchSurname) > 0) {
    $urlParams['searchSurname'] = $searchSurname;
    $conditions[] = "bo.id IN (SELECT DISTINCT optionid FROM (SELECT userid, optionid FROM {booking_teachers} WHERE bookingid = :snbookingid3 UNION SELECT userid, optionid FROM {booking_answers} WHERE bookingid = :snbookingid4) AS un LEFT JOIN mdl_user AS u ON u.id = un.userid WHERE u.lastname LIKE :searchsurname)";
    $conditionsParams['searchsurname'] = "%{$searchSurname}%";
    $conditionsParams['snbookingid3'] = $booking->id;
    $conditionsParams['snbookingid4'] = $booking->id;
}

$urlParamsSort = $urlParams;

if ($sorto == 1) {
    $urlParams['sort'] = 1;
    $urlParamsSort['sort'] = 0;
} else if ($sorto == 0) {
    $urlParams['sort'] = 0;
    $urlParamsSort['sort'] = 1;
}

$url = new moodle_url('/mod/booking/view.php', $urlParams);
$urlCancel = new moodle_url('/mod/booking/view.php', array('id' => $id));
$sortUrl = new moodle_url('/mod/booking/view.php', $urlParamsSort);

$PAGE->set_url($url);
$PAGE->requires->yui_module('moodle-mod_booking-viewscript', 'M.mod_booking.viewscript.init');

$booking->apply_tags();
$booking->get_url_params();

$strbooking = get_string('modulename', 'booking');
$strbookings = get_string('modulenameplural', 'booking');

// check if data has been submitted to be processed
if ($action == 'delbooking' and confirm_sesskey() && $confirm == 1 and
         has_capability('mod/booking:choose', $context) and
         ($booking->booking->allowupdate or has_capability('mod/booking:deleteresponses', $context))) {
    $bookingData = new \mod_booking\booking_option($cm->id, $optionid);
    $bookingData->apply_tags();

    if ($bookingData->user_delete_response($USER->id)) {
        echo $OUTPUT->header();
        $contents = get_string('bookingdeleted', 'booking');
        $options = array('id' => $cm->id);
        $contents .= $OUTPUT->single_button(new moodle_url('view.php', $options),
                get_string('continue'), 'get');
        echo $OUTPUT->box($contents, 'box generalbox', 'notice');
        echo $OUTPUT->footer();
        die();
    }
} elseif ($action == 'delbooking' and confirm_sesskey() and
         has_capability('mod/booking:choose', $context) and
         ($booking->booking->allowupdate or has_capability('mod/booking:deleteresponses', $context))) { // print
                                                                                                       // confirm
                                                                                                       // delete
                                                                                                       // form
    echo $OUTPUT->header();

    $bookingData = new \mod_booking\booking_option($cm->id, $optionid);
    $bookingData->apply_tags();

    $options = array('id' => $cm->id, 'action' => 'delbooking', 'confirm' => 1,
        'optionid' => $optionid, 'sesskey' => $USER->sesskey);

    $deletemessage = $bookingData->option->text;

    if ($bookingData->option->coursestarttime != 0) {
        $deletemessage .= "<br />" .
                 userdate($bookingData->option->coursestarttime, get_string('strftimedatetime')) .
                 " - " .
                 userdate($bookingData->option->courseendtime, get_string('strftimedatetime'));
    }

    echo $OUTPUT->confirm(get_string('deletebooking', 'booking', $deletemessage),
            new moodle_url('view.php', $options), $urlCancel);
    echo $OUTPUT->footer();
    die();
}

// before processing data user has to agree to booking policy and confirm booking
if ($form = data_submitted() && has_capability('mod/booking:choose', $context) && $download == '' &&
         confirm_sesskey() && $confirm != 1 && $answer) {
    booking_confirm_booking($answer, $booking, $USER, $cm, $url);
    die();
}

$PAGE->set_title(format_string($booking->booking->name));
$PAGE->set_heading($booking->booking->name);

// check if custom user profile fields are required and redirect to complete them if necessary
if (has_capability('moodle/user:editownprofile', $context, NULL, false) and
         booking_check_user_profile_fields($USER->id) and
         !has_capability('moodle/site:config', $context)) {
    echo $OUTPUT->header();
    $contents = get_string('mustfilloutuserinfobeforebooking', 'booking');
    $contents .= $OUTPUT->single_button(
            new moodle_url("edituserprofile.php",
                    array('cmid' => $cm->id, 'courseid' => $course->id)), get_string('continue'),
            'get');
    echo $OUTPUT->box($contents, 'box generalbox', 'notice');
    echo $OUTPUT->footer();
    die();
}

// Submit any new data if there is any
if ($download == '' && $form = data_submitted() && has_capability('mod/booking:choose', $context)) {
    echo $OUTPUT->header();
    $timenow = time();

    $url = new moodle_url("view.php", array('id' => $cm->id));
    $url->set_anchor("option" . $answer);

    if (!empty($answer)) {
        $bookingData = new \mod_booking\booking_option($cm->id, $answer, array(), 0, 0, false);
        $bookingData->apply_tags();
        if ($bookingData->user_submit_response($USER)) {
            $contents = get_string('bookingsaved', 'booking');
            if ($booking->booking->sendmail) {
                $contents .= "<br />" . get_string('mailconfirmationsent', 'booking') . ".";
            }
            $contents .= $OUTPUT->single_button($url, get_string('continue'), 'get');
            echo $OUTPUT->box($contents, 'box generalbox', 'notice');
            echo $OUTPUT->footer();
            die();
        } elseif (is_int($answer)) {
            $contents = get_string('bookingmeanwhilefull', 'booking') . " " .
                     $bookingData->option->text;
            $contents .= $OUTPUT->single_button($url, 'get');
            echo $OUTPUT->box($contents, 'box generalbox', 'notice');
            echo $OUTPUT->footer();
            die();
        }
    } else {
        $contents = get_string('nobookingselected', 'booking');
        $contents .= $OUTPUT->single_button($url, 'get');
        echo $OUTPUT->box($contents, 'box generalbox', 'notice');
        echo $OUTPUT->footer();
        die();
    }
}

$event = \mod_booking\event\course_module_viewed::create(
        array('objectid' => $PAGE->cm->instance, 'context' => $PAGE->context));
$event->add_record_snapshot('course', $PAGE->course);
$event->trigger();

// Display the booking and possibly results

$bookinglist = $booking->allbookedusers;

$mybookings = $DB->get_record_sql(
        "SELECT COUNT(*) AS mybookings FROM {booking_answers} WHERE userid = :userid AND bookingid = :bookingid",
        array('userid' => $USER->id, 'bookingid' => $booking->id));
$myoptions = $DB->get_record_sql(
        "SELECT COUNT(*) AS myoptions FROM {booking_teachers} WHERE userid = :userid AND bookingid = :bookingid",
        array('userid' => $USER->id, 'bookingid' => $booking->id));

$current = false; // Initialise for later
                  // if user has already made a selection, show their selected answer.
                  // Print the form
$bookingopen = true;
$timenow = time();

if (!$current and $bookingopen and has_capability('mod/booking:choose', $context)) {

    switch ($whichview) {
        case 'mybooking':
            $conditions[] = "bo.id IN (SELECT optionid FROM {booking_answers} WHERE userid = :myuserid AND bookingid = :mybookingid)";
            $conditionsParams['myuserid'] = $USER->id;
            $conditionsParams['mybookingid'] = $booking->id;
            break;

        case 'myoptions':
            $conditions[] = "bo.id IN (SELECT optionid FROM {booking_teachers} WHERE userid = :myuserid AND bookingid = :mybookingid)";
            $conditionsParams['myuserid'] = $USER->id;
            $conditionsParams['mybookingid'] = $booking->id;
            break;

        case 'showall':
            $conditions[] = "bo.bookingid = :bookingid1";
            $conditionsParams['bookingid1'] = $booking->id;
            break;

        case 'showonlyone':
            $conditions[] = "bo.id = :optionid";
            $conditionsParams['optionid'] = $optionid;
            break;

        case 'showactive':
            $conditions[] = "(bo.courseendtime > :time OR bo.courseendtime = 0)";
            $conditionsParams['time'] = time();
            break;

        case 'myinstitution':
            $conditions[] = "bo.institution LIKE :institution";
            $conditionsParams['institution'] = "%{$USER->institution}%";
            break;

        default:
            break;
    }

    $tableAllOtions = new all_options('mod_booking_all_options', $booking, $cm, $context);
    $tableAllOtions->is_downloading($download, $booking->booking->name, $booking->booking->name);

    $tableAllOtions->define_baseurl($url);
    $tableAllOtions->defaultdownloadformat = 'ods';
    if (has_capability('mod/booking:downloadresponses', $context)) {
        $tableAllOtions->is_downloadable(true);
    } else {
        $tableAllOtions->is_downloadable(false);
    }
    $tableAllOtions->show_download_buttons_at(array(TABLE_P_BOTTOM));

    $columns = array();
    $headers = array();

    if (!$tableAllOtions->is_downloading()) {
        echo $OUTPUT->header();

        echo '<div class="clearer"></div>';

        if ($booking->booking->showhelpfullnavigationlinks) {
            echo $html = html_writer::tag('div',
                    '<a id="gotop" href="#goenrol">' . get_string('goenrol', 'booking') . '</a>',
                    array('style' => 'width:100%; font-weight: bold; text-align: right;'));
            echo html_writer::tag('div', format_module_intro('booking', $booking->booking, $cm->id),
                    array('class' => 'intro'));
        }

        if (!empty($booking->booking->duration)) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string('eventduration', 'booking') . ': ',
                    array('class' => 'bold'));
            echo html_writer::tag('span', $booking->booking->duration);
            echo html_writer::end_tag('div');
        }

        if (!empty($booking->booking->points) && ($booking->booking->points != 0)) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string('eventpoints', 'booking') . ': ',
                    array('class' => 'bold'));
            echo html_writer::tag('span', $booking->booking->points);
            echo html_writer::end_tag('div');
        }

        if (!empty($booking->booking->organizatorname)) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string('organizatorname', 'booking') . ': ',
                    array('class' => 'bold'));
            echo html_writer::tag('span', $booking->booking->organizatorname);
            echo html_writer::end_tag('div');
        }

        $out = array();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_booking', 'myfilemanager',
                $booking->booking->id);

        if (count($files) > 0) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string("attachedfiles", "booking") . ': ',
                    array('class' => 'bold'));

            foreach ($files as $file) {
                if ($file->get_filesize() > 0) {
                    $filename = $file->get_filename();
                    $furl = file_encode_url($CFG->wwwroot . '/pluginfile.php',
                            '/' . $file->get_contextid() . '/' . $file->get_component() . '/' .
                                     $file->get_filearea() . '/' . $file->get_itemid() . '/' .
                                     $file->get_filename());
                    $out[] = html_writer::link($furl, $filename);
                }
            }
            echo html_writer::tag('span', implode(', ', $out));
            echo html_writer::end_tag('div');
        }

        if (!empty($CFG->usetags)) {
            if ($CFG->branch >= 31) {
                $tags = core_tag_tag::get_item_tags('mod_booking', 'booking', $booking->booking->id);
                echo $OUTPUT->tag_list($tags, null, 'booking-tags');
            } else {
                $tags = tag_get_tags_array('booking', $booking->booking->id);
                $links = array();
                foreach ($tags as $tagid => $tag) {
                    $turl = new moodle_url('tag.php', array('id' => $id, 'tag' => $tag));
                    $links[] = html_writer::link($turl, $tag, array());
                }

                if (!empty($tags)) {
                    echo html_writer::start_tag('div');
                    echo html_writer::tag('label', get_string('tags') . ': ',
                            array('class' => 'bold'));
                    echo html_writer::tag('span', implode(', ', $links));
                    echo html_writer::end_tag('div');
                }
            }
        }

        if ($booking->booking->categoryid != '0' && $booking->booking->categoryid != '') {
            $categoryies = explode(',', $booking->booking->categoryid);

            if (count($categoryies) > 0) {
                $links = array();
                foreach ($categoryies as $category) {
                    $tmpCat = $DB->get_record('booking_category', array('id' => $category));
                    $surl = new moodle_url('category.php',
                            array('id' => $id, 'category' => $tmpCat->id));
                    $links[] = html_writer::link($surl, $tmpCat->name, array());
                }

                echo html_writer::start_tag('div');
                echo html_writer::tag('label', get_string('category', 'booking') . ': ',
                        array('class' => 'bold'));
                echo html_writer::tag('span', implode(', ', $links));
                echo html_writer::end_tag('div');
            }
        }

        if (strlen($booking->booking->bookingpolicy) > 0) {
            $link = new moodle_url('/mod/booking/viewpolicy.php',
                    array('id' => $booking->booking->id, 'cmid' => $cm->id));
            echo $OUTPUT->action_link($link, get_string("bookingpolicy", "booking"),
                    new popup_action('click', $link));
        }

        if ($booking->booking->showhelpfullnavigationlinks) {
            echo $html = html_writer::tag('div',
                    '<a id="goenrol" href="#gotop">' . get_string('gotop', 'booking') . '</a>',
                    array('style' => 'width:100%; font-weight: bold; text-align: right;'));
        }

        if ($booking->booking->timeclose != 0) {
            if ($booking->booking->timeopen > $timenow &&
                     !has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->box(
                        get_string("notopenyet", "booking",
                                userdate($booking->booking->timeopen, get_string('strftimedate'))),
                        "center");
                echo $OUTPUT->footer();
                exit();
            } else if ($booking->booking->timeclose < $timenow &&
                     !has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->box(
                        get_string("expired", "booking", userdate($booking->booking->timeclose)),
                        "center");
                $bookingopen = false;
                echo $OUTPUT->footer();
                exit();
            }
        }

        echo $OUTPUT->box(booking_show_maxperuser($booking, $USER, $bookinglist), 'box mdl-align');

        $output = $PAGE->get_renderer('mod_booking');
        $output->print_booking_tabs($urlParams, $whichview, $mybookings->mybookings,
                $myoptions->myoptions);

        $search = '<a href="#" id="showHideSearch">' . get_string('search') . "</a>";

        if ($whichview != 'showonlyone') {
            echo $OUTPUT->box("{$search}", 'box mdl-align');
        }

        $hidden = "";

        foreach ($urlParams as $key => $value) {
            if (!in_array($key, array('searchText', 'searchLocation', 'searchInstitution'))) {
                $hidden .= '<input value="' . $value . '" type="hidden" name="' . $key . '">';
            }
        }

        $labelBooking = (empty($booking->booking->lblbooking) ? get_string('booking', 'booking') : $booking->booking->lblbooking);
        $labelLocation = (empty($booking->booking->lbllocation) ? get_string('location', 'booking') : $booking->booking->lbllocation);
        $labelInstitution = (empty($booking->booking->lblinstitution) ? get_string('institution',
                'booking') : $booking->booking->lblinstitution);
        $labelSearchName = (empty($booking->booking->lblname) ? get_string('searchName', 'booking') : $booking->booking->lblname);
        $labelSearchSurname = (empty($booking->booking->lblsurname) ? get_string('searchSurname',
                'booking') : $booking->booking->lblsurname);

        $row = new html_table_row(
                array($labelBooking,
                    $hidden . '<input value="' . $urlParams['searchText'] .
                             '" type="text" id="searchText" name="searchText">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(
                array($labelLocation,
                    '<input value="' . $urlParams['searchLocation'] .
                             '" type="text" id="searchLocation" name="searchLocation">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(
                array($labelInstitution,
                    '<input value="' . $urlParams['searchInstitution'] .
                             '" type="text" id="searchInstitution" name="searchInstitution">', "",
                            ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(
                array($labelSearchName,
                    '<input value="' . $urlParams['searchName'] .
                             '" type="text" id="searchName" name="searchName">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(
                array($labelSearchSurname,
                    '<input value="' . $urlParams['searchSurname'] .
                             '" type="text" id="searchSurname" name="searchSurname">', "", ""));
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

        if (empty($urlParams['searchText']) && empty($urlParams['searchLocation']) &&
                 empty($urlParams['searchName']) && empty($urlParams['searchInstitution']) &&
                 empty($urlParams['searchSurname'])) {
            $table->attributes = array('style' => "display: none;");
        }
        echo html_writer::tag('form', html_writer::table($table));

        $sortUrl->set_anchor('goenrol');

        $columns[] = 'text';
        $headers[] = get_string("select", "mod_booking");
        $columns[] = 'coursestarttime';
        $headers[] = get_string("coursedate", "mod_booking");
        $columns[] = 'maxanswers';
        $headers[] = get_string("availability", "mod_booking");
        $columns[] = 'id';
        $headers[] = "";

        $fields = "DISTINCT bo.id,
                         bo.text,
                         bo.address,
                         bo.description,
                         bo.coursestarttime,
                         bo.courseendtime,
                         bo.limitanswers,
                         bo.maxanswers,
                         bo.maxoverbooking,



                  (SELECT COUNT(*)
                   FROM {booking_answers} AS ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 0) AS booked,

                  (SELECT COUNT(*)
                   FROM {booking_answers} AS ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 1) AS waiting,
                         bo.location,
                         bo.institution,

                  (SELECT COUNT(*)
                   FROM {booking_answers} AS ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid) AS iambooked,
                         b.allowupdate,
                         bo.bookingclosingtime,
                         b.btncancelname,

                  (SELECT DISTINCT(ba.waitinglist)
                   FROM {booking_answers} AS ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid1) AS waitinglist,
                         b.btnbooknowname,
                         b.maxperuser,

                  (SELECT COUNT(*)
                   FROM {booking_answers} AS ba
                   WHERE ba.bookingid = b.id
                     AND ba.userid = :userid2) AS bookinggetuserbookingcount,
                         b.cancancelbook,
                         bo.disablebookingusers,

                  (SELECT COUNT(*)
                   FROM {booking_teachers} AS ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid3) AS isteacher
                ";
        $from = '{booking} AS b ' . 'LEFT JOIN {booking_options} AS bo ON bo.bookingid = b.id';
        $where = "b.id = :bookingid " .
                 (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions));

        $conditionsParams['userid'] = $USER->id;
        $conditionsParams['userid1'] = $USER->id;
        $conditionsParams['userid2'] = $USER->id;
        $conditionsParams['userid3'] = $USER->id;
        $conditionsParams['bookingid'] = $booking->booking->id;

        $tableAllOtions->set_sql($fields, $from, $where, $conditionsParams);

        $tableAllOtions->define_columns($columns);
        $tableAllOtions->define_headers($headers);
        unset($tableAllOtions->attributes['cellspacing']);

        $paging = $booking->booking->paginationnum;
        if ($paging == 0) {
            $paging = 25;
        }
        $tableAllOtions->setup();
        $tableAllOtions->query_db($paging, true);

        // Prepare rawdata for adding teachers and times
        foreach ($tableAllOtions->rawdata as $optionid => $option) {
            $option->times = null;
            $option->teachers = "";
        }

        // Add teachers to rawdata
        $teachers = array();
        $tachernamesql = $DB->sql_fullname('u.firstname', 'u.lastname');
        $bookingoptionids = array_keys($tableAllOtions->rawdata);
        $bookingoptionids = implode(',', $bookingoptionids);
        if (!empty($bookingoptionids)) {
            $teachersql = "SELECT u.id, bo.id AS boid, $tachernamesql as teachername
            FROM {booking_options} AS bo, {booking_teachers} AS t
            LEFT JOIN {user} AS u ON u.id = t.userid
            WHERE bo.id = t.optionid
            AND t.optionid IN (" . $bookingoptionids . ")";
            $teachers = $DB->get_records_sql($teachersql);

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
                    $tableAllOtions->rawdata[$key]->teachers = $teacher;
                }
            }

            $timessql = 'SELECT bod.id AS dateid, bo.id AS optionid, ' .
                     $DB->sql_concat('bod.coursestarttime', "'-'", 'bod.courseendtime') . ' AS times
                   FROM {booking_optiondates} bod, {booking_options} bo
                   WHERE bo.id = bod.optionid
                   AND bo.id IN (' . $bookingoptionids . ')
                   ORDER BY bod.coursestarttime ASC';
            $times = $DB->get_records_sql($timessql);

            if (!empty($times)) {
                foreach ($times as $time) {
                    if (empty($optiontimes[$time->optionid])) {
                        $optiontimes[$time->optionid] = $time->times;
                    } else {
                        $optiontimes[$time->optionid] .= ", " . $time->times;
                    }
                }
                if (!empty($optiontimes)) {
                    foreach ($optiontimes as $key => $time) {
                        $tableAllOtions->rawdata[$key]->times = $time;
                    }
                }
            }
        }

        $tableAllOtions->build_table();
        if ($tableAllOtions->count_records() > 0) {
            $tableAllOtions->finish_output();
        } else {
            if (has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->notification(
                        get_string('infonobookingoption', 'mod_booking',
                                get_string('pluginname', 'block_settings')));
            } else {
                $tableAllOtions->finish_output();
            }
        }
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
        if ($booking->booking->numgenerator) {
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

        $addfields = explode(',', $booking->booking->additionalfields);
        $addquoted = "'" . implode("','", $addfields) . "'";
        if ($userprofilefields = $DB->get_records_select('user_info_field',
                'id > 0 AND shortname IN (' . $addquoted . ')', array(), 'id', 'id, shortname, name')) {
            foreach ($userprofilefields as $profilefield) {
                $columns[] = "cust" . strtolower($profilefield->shortname);
                $headers[] = $profilefield->name;
                $customfields .= ", (SELECT " . $DB->sql_concat('uif.datatype', "'|'", ',uid.data') .
                         " as custom FROM {user_info_data} AS uid LEFT JOIN {user_info_field} AS uif ON uid.fieldid = uif.id WHERE userid = tba.userid AND uif.shortname = '{$profilefield->shortname}') AS cust" .
                         strtolower($profilefield->shortname);
            }
        }
        $columns[] = 'groups';
        $headers[] = get_string("group");

        if ($myoptions->myoptions > 0 && !has_capability('mod/booking:readresponses', $context)) {
            $conditionsParams['onlyinstitution1'] = $USER->institution;
            $conditions[] = 'tu.institution LIKE :onlyinstitution1';
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
                tu.username AS username,
                tu.email AS email,
                tba.completed AS completed,
                tba.numrec,
                otherbookingoption.text AS otheroptions,
                tba.waitinglist AS waitinglist {$customfields}";
        $from = '{booking_answers} AS tba
                JOIN {user} AS tu ON tu.id = tba.userid
                JOIN {booking_options} AS tbo ON tbo.id = tba.optionid
                FULL JOIN {booking_options} AS otherbookingoption ON otherbookingoption.id = tba.frombookingid';
        $where = 'tba.optionid IN (SELECT DISTINCT bo.id FROM {booking} AS b
                                    LEFT JOIN {booking_options} AS bo ON bo.bookingid = b.id WHERE b.id = :bookingid ' .
                 (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions)) . ')';

        $conditionsParams['userid'] = $USER->id;
        $conditionsParams['userid1'] = $USER->id;
        $conditionsParams['userid2'] = $USER->id;
        $conditionsParams['userid3'] = $USER->id;
        $conditionsParams['bookingid'] = $booking->booking->id;
        $conditionsParams['tcourseid'] = $course->id;
        $tableAllOtions->define_columns($columns);
        $tableAllOtions->define_headers($headers);
        $tableAllOtions->set_sql($fields, $from, $where, $conditionsParams);
        unset($tableAllOtions->attributes['cellspacing']);
        $tableAllOtions->setup();
        $tableAllOtions->query_db(10);
        if (!empty($tableAllOtions->rawdata)) {
            foreach ($tableAllOtions->rawdata as $option) {
                $option->otheroptions = "";
                $option->groups = "";
            }
        }
        if (!empty($tableAllOtions->rawdata)) {
            foreach ($tableAllOtions->rawdata as $option) {
                $option->otheroptions = "";
                $option->groups = "";
                $groups = groups_get_user_groups($course->id, $option->userid);
                if (!empty($groups[0])) {
                    $groupids = implode(',', $groups[0]);
                    $groupnames = $DB->get_fieldset_select('groups', 'name',
                            ' id IN (' . $groupids . ')');
                    $option->groups = implode(', ', $groupnames);
                }
            }
        }
        $tableAllOtions->build_table();
        $tableAllOtions->finish_output();
        exit();
    }
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->error_text(get_string("norighttobook", "booking"));
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', array('id' => $course->id)));
}
echo $OUTPUT->box('<a href="http://www.edulabs.org">' . get_string('createdby', 'booking') . "</a>",
        'box mdl-align');
echo $OUTPUT->footer();

?>