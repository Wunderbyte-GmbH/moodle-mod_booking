<?php
require_once("../../config.php");
require_once("lib.php");
require_once("categoriesform.class.php");

$courseid         = required_param('courseid', PARAM_INT);    

$url = new moodle_url('/mod/booking/categories.php', array('courseid' => $courseid));
$PAGE->set_url($url);

$context = context_course::instance($courseid);

if (! $course = $DB->get_record("course", array("id" => $courseid))) {
	print_error('coursemisconf');
}

require_login($courseid, false);

$PAGE->set_pagelayout('standard');

$title = get_string('category', 'booking');

$PAGE->navbar->add(get_string('addcategory', 'booking'));
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($title);

$categories = $DB->get_records('booking_category', array('course' => $courseid, 'cid' => 0));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('categories', 'booking') . ' ' . get_string('forcourse', 'booking') . ' ' . $COURSE->fullname, 2);

$message = "<a href=\"categoryadd.php?courseid=$courseid\">".get_string('addnewcategory','booking')."</a>";
echo $OUTPUT->box($message,'box mdl-align');

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); //could use an id separate from tag-blogs, but would have to copy the css style to make it look the same

echo "<ul>";

foreach ($categories as $category) {
	echo "<li>$category->name</li>";
	$subcategories = $DB->get_records('booking_category', array('course' => $courseid, 'cid' => $category->id));
	if (count((array)$subcategories < 0)) {
		echo "<ul>";
		foreach ($subcategories as $subcat) {
			echo "<li>$subcat->name</li>";
		}
		echo "</ul>";
	}
}

echo "</ul>";

echo $OUTPUT->box_end();

echo $OUTPUT->footer();

?>