<?php
require_once("../../config.php");
require_once("locallib.php");
require_once($CFG->libdir . '/completionlib.php');
require_once("{$CFG->libdir}/tablelib.php");
require_once("{$CFG->dirroot}/mod/booking/classes/all_options.php");

$id = required_param('id', PARAM_INT);                 // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$whichview = optional_param('whichview', 'showactive', PARAM_ALPHA);
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

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    print_error('invalidcoursemodule');
}

$booking = new booking_options($cm->id, TRUE, array(), 0, 0, false);

if (!empty($action)) {
    $urlParams['action'] = $action;
}

if (!empty($whichview)) {
    $urlParams['whichview'] = $whichview;
} else {
    $urlParams['whichview'] = 'showactive';
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

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

$booking->apply_tags();
$booking->get_url_params();

$strbooking = get_string('modulename', 'booking');
$strbookings = get_string('modulenameplural', 'booking');

// check if data has been submitted to be processed
if ($action == 'delbooking' and confirm_sesskey() && $confirm == 1 and has_capability('mod/booking:choose', $context) and ( $booking->booking->allowupdate or has_capability('mod/booking:deleteresponses', $context))) {
    $bookingData = new booking_option($cm->id, $optionid);
    $bookingData->apply_tags();

    if ($bookingData->user_delete_response($USER->id)) {
        echo $OUTPUT->header();
        $contents = get_string('bookingdeleted', 'booking');
        $options = array('id' => $cm->id);
        $contents .= $OUTPUT->single_button(new moodle_url('view.php', $options), get_string('continue'), 'get');
        echo $OUTPUT->box($contents, 'box generalbox', 'notice');
        echo $OUTPUT->footer();
        die;
    }
} elseif ($action == 'delbooking' and confirm_sesskey() and has_capability('mod/booking:choose', $context) and ( $booking->booking->allowupdate or has_capability('mod/booking:deleteresponses', $context))) {    //print confirm delete form
    echo $OUTPUT->header();

    $bookingData = new booking_option($cm->id, $optionid);
    $bookingData->apply_tags();

    $options = array('id' => $cm->id, 'action' => 'delbooking', 'confirm' => 1, 'optionid' => $optionid, 'sesskey' => $USER->sesskey);

    $deletemessage = $bookingData->option->text;

    if ($bookingData->option->coursestarttime != 0) {
        $deletemessage .= "<br />" . userdate($bookingData->option->coursestarttime, get_string('strftimedatetime')) . " - " . userdate($bookingData->option->courseendtime, get_string('strftimedatetime'));
    }

    echo $OUTPUT->confirm(get_string('deletebooking', 'booking', $deletemessage), new moodle_url('view.php', $options), $urlCancel);
    echo $OUTPUT->footer();
    die;
}

// before processing data user has to agree to booking policy and confirm booking
if ($form = data_submitted() && has_capability('mod/booking:choose', $context) && $download == '' && confirm_sesskey() && $confirm != 1 && $answer) {
    booking_confirm_booking($answer, $booking, $USER, $cm, $url);
    die;
}

$PAGE->set_title(format_string($booking->booking->name));
$PAGE->set_heading($booking->booking->name);

if (has_capability('mod/booking:updatebooking', $context)) {
    $settingnode = $PAGE->settingsnav->add(get_string("bookingoptionsmenu", "booking"), null, navigation_node::TYPE_CONTAINER);

    $settingnode->add(get_string('addnewbookingoption', 'booking'), new moodle_url('editoptions.php', array('id' => $cm->id, 'optionid' => 'add')));
    $settingnode->add(get_string('importcsvbookingoption', 'booking'), new moodle_url('importoptions.php', array('id' => $cm->id)));
    $settingnode->add(get_string('importexcelbutton', 'booking'), new moodle_url('importexcel.php', array('id' => $cm->id)));
    $settingnode->add(get_string('tagtemplates', 'booking'), new moodle_url('tagtemplates.php', array('cmid' => $cm->id)));
}

// check if custom user profile fields are required and redirect to complete them if necessary
if (has_capability('moodle/user:editownprofile', $context, NULL, false) and booking_check_user_profile_fields($USER->id) and ! has_capability('moodle/site:config', $context)) {
    echo $OUTPUT->header();
    $contents = get_string('mustfilloutuserinfobeforebooking', 'booking');
    $contents .= $OUTPUT->single_button(new moodle_url("edituserprofile.php", array('cmid' => $cm->id, 'courseid' => $course->id)), get_string('continue'), 'get');
    echo $OUTPUT->box($contents, 'box generalbox', 'notice');
    echo $OUTPUT->footer();
    die;
}

/// Submit any new data if there is any
if ($download == '' && $form = data_submitted() && has_capability('mod/booking:choose', $context)) {
    echo $OUTPUT->header();
    $timenow = time();

    $url = new moodle_url("view.php", array('id' => $cm->id));
    $url->set_anchor("option" . $answer);


    if (!empty($answer)) {
        $bookingData = new booking_option($cm->id, $answer, array(), 0, 0, false);
        $bookingData->apply_tags();
        if ($bookingData->user_submit_response($USER)) {
            $contents = get_string('bookingsaved', 'booking');
            if ($booking->booking->sendmail) {

                $contents .= "<br />" . get_string('mailconfirmationsent', 'booking') . ".";
            }
            $contents .= $OUTPUT->single_button($url, get_string('continue'), 'get');
            echo $OUTPUT->box($contents, 'box generalbox', 'notice');
            echo $OUTPUT->footer();
            die;
        } elseif (is_int($answer)) {
            $contents = get_string('bookingmeanwhilefull', 'booking') . " " . $bookingData->option->text;
            $contents .= $OUTPUT->single_button($url, 'get');
            echo $OUTPUT->box($contents, 'box generalbox', 'notice');
            echo $OUTPUT->footer();
            die;
        }
    } else {
        $contents = get_string('nobookingselected', 'booking');
        $contents .= $OUTPUT->single_button($url, 'get');
        echo $OUTPUT->box($contents, 'box generalbox', 'notice');
        echo $OUTPUT->footer();
        die;
    }
}

$event = \mod_booking\event\course_module_viewed::create(array(
            'objectid' => $PAGE->cm->instance,
            'context' => $PAGE->context,
        ));
$event->add_record_snapshot('course', $PAGE->course);
$event->trigger();

/// Display the booking and possibly results

$bookinglist = $booking->allbookedusers;

$mybookings = $DB->get_record_sql("SELECT COUNT(*) AS mybookings FROM {booking_answers} WHERE userid = :userid AND bookingid = :bookingid", array('userid' => $USER->id, 'bookingid' => $booking->id));
$myoptions = $DB->get_record_sql("SELECT COUNT(*) AS myoptions FROM {booking_teachers} WHERE userid = :userid AND bookingid = :bookingid", array('userid' => $USER->id, 'bookingid' => $booking->id));

$current = false;  // Initialise for later
//if user has already made a selection, show their selected answer.
/// Print the form
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
            echo $html = html_writer::tag('div', '<a id="gotop" href="#goenrol">' . get_string('goenrol', 'booking') . '</a>', array('style' => 'width:100%; font-weight: bold; text-align: right;'));
            echo html_writer::tag('div', format_module_intro('booking', $booking->booking, $cm->id), array('class' => 'intro'));
        }

        if (!empty($booking->booking->duration)) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string('eventduration', 'booking') . ': ', array('class' => 'bold'));
            echo html_writer::tag('span', $booking->booking->duration);
            echo html_writer::end_tag('div');
        }

        if (!empty($booking->booking->points) && ($booking->booking->points != 0)) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string('eventpoints', 'booking') . ': ', array('class' => 'bold'));
            echo html_writer::tag('span', $booking->booking->points);
            echo html_writer::end_tag('div');
        }

        if (!empty($booking->booking->organizatorname)) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string('organizatorname', 'booking') . ': ', array('class' => 'bold'));
            echo html_writer::tag('span', $booking->booking->organizatorname);
            echo html_writer::end_tag('div');
        }

        $out = array();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_booking', 'myfilemanager', $booking->booking->id);

        if (count($files) > 0) {
            echo html_writer::start_tag('div');
            echo html_writer::tag('label', get_string("attachedfiles", "booking") . ': ', array('class' => 'bold'));

            foreach ($files as $file) {
                if ($file->get_filesize() > 0) {
                    $filename = $file->get_filename();
                    $furl = file_encode_url($CFG->wwwroot . '/pluginfile.php', '/' . $file->get_contextid() . '/' . $file->get_component() . '/' . $file->get_filearea() . '/' . $file->get_itemid() . '/' . $file->get_filename());
                    $out[] = html_writer::link($furl, $filename);
                }
            }
            echo html_writer::tag('span', implode(', ', $out));
            echo html_writer::end_tag('div');
        }

        if (!empty($CFG->usetags)) {
            $tags = tag_get_tags_array('booking', $booking->booking->id);

            $links = array();
            foreach ($tags as $tagid => $tag) {
                $turl = new moodle_url('tag.php', array('id' => $id, 'tag' => $tag));
                $links[] = html_writer::link($turl, $tag, array());
            }

            if (!empty($tags)) {
                echo html_writer::start_tag('div');
                echo html_writer::tag('label', get_string('tags') . ': ', array('class' => 'bold'));
                echo html_writer::tag('span', implode(', ', $links));
                echo html_writer::end_tag('div');
            }
        }

        if ($booking->booking->categoryid != '0' && $booking->booking->categoryid != '') {
            $categoryies = explode(',', $booking->booking->categoryid);

            if (count($categoryies) > 0) {
                $links = array();
                foreach ($categoryies as $category) {
                    $tmpCat = $DB->get_record('booking_category', array('id' => $category));
                    $surl = new moodle_url('category.php', array('id' => $id, 'category' => $tmpCat->id));
                    $links[] = html_writer::link($surl, $tmpCat->name, array());
                }

                echo html_writer::start_tag('div');
                echo html_writer::tag('label', get_string('category', 'booking') . ': ', array('class' => 'bold'));
                echo html_writer::tag('span', implode(', ', $links));
                echo html_writer::end_tag('div');
            }
        }

        if (strlen($booking->booking->bookingpolicy) > 0) {
            $link = new moodle_url('/mod/booking/viewpolicy.php', array('id' => $booking->booking->id, 'cmid' => $cm->id));
            echo $OUTPUT->action_link($link, get_string("bookingpolicy", "booking"), new popup_action('click', $link));
        }

        if ($booking->booking->showhelpfullnavigationlinks) {
            echo $html = html_writer::tag('div', '<a id="goenrol" href="#gotop">' . get_string('gotop', 'booking') . '</a>', array('style' => 'width:100%; font-weight: bold; text-align: right;'));
        }

        if ($booking->booking->timeclose != 0) {
            if ($booking->booking->timeopen > $timenow && !has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->box(get_string("notopenyet", "booking", userdate($booking->booking->timeopen, get_string('strftimedate'))), "center");
                echo $OUTPUT->footer();
                exit;
            } else if ($booking->booking->timeclose < $timenow && !has_capability('mod/booking:updatebooking', $context)) {
                echo $OUTPUT->box(get_string("expired", "booking", userdate($booking->booking->timeclose)), "center");
                $bookingopen = false;
            }
        }

        echo $OUTPUT->box(booking_show_maxperuser($booking, $USER, $bookinglist), 'box mdl-align');

        $output = $PAGE->get_renderer('mod_booking');
        $output->print_booking_tabs($urlParams, $whichview, $mybookings->mybookings, $myoptions->myoptions);

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
        $labelInstitution = (empty($booking->booking->lblinstitution) ? get_string('institution', 'booking') : $booking->booking->lblinstitution);
        $labelSearchName = (empty($booking->booking->lblname) ? get_string('searchName', 'booking') : $booking->booking->lblname);
        $labelSearchSurname = (empty($booking->booking->lblsurname) ? get_string('searchSurname', 'booking') : $booking->booking->lblsurname);

        $row = new html_table_row(array($labelBooking, '<form>' . $hidden . '<input value="' . $urlParams['searchText'] . '" type="text" id="searchText" name="searchText">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(array($labelLocation, '<input value="' . $urlParams['searchLocation'] . '" type="text" id="searchLocation" name="searchLocation">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(array($labelInstitution, '<input value="' . $urlParams['searchInstitution'] . '" type="text" id="searchInstitution" name="searchInstitution">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(array($labelSearchName, '<input value="' . $urlParams['searchName'] . '" type="text" id="searchName" name="searchName">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(array($labelSearchSurname, '<input value="' . $urlParams['searchSurname'] . '" type="text" id="searchSurname" name="searchSurname">', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";
        $row = new html_table_row(array("", '<input id="searchButton" type="submit" value="' . get_string('search') . '"><input id="buttonclear" type="button" value="' . get_string('reset', 'booking') . '"></form>', "", ""));
        $tabledata[] = $row;
        $rowclasses[] = "";

        $table = new html_table();
        $table->head = array('', '', '');
        $table->data = $tabledata;
        $table->id = "tableSearch";
        if (empty($urlParams['searchText']) && empty($urlParams['searchLocation']) && empty($urlParams['searchName']) && empty($urlParams['searchInstitution']) && empty($urlParams['searchSurname'])) {
            $table->attributes = array('style' => "display: none;");
        }
        echo html_writer::table($table);

        $sortUrl->set_anchor('goenrol');

        $columns[] = 'text';
        $headers[] = get_string("select", "booking");
        $columns[] = 'coursestarttime';
        $headers[] = get_string("coursedate", "booking");
        $columns[] = 'maxanswers';
        $headers[] = get_string("availability", "booking");
        $columns[] = 'id';
        $headers[] = "";

        $fields = "DISTINCT bo.id, bo.text, bo.address, bo.coursestarttime, bo.courseendtime, (SELECT GROUP_CONCAT(CONCAT(CONCAT(u.firstname, ' '), u.lastname) SEPARATOR ', ') AS teachers FROM {booking_teachers} AS t LEFT JOIN {user} AS u ON u.id = t.userid WHERE t.optionid = bo.id) AS teachers, bo.limitanswers, bo.maxanswers, bo.maxoverbooking, (SELECT  COUNT(*) FROM {booking_answers} AS ba WHERE ba.optionid = bo.id AND ba.waitinglist = 0) AS booked, (SELECT COUNT(*) FROM {booking_answers} AS ba WHERE ba.optionid = bo.id AND ba.waitinglist = 1) AS waiting, bo.location, bo.institution, (SELECT COUNT(*) FROM {booking_answers} AS ba WHERE ba.optionid = bo.id AND ba.userid = :userid) AS iambooked, b.allowupdate, bo.bookingclosingtime, b.btncancelname, (SELECT ba.waitinglist FROM {booking_answers} AS ba WHERE ba.optionid = bo.id AND ba.userid = :userid1) AS waitinglist, b.btnbooknowname, b.maxperuser, (SELECT 
            COUNT(*) FROM {booking_answers} AS ba WHERE ba.bookingid = b.id AND ba.userid = :userid2) AS bookinggetuserbookingcount, b.cancancelbook, bo.disablebookingusers,
            (SELECT COUNT(*) FROM {booking_teachers} AS ba WHERE ba.optionid = bo.id AND ba.userid = :userid3) AS isteacher";
        $from = '{booking} AS b '
                . 'LEFT JOIN {booking_options} AS bo ON bo.bookingid = b.id';
        $where = "b.id = :bookingid " . (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions));

        $conditionsParams['userid'] = $USER->id;
        $conditionsParams['userid1'] = $USER->id;
        $conditionsParams['userid2'] = $USER->id;
        $conditionsParams['userid3'] = $USER->id;
        $conditionsParams['bookingid'] = $booking->booking->id;

        $tableAllOtions->set_sql(
                $fields, $from, $where, $conditionsParams);

        $tableAllOtions->define_columns($columns);
        $tableAllOtions->define_headers($headers);

        $pagging = $booking->booking->paginationnum;
        if ($pagging == 0) {
            $pagging = 25;
        }
        $tableAllOtions->out($pagging, true);
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
        if ($userprofilefields = $DB->get_records_select('user_info_field', 'id > 0 AND shortname IN (' . $addquoted . ')', array(), 'id', 'id, shortname, name')) {
            foreach ($userprofilefields as $profilefield) {
                $columns[] = "cust" . strtolower($profilefield->shortname);
                $headers[] = $profilefield->name;
                $customfields .= ", (SELECT concat(uif.datatype,'|',uid.data) as custom FROM {user_info_data} AS uid LEFT JOIN {user_info_field} AS uif ON uid.fieldid = uif.id WHERE userid = tba.userid AND uif.shortname = '{$profilefield->shortname}') AS cust" . strtolower($profilefield->shortname);
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
                BINARY( SELECT 
                        GROUP_CONCAT(obo.text
                                SEPARATOR ', ') AS otheroptions
                    FROM
                        {booking_answers} AS oba
                            LEFT JOIN
                        {booking_options} AS obo ON obo.id = oba.optionid
                    WHERE
                        oba.frombookingid = tba.optionid
                            AND oba.userid = tba.userid) AS otheroptions,
                (SELECT 
                        GROUP_CONCAT(g.name
                                SEPARATOR ', ') AS groups
                    FROM
                        {groups_members} AS gm
                            LEFT JOIN
                        {groups} AS g ON g.id = gm.groupid
                    WHERE
                        gm.userid = tu.id AND g.courseid = :tcourseid) AS groups,
                tba.numrec,
                        tba.waitinglist AS waitinglist {$customfields}";
        $from = '{booking_answers} AS tba JOIN {user} AS tu ON tu.id = tba.userid JOIN {booking_options} AS tbo ON tbo.id = tba.optionid';
        $where = 'tba.optionid IN (SELECT DISTINCT bo.id FROM {booking} AS b LEFT JOIN {booking_options} AS bo ON bo.bookingid = b.id WHERE b.id = :bookingid ' . (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions)) . ')';

        $conditionsParams['userid'] = $USER->id;
        $conditionsParams['userid1'] = $USER->id;
        $conditionsParams['userid2'] = $USER->id;
        $conditionsParams['userid3'] = $USER->id;
        $conditionsParams['bookingid'] = $booking->booking->id;
        $conditionsParams['tcourseid'] = $course->id;

        $tableAllOtions->set_sql(
                $fields, $from, $where, $conditionsParams);

        $tableAllOtions->define_columns($columns);
        $tableAllOtions->define_headers($headers);

        $tableAllOtions->out(10, true);
        exit;
    }
} else {
    echo $OUTPUT->box(get_string("norighttobook", "booking"));
}

echo $OUTPUT->box("<a href=\"http://www.edulabs.org\">" . get_string('createdby', 'booking') . "</a>", 'box mdl-align');
echo $OUTPUT->footer();
?>

<script type="text/javascript">
    YUI().use('node-event-simulate', function (Y) {

        Y.one('#buttonclear').on('click', function () {
            Y.one('#searchText').set('value', '');
            Y.one('#searchLocation').set('value', '');
            Y.one('#searchInstitution').set('value', '');
            Y.one('#searchName').set('value', '');
            Y.one('#searchSurname').set('value', '');
            Y.one('#searchButton').simulate('click');
        });
    });

    YUI().use('node', function (Y) {
        Y.delegate('click', function (e) {
            var buttonID = e.currentTarget.get('id'),
                    node = Y.one('#tableSearch');

            if (buttonID === 'showHideSearch') {
                node.toggleView();
                location.hash = "#goenrol";
                e.preventDefault();
            }

        }, document, 'a');
    });
</script>
