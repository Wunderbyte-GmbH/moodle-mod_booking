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
if (!defined('AJAX_SCRIPT')) {
    define('AJAX_SCRIPT', true);
}

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT); // Course Module ID.

list($course, $cm) = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

echo $OUTPUT->header();

$institutions = $DB->get_records('booking_institutions', array('course' => $COURSE->id));
$instnames = array();
foreach ($institutions as $id => $inst) {
    $instnames[] = $inst->name;
}

echo json_encode($instnames);