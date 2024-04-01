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
 * @copyright 2019 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

use mod_booking\form\customreporttemplatesadd_form;

$id = required_param('id', PARAM_INT); // Course Module ID.
$templateid = optional_param('templateid', '', PARAM_INT);

$url = new moodle_url('/mod/booking/customreporttemplatesadd.php', ['id' => $id, 'templateid' => $templateid]);
$urlredirect = new moodle_url('/mod/booking/customreporttemplates.php', ['id' => $id]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

if (!$coursecontext = context_course::instance($course->id)) {
    throw new moodle_exception('badcontext');
}


require_capability('mod/booking:manageoptiontemplates', $context);

$PAGE->navbar->add(get_string("addnewreporttemplate", "booking"));
$PAGE->set_title(format_string(get_string("addnewreporttemplate", "booking")));
$PAGE->set_heading(get_string("addnewreporttemplate", "booking"));

$mform = new customreporttemplatesadd_form($url);

if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($urlredirect, '', 0);
    die();
} else if ($data = $mform->get_data()) {

    // Add new record.
    $template = new stdClass();
    $template->course = $cm->course;
    $template->name = $data->name;

    $entryid = $DB->insert_record("booking_customreport", $template);

    file_save_draft_area_files($data->templatefile, $coursecontext->id, 'mod_booking', 'templatefile',
        $entryid, ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1]);

    redirect($urlredirect, get_string('templatesuccessfullysaved', 'booking'), 5);
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("addnewreporttemplate", "booking"), 3, 'helptitle', 'uniqueid');

    $defaultvalues = new stdClass();

    // Processed if form is submitted but data not validated & form should be redisplayed OR first display of form.
    $mform->set_data($defaultvalues);
    $mform->display();
}

echo $OUTPUT->footer();
