<?php

/**
 * Import options or just add new users from CSV
 *
 * @package Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once ("../../config.php");
require_once ("locallib.php");

$id = required_param('cmid', PARAM_INT); // Course Module ID

$url = new moodle_url('/mod/booking/tagtemplates.php', array('cmid' => $id));
$urlRedirect = new moodle_url('/mod/booking/view.php', array('id' => $id));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("tagtemplates", "booking"));
$PAGE->set_title(format_string(get_string("tagtemplates", "booking")));
$PAGE->set_heading(get_string("tagtemplates", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("tagtemplates", "booking"), 3, 'helptitle', 'uniqueid');

$table = new html_table();
$table->head = array(get_string('tagtag', 'booking'), get_string('tagtext', 'booking'));

$tags = new booking_tags($cm);

$tagsTable = array();

foreach ($tags->get_all_tags() as $tag) {
    
    $edit = new moodle_url('tagtemplatesadd.php', array('cmid' => $cm->id, 'tid' => $tag->id));
    $button = $OUTPUT->single_button($edit, get_string('edittag', 'booking'), 'get');
    
    $tagsTable[] = array("[{$tag->tag}]", nl2br($tag->text), 
        html_writer::tag('span', $button, 
                array('style' => 'text-align: right; display:table-cell;')));
}

$table->data = $tagsTable;
echo html_writer::table($table);

$cancel = new moodle_url('view.php', array('id' => $cm->id));
$addnew = new moodle_url('tagtemplatesadd.php', array('cmid' => $cm->id));

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('cancel', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: right; display:table-cell;'));
$button = $OUTPUT->single_button($addnew, get_string('addnewtagtemplate', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: left; display:table-cell;'));
echo '</div>';

echo $OUTPUT->footer();
