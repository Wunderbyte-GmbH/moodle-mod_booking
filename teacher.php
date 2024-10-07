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
 * Add dates to option.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2022 Bernhard Fischer, Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

// Check if login is required.
if (empty(get_config('booking', 'teachersnologinrequired'))) {
    require_login(0, false);
}

use mod_booking\output\page_teacher;

global $DB, $PAGE, $OUTPUT, $USER;

if (!$context = context_system::instance()) {
    throw new moodle_exception('badcontext');
}

$teacherid = required_param('teacherid', PARAM_INT);

// Check if optionid is valid.
$PAGE->set_context($context);
$PAGE->set_url('/mod/booking/teacher.php?teacherid=' . $teacherid);

$title = get_string('teacher', 'mod_booking');

$PAGE->navbar->add($title);
$PAGE->set_title(format_string($title));
$PAGE->set_heading($title);
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('page-mod-booking-teacher');

echo $OUTPUT->header();

echo '<a href="' . $CFG->wwwroot . '/mod/booking/teachers.php" target="_self"><h5>' .
    get_string('showallteachers', 'mod_booking') . '</h5></a>';

$data = new page_teacher($teacherid);
$output = $PAGE->get_renderer('mod_booking');
echo $output->render_teacherpage($data);

echo $OUTPUT->footer();
