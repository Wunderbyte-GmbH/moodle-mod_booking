<?php
require_once("../../config.php");
require_once("locallib.php");
require_once($CFG->libdir . '/completionlib.php');
require_once("{$CFG->libdir}/tablelib.php");
require_once("{$CFG->dirroot}/mod/booking/classes/all_options.php");

$id = required_param('id', PARAM_INT);                 // Course Module ID
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

$urlParams = array();
$urlParamsSort = array();
$urlParams['id'] = $id;

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
}

$urlParams['searchLocation'] = "";
if (strlen($searchLocation) > 0) {
    $urlParams['searchLocation'] = $searchLocation;
}

$urlParams['searchInstitution'] = "";
if (strlen($searchInstitution) > 0) {
    $urlParams['searchInstitution'] = $searchInstitution;
}

$urlParams['searchName'] = "";
if (strlen($searchName) > 0) {
    $urlParams['searchName'] = $searchName;
}

$urlParams['searchSurname'] = "";
if (strlen($searchSurname) > 0) {
    $urlParams['searchSurname'] = $searchSurname;
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

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    print_error('invalidcoursemodule');
}

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);

$booking = new booking_options($cm->id, TRUE, $urlParams, $page, $perPage);
$booking->apply_tags();
$booking->get_url_params();

$strbooking = get_string('modulename', 'booking');
$strbookings = get_string('modulenameplural', 'booking');

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

// before processing data user has to agree to booking policy and confirm booking
if ($form = data_submitted() && has_capability('mod/booking:choose', $context) && confirm_sesskey() && $confirm != 1 && $answer) {
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

echo $OUTPUT->header();

// check if custom user profile fields are required and redirect to complete them if necessary
if (has_capability('moodle/user:editownprofile', $context, NULL, false) and booking_check_user_profile_fields($USER->id) and ! has_capability('moodle/site:config', $context)) {
    $contents = get_string('mustfilloutuserinfobeforebooking', 'booking');
    $contents .= $OUTPUT->single_button(new moodle_url("edituserprofile.php", array('cmid' => $cm->id, 'courseid' => $course->id)), get_string('continue'), 'get');
    echo $OUTPUT->box($contents, 'box generalbox', 'notice');
    echo $OUTPUT->footer();
    die;
}

/// Submit any new data if there is any
if ($form = data_submitted() && has_capability('mod/booking:choose', $context)) {
    $timenow = time();

    $url = new moodle_url("view.php", array('id' => $cm->id));
    $url->set_anchor("option" . $booking->options[$answer]->id);


    if (!empty($answer)) {
        $bookingData = new booking_option($cm->id, $answer);
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
            $contents = get_string('bookingmeanwhilefull', 'booking') . " " . $booking->option[$answer]->text;
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
// we have to refresh $booking as it is modified by submitted data;
$booking = new booking_options($cm->id, TRUE, $urlParams, $page, $perPage);
$booking->apply_tags();
$booking->get_url_params();

$event = \mod_booking\event\course_module_viewed::create(array(
            'objectid' => $PAGE->cm->instance,
            'context' => $PAGE->context,
        ));
$event->add_record_snapshot('course', $PAGE->course);
$event->trigger();

/// Display the booking and possibly results

$bookinglist = $booking->allbookedusers;

echo '<div class="clearer"></div>';

echo $html = html_writer::tag('div', '<a id="gotop" href="#goenrol">' . get_string('goenrol', 'booking') . '</a>', array('style' => 'width:100%; font-weight: bold; text-align: right;'));
echo html_writer::tag('div', format_module_intro('booking', $booking->booking, $cm->id), array('class' => 'intro'));

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

echo $html = html_writer::tag('div', '<a id="goenrol" href="#gotop">' . get_string('gotop', 'booking') . '</a>', array('style' => 'width:100%; font-weight: bold; text-align: right;'));

$output = $PAGE->get_renderer('mod_booking');
$output->print_booking_tabs($urlParams, $whichview);


/*
  //download spreadsheet of all users
  if (has_capability('mod/booking:downloadresponses', $context)) {
  /// Download spreadsheet for all booking options
  echo $html = html_writer::tag('div', get_string('downloadallresponses', 'booking') . ': ', array('style' => 'width:100%; font-weight: bold; text-align: right;'));
  $optionstochoose = array('all' => get_string('allbookingoptions', 'booking'));
  if (isset($booking->options)) {
  foreach ($booking->options as $option) {
  $optionstochoose[$option->id] = $option->text;
  }
  }
  $options = $urlParams;
  $options["id"] = "$cm->id";
  $options["optionid"] = 0;
  $options["download"] = "ods";
  $options['action'] = "all";
  $button = $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadods"));
  echo '<div style="width: 100%; text-align: right; display:table;">';
  echo html_writer::tag('span', $button, array('style' => 'width: 100%; text-align: right; display:table-cell;'));
  $options["download"] = "xls";
  $button = $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadexcel"));
  echo html_writer::tag('span', $button, array('style' => 'text-align: right; display:table-cell;'));
  echo '</div>';
  }
 * 
 */

$current = false;  // Initialise for later
//if user has already made a selection, show their selected answer.
/// Print the form
$bookingopen = true;
$timenow = time();
if ($booking->booking->timeclose != 0) {
    if ($booking->booking->timeopen > $timenow && !has_capability('mod/booking:updatebooking', $context)) {
        echo $OUTPUT->box(get_string("notopenyet", "booking", userdate($booking->booking->timeopen, get_string('strftimedate'))), "center");
        echo $OUTPUT->footer();
        exit;
    } else if ($booking->booking->timeclose && !has_capability('mod/booking:updatebooking', $context)) {
        echo $OUTPUT->box(get_string("expired", "booking", userdate($booking->booking->timeclose)), "center");
        $bookingopen = false;
    }
}

if (!$current and $bookingopen and has_capability('mod/booking:choose', $context)) {

    echo $OUTPUT->box(booking_show_maxperuser($booking, $USER, $bookinglist), 'box mdl-align');

    $search = '<a href="#" id="showHideSearch">' . get_string('search') . "</a>";

    if ($whichview != 'showonlyone') {
        echo $OUTPUT->box("{$search}", 'box mdl-align');
    }

    $sortUrl->set_anchor('goenrol');

    $tableAllOtions = new all_options('mod_booking_all_options', $booking);
    $tableAllOtions->is_downloading($download, $booking->booking->name, $booking->booking->name);

    $tableAllOtions->define_baseurl($url);
    $tableAllOtions->defaultdownloadformat = 'ods';
    $tableAllOtions->is_downloadable(true);
    $tableAllOtions->show_download_buttons_at(array(TABLE_P_BOTTOM));

    $columns = array();
    $headers = array();

    if (!$tableAllOtions->is_downloading()) {
        $columns[] = 'id';
        $headers[] = get_string("select", "booking");
        $columns[] = 'coursestarttime';
        $headers[] = get_string("coursedate", "booking");
        $columns[] = 'address';
        $headers[] = get_string("availability", "booking");

        $fields = 'bo.id, bo.text, bo.address, bo.coursestarttime, bo.courseendtime';
        $from = '{booking} AS b LEFT JOIN {booking_options} AS bo ON bo.bookingid = b.id';
        $where = "b.id = :bookingid";

        $tableAllOtions->set_sql(
                $fields, $from, $where, array('bookingid' => $booking->booking->id));

        $tableAllOtions->define_columns($columns);
        $tableAllOtions->define_headers($headers);
        
        $tableAllOtions->out($booking->booking->paginationnum, true);
    }


    booking_show_form($booking, $USER, $cm, $bookinglist, $sortUrl, $urlParams);
    echo $OUTPUT->paging_bar($booking->count(), $page, $perPage, $url);
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