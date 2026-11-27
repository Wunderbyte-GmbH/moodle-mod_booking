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
 * Add category
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");
require_once("categoriesform.class.php");

$courseid = required_param('courseid', PARAM_INT);
$cid = optional_param('cid', '', PARAM_INT);
$delete = optional_param('delete', '', PARAM_INT);

if ($cid != '') {
    $url = new moodle_url(
        '/mod/booking/categoryadd.php',
        ['courseid' => $courseid, 'cid' => $cid]
    );
} else {
    $url = new moodle_url('/mod/booking/categoryadd.php', ['courseid' => $courseid]);
}

$PAGE->set_url($url);

$context = context_course::instance($courseid);

if (!$course = $DB->get_record("course", ["id" => $courseid])) {
    throw new moodle_exception('coursemisconf');
}

$PAGE->navbar->add(get_string('addnewcategory', 'booking'));

require_login($courseid, false);

require_capability('mod/booking:addinstance', $context);

$PAGE->set_pagelayout('standard');

$redirecturl = new moodle_url('/mod/booking/categories.php', ['courseid' => $courseid]);

if ($delete == 1) {
    // Deleting changes data, so the request has to come from a page of this session.
    require_sesskey();
    $delmessage = \mod_booking\local\booking_categories::delete((int)$courseid, (int)$cid);
    redirect($redirecturl, $delmessage, 5);
}

$mform = new mod_booking_categories_form(null, ['courseid' => $courseid, 'cidd' => $cid]);

$defaultvalues = new stdClass();
if ($cid != '') {
    // Only a category of the course the rights were checked for can be edited.
    $defaultvalues = $DB->get_record('booking_category', ['id' => $cid, 'course' => $courseid], '*', MUST_EXIST);
}

$defaultvalues->courseid = $courseid;
$defaultvalues->course = $courseid;

$PAGE->set_title(get_string('addnewcategory', 'booking'));

if ($mform->is_cancelled()) {
    redirect($redirecturl, '', 0);
} else if ($data = $mform->get_data(true)) {
    \mod_booking\local\booking_categories::save((int)$courseid, (int)$cid, $data);
    redirect($redirecturl, '', 0);
}

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

$mform->set_data($defaultvalues);
$mform->display();

echo $OUTPUT->footer();
