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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\output\page_allteachers;

require_once(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

// Check if login is required.
if (empty(get_config('booking', 'teachersnologinrequired'))) {
    require_login(0, false);
}

global $DB, $PAGE, $OUTPUT, $USER;

if (!$context = context_system::instance()) {
    throw new moodle_exception('badcontext');
}

// Check if optionid is valid.
$PAGE->set_context($context);

$title = get_string('allteachers', 'mod_booking');

$PAGE->set_url('/mod/booking/teachers.php');
$PAGE->navbar->add($title);
$PAGE->set_title(format_string($title));
$PAGE->set_heading($title);
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('page-mod-booking-allteachers');

echo $OUTPUT->header();

$teacherids = [];

// Now get all teachers that we're interested in.
$sqlteachers =
    "SELECT DISTINCT bt.userid, u.firstname, u.lastname, u.email
    FROM {booking_teachers} bt
    LEFT JOIN {user} u
    ON u.id = bt.userid
    ORDER BY u.lastname ASC";

if ($teacherrecords = $DB->get_records_sql($sqlteachers)) {
    foreach ($teacherrecords as $teacherrecord) {
        $teacherids[] = $teacherrecord->userid;
    }
}

// Now prepare the data for all teachers.
$data = new page_allteachers($teacherids);
/** @var \mod_booking\output\renderer $output */
$output = $PAGE->get_renderer('mod_booking');

// And return the rendered page showing all teachers.
echo $output->render_allteacherspage($data);

echo $OUTPUT->footer();
