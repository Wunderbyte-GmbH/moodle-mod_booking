<?php 

require_once("../../config.php");
require_once("lib.php");

$id         = required_param('id', PARAM_INT);
$categoryid = optional_param('category', '',PARAM_INT);

$url = new moodle_url('/mod/booking/category.php', array('id' => $id, 'category'=>$categoryid));

$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('booking', $id)) {
	print_error('invalidcoursemodule');
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
	print_error('coursemisconf');
}

require_course_login($course, false, $cm);

$category = $DB->get_record('booking_category', array('id' => $categoryid));
$PAGE->set_pagelayout('standard');

$PAGE->navbar->add($category->name);
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($category->name);

echo $OUTPUT->header();

echo $OUTPUT->heading($category->name, 2);

$records = $DB->get_records('booking', array('categoryid' => $category->id));

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); //could use an id separate from tag-blogs, but would have to copy the css style to make it look the same

echo '<ul>';

foreach ($records as $record) {
	$booking = $DB->get_record('booking', array('id' => $record->id, 'course' => $cm->course));
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