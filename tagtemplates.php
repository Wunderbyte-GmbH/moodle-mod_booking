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

/**
 * Import options or just add new users from CSV
 *
 * @package mod_booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_tags;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$tagid = optional_param('tagid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUM);

$url = new moodle_url('/mod/booking/tagtemplates.php', ['id' => $id]);
$urlredirect = new moodle_url('/mod/booking/view.php', ['id' => $id]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$groupmode = groups_get_activity_groupmode($cm);

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

if (($action === 'delete') && ($tagid > 0)) {
    $DB->delete_records('booking_tags', ['id' => $tagid]);
    redirect($url, get_string('tagdeleted', 'booking'), 5);
}

$PAGE->navbar->add(get_string("tagtemplates", "booking"));
$PAGE->set_title(format_string(get_string("tagtemplates", "booking")));
$PAGE->set_heading(get_string("tagtemplates", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("tagtemplates", "booking"), 3, 'helptitle', 'uniqueid');

$table = new html_table();
$table->head = [get_string('tagtag', 'booking'), get_string('tagtext', 'booking'), ''];

$tags = new booking_tags($cm->course);

$tagstable = [];

foreach ($tags->get_all_tags() as $tag) {

    $edit = new moodle_url('/mod/booking/tagtemplatesadd.php', ['id' => $cm->id, 'tagid' => $tag->id]);
    $delete = new moodle_url('/mod/booking/tagtemplates.php', ['id' => $id, 'tagid' => $tag->id, 'action' => 'delete']);
    $button = $OUTPUT->single_button($edit, get_string('edittag', 'booking'), 'get') .
        $OUTPUT->single_button($delete, get_string('delete'), 'get');

    $tagstable[] = ["[{$tag->tag}]", nl2br($tag->text),
                    html_writer::tag('span', $button, ['style' => 'text-align: right; display:table-cell;']),
                ];
}

$table->data = $tagstable;
echo html_writer::table($table);

$cancel = new moodle_url('/mod/booking/view.php', ['id' => $cm->id]);
$addnew = new moodle_url('/mod/booking/tagtemplatesadd.php', ['id' => $cm->id]);

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('cancel', 'core'), 'get');
echo html_writer::tag('span', $button, ['style' => 'text-align: right; display:table-cell;']);
$button = $OUTPUT->single_button($addnew, get_string('addnewtagtemplate', 'booking'), 'get');
echo html_writer::tag('span', $button, ['style' => 'text-align: left; display:table-cell;']);
echo '</div>';

echo $OUTPUT->footer();
