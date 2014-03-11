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

$records = $DB->get_records('tag_instance', array('tagid' => $tag->id, 'itemtype' => 'booking'));

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); //could use an id separate from tag-blogs, but would have to copy the css style to make it look the same

echo '<ul>';

foreach ($records as $record) {
	$booking = $DB->get_record('booking', array('id' => $record->itemid, 'course' => $cm->course));
	if ($booking) {
		$cmc = get_coursemodule_from_instance('booking', $booking->id);
		$url = new moodle_url('/mod/booking/view.php', array('id' => $cmc->id));
		echo '<li><a href="' . $url . '">' . $booking->name . '</a></li>';
	}
}
echo '</ul>';

echo $OUTPUT->box_end();

echo $OUTPUT->footer();

?>