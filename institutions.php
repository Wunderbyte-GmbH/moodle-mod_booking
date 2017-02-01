<?php
require_once("../../config.php");
require_once("lib.php");

$courseid = required_param('courseid', PARAM_INT);

$url = new moodle_url('/mod/booking/institutions.php', array('courseid' => $courseid));
$PAGE->set_url($url);

$context = context_course::instance($courseid);

if (!$course = $DB->get_record("course", array("id" => $courseid))) {
    print_error('coursemisconf');
}

require_login($courseid, false);

$PAGE->set_pagelayout('standard');

$title = get_string('institutions', 'booking');

$PAGE->navbar->add(get_string('addnewinstitution', 'booking'));
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($title);

$institutions = $DB->get_records('booking_institutions', array('course' => $courseid));

echo $OUTPUT->header();

echo $OUTPUT->heading(
        get_string('institutions', 'booking') . ' ' . get_string('forcourse', 'booking') . ' ' .
                 $COURSE->fullname, 2);

$message = "<a href=\"institutionadd.php?courseid=$courseid\">" .
         get_string('addnewinstitution', 'booking') . "</a>";
$import = "<a href=\"institutioncsv.php?courseid=$courseid\">" .
         get_string('importcsvtitle', 'booking') . "</a>";
echo $OUTPUT->box("{$message} | {$import}", 'box mdl-align');

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); // could use an id separate from tag-blogs, but would have to copy the css style to make it look
                                                    // the same

echo "<ul>";

foreach ($institutions as $institution) {
    $editlink = "<a href=\"institutionadd.php?courseid=$courseid&cid=$institution->id\">" .
             get_string('editcategory', 'booking') . '</a>';
    $deletelink = "<a href=\"institutionadd.php?courseid=$courseid&cid=$institution->id&delete=1\">" .
             get_string('deletecategory', 'booking') . '</a>';
    echo "<li>$institution->name - $editlink - $deletelink</li>";
}

echo "</ul>";

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
?>