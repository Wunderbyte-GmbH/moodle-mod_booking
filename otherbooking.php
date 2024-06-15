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
 * @copyright 2014 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);

$url = new moodle_url('/mod/booking/otherbooking.php', ['id' => $id, 'optionid' => $optionid]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$option = singleton_service::get_instance_of_booking_option($id, $optionid);

$PAGE->navbar->add(get_string("editotherbooking", "booking"));
$PAGE->set_title(format_string(get_string("editotherbooking", "booking")));
$PAGE->set_heading(get_string("editotherbooking", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("editotherbooking", "booking") . " [{$option->option->text}]", 3,
        'helptitle', 'uniqueid');

echo html_writer::link(
        new moodle_url('/mod/booking/report.php', ['id' => $cm->id, 'optionid' => $optionid]),
        get_string('gotomanageresponses', 'booking'), ['style' => 'float:right;']);
echo '<br>';

$table = new html_table();
$table->head = [
    (empty($option->booking->settings->lblacceptingfrom) ? get_string('otherbookingoptions', 'booking') :
        $option->booking->settings->lblacceptingfrom),
    (empty($option->booking->settings->lblnumofusers) ? get_string('otherbookingnumber', 'booking') :
        $option->booking->settings->lblnumofusers),
];

$rules = $DB->get_records_sql(
        "SELECT bo.id, bo.otheroptionid, bo.userslimit, b.text
        FROM {booking_other} bo
        LEFT JOIN {booking_options} b ON b.id = bo.otheroptionid
        WHERE bo.optionid = ?", [$optionid]);

$rulestable = [];

foreach ($rules as $rule) {

    $edit = new moodle_url('/mod/booking/otherbookingaddrule.php',
            ['id' => $cm->id, 'optionid' => $optionid, 'bookingotherid' => $rule->id]);
    $delete = new moodle_url('/mod/booking/otherbookingaddrule.php',
            ['id' => $cm->id, 'optionid' => $optionid, 'bookingotherid' => $rule->id, 'delete' => 1]);

    $button = '<div style="width: 100%; text-align: right; display:table;">';
    $buttone = $OUTPUT->single_button($edit, get_string('editrule', 'booking'), 'get');
    $button .= html_writer::tag('span', $buttone,
            ['style' => 'text-align: right; display:table-cell;']);
    $buttond = $OUTPUT->single_button($delete, get_string('deleterule', 'booking'), 'get');
    $button .= html_writer::tag('span', $buttond,
            ['style' => 'text-align: left; display:table-cell;']);
    $button .= '</div>';

    $rulestable[] = ["{$rule->text}", $rule->userslimit,
                html_writer::tag('span', $button,
                ['style' => 'text-align: right; display:table-cell;']),
        ];
}

$table->data = $rulestable;
echo html_writer::table($table);

$cancel = new moodle_url('/mod/booking/report.php', ['id' => $cm->id, 'optionid' => $optionid]);
$addnew = new moodle_url('/mod/booking/otherbookingaddrule.php', ['id' => $cm->id, 'optionid' => $optionid]);

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('cancel', 'core'), 'get');
echo html_writer::tag('span', $button, ['style' => 'text-align: right; display:table-cell;']);
$button = $OUTPUT->single_button($addnew, get_string('otherbookingaddrule', 'booking'), 'get');
echo html_writer::tag('span', $button, ['style' => 'text-align: left; display:table-cell;']);
echo '</div>';

echo $OUTPUT->footer();
