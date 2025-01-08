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
 * Index page
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");

$id = required_param('id', PARAM_INT); // Course id.

$PAGE->set_url('/mod/booking/index.php', ['id' => $id]);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_login($course);
$PAGE->set_pagelayout('incourse');

$strbookings = get_string("modulenameplural", "booking");
$PAGE->set_title($strbookings);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($strbookings);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($strbookings));

$context = context_course::instance($course->id);
require_capability('mod/booking:choose', $context);

if (!$bookings = get_coursemodules_in_course('booking', $course->id)) {
    notice(get_string('thereareno', 'moodle', $strbookings), "../../course/view.php?id=$course->id");
}

$strsectionname = '';
$usesections = course_format_uses_sections($course->format);
$modinfo = get_fast_modinfo($course);

if ($usesections) {
    $strsectionname = get_string('sectionname', 'format_'.$course->format);
    $sections = $modinfo->get_section_info_all();
}

$table = new html_table();

$currentsection = "";
$sectionname = '';
foreach ($modinfo->instances['booking'] as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $printsection = '';
    $previoussectionname = $sectionname;
    if ($usesections && $cm->sectionnum >= 0) {
        $sectionname = get_section_name($course, $sections[$cm->sectionnum]);
    }
    if ($previoussectionname != $sectionname) {
        $printsection = $sectionname;
        $table->data[] = [$printsection, ''];
    }

    $context = context_module::instance($cm->id);
    $booking = singleton_service::get_instance_of_booking_by_cmid((int)$cm->id);
    $bo = $booking->get_user_booking($USER);

    $numberofbookings = '';
    if (!empty($bo)) {
        $numberofbookings = '<ul>';
        foreach ($bo as $b) {
            $numberofbookings .= "<li>{$b->text}</li>";
        }
        $numberofbookings .= '</ul>';
    }

    // Calculate the href.
    if (!$cm->visible) {
        // Show dimmed if the mod is hidden.
        $tthref = "<a class=\"dimmed\" href=\"view.php?id=$cm->id\">" .
                 format_string($cm->name, true) . "</a>";
    } else {
        // Show normal if the mod is visible.
        $tthref = "<a href=\"view.php?id=$cm->id\">" . format_string($cm->name, true) . "</a>";
    }

    $table->data[] = [$tthref, $numberofbookings];
}
echo "<br />";
echo html_writer::table($table);

echo $OUTPUT->footer();
