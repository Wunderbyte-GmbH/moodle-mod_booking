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
 * Global settings
 *
 * @package mod_booking
 * @copyright 2019 Andraž Prinčič, www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use mod_booking\customreporttemplates_table;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$templateid = optional_param('templateid', 0, PARAM_INT);
$action = optional_param('action', 0, PARAM_ALPHANUM);
list($course, $cm) = get_course_and_cm_from_cmid($id);

// No guest autologin.
require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$pageurl = new moodle_url('/mod/booking/customreporttemplates.php',  ['id' => $id]);

if (($action === 'delete') && ($templateid > 0)) {
    $DB->delete_records('booking_customreport', ['id' => $templateid]);
    redirect($pageurl, get_string('templatedeleted', 'booking'), 5);
}

$table = new customreporttemplates_table('customreporttemplates', $id);
$table->set_sql('id, name', "{booking_customreport}", "course = $course->id");

$table->define_baseurl($pageurl);

$PAGE->set_url($pageurl);
$PAGE->set_title(
        format_string($SITE->shortname) . ': ' . get_string('customreporttemplates', 'booking'));
$PAGE->navbar->add(get_string('customreporttemplates', 'booking'), $pageurl);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('customreporttemplates', 'booking'));

$table->out(25, true);

$cancel = new moodle_url('/mod/booking/view.php', ['id' => $cm->id]);
$addnew = new moodle_url('/mod/booking/customreporttemplatesadd.php', ['id' => $cm->id]);

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('cancel', 'core'), 'get');
echo html_writer::tag('span', $button, ['style' => 'text-align: right; display:table-cell;']);
$button = $OUTPUT->single_button($addnew, get_string('addnewreporttemplate', 'booking'), 'get');
echo html_writer::tag('span', $button, ['style' => 'text-align: left; display:table-cell;']);
echo '</div>';

echo $OUTPUT->footer();
