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
    $url = new moodle_url('/mod/booking/categoryadd.php',
            ['courseid' => $courseid, 'cid' => $cid]);
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
    $candelete = true;

    $categories = $DB->get_records("booking_category", ["cid" => $cid]);
    if (count((array) $categories) > 0) {
        $candelete = false;
        $delmessage = get_string('deletesubcategory', 'booking');
    }

    $bookings = $DB->get_records("booking", ["categoryid" => $cid]);
    if (count((array) $bookings) > 0) {
        $candelete = false;
        $delmessage = get_string('usedinbooking', 'booking');
    }

    if ($candelete) {
        $DB->delete_records("booking_category", ["id" => $cid]);
        $delmessage = get_string('successfulldeleted', 'booking');
    }
    redirect($redirecturl, $delmessage, 5);
}

$mform = new mod_booking_categories_form(null, ['courseid' => $courseid, 'cidd' => $cid]);

$defaultvalues = new stdClass();
if ($cid != '') {
    $defaultvalues = $DB->get_record('booking_category', ['id' => $cid]);
}

$defaultvalues->courseid = $courseid;
$defaultvalues->course = $courseid;

$PAGE->set_title(get_string('addnewcategory', 'booking'));

if ($mform->is_cancelled()) {
    redirect($redirecturl, '', 0);
} else if ($data = $mform->get_data(true)) {
    $category = new stdClass();
    $category->id = $data->id;
    $category->name = $data->name;
    if ($cid == $data->id) {
        $category->cid = 0;
    } else {
        $category->cid = $data->cid;
    }
    $category->course = $data->course;

    if ($category->id != '') {
        $DB->update_record("booking_category", $category);
    } else {
        $DB->insert_record("booking_category", $category);
    }
    redirect($redirecturl, '', 0);
}

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

$mform->set_data($defaultvalues);
$mform->display();

echo $OUTPUT->footer();
