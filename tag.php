<?php 

require_once("../../config.php");
require_once("lib.php");
require_once($CFG->dirroot.'/tag/lib.php');
require_once($CFG->dirroot.'/tag/locallib.php');

$id         = required_param('id', PARAM_INT);
$tagname = optional_param('tag', '',PARAM_TAG);

$url = new moodle_url('/mod/booking/tag.php', array('id' => $id, 'tag'=>$tagname));

$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('booking', $id)) {
	print_error('invalidcoursemodule');
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}

require_course_login($course, false, $cm);

$tag = tag_get('name', $tagname, '*');
$PAGE->set_pagelayout('standard');

$tagname = tag_display_name($tag);
$title = get_string('tag', 'tag') .' - '. $tagname;

$PAGE->navbar->add($tagname);
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($title);

echo $OUTPUT->header();

echo $OUTPUT->heading($tagname, 2);

echo "opa bato";

echo $OUTPUT->footer();

?>