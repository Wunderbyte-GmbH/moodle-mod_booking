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
 * Handling editing of option tepmplates
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\form\option_form;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_INT);

$url = new moodle_url('/mod/booking/edit_optiontemplate.php', ['optionid' => $optionid, 'id' => $id]);
$redirecturl = new moodle_url('/mod/booking/optiontemplatessettings.php', ['optionid' => $optionid, 'id' => $id]);
$PAGE->set_url($url);
$PAGE->requires->jquery_plugin('ui-css');
list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

if (!$booking = singleton_service::get_instance_of_booking_by_cmid($cm->id)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

if (!has_capability('mod/booking:manageoptiontemplates', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'manage booking option templates');
}

$customdata = ['id' => $optionid, 'bookingid' => 0, 'optionid' => $optionid, 'cmid' => $cm->id, 'context' => $context];
$mform = new option_form(null, $customdata);

if ($defaultvalues = $DB->get_record('booking_options', ['bookingid' => 0, 'id' => $optionid])) {
    $defaultvalues->optionid = $optionid;
    $defaultvalues->bookingid = 0;
    $defaultvalues->bookingname = $booking->settings->name;
    $defaultvalues->id = $cm->id;
} else {
    throw new moodle_exception('This booking template does not exist');
}

if ($mform->is_cancelled()) {
    redirect($redirecturl, '', 0);
} else if ($fromform = $mform->get_data()) {
    // Validated data.
    if (confirm_sesskey() &&
            (has_capability('mod/booking:updatebooking', $context) ||
            has_capability('mod/booking:addeditownoption', $context))) {
        if (!isset($fromform->limitanswers)) {
            $fromform->limitanswers = 0;
        }

        $nbooking = booking_option::update($fromform, $context);

        if ($draftitemid = file_get_submitted_draft_itemid('myfilemanageroption')) {
            file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanageroption',
                    $nbooking, ['subdirs' => false, 'maxfiles' => 50]);
        }

        if ($draftimageid = file_get_submitted_draft_itemid('bookingoptionimage')) {
            file_save_draft_area_files($draftimageid, $context->id, 'mod_booking', 'bookingoptionimage',
                    $nbooking, ['subdirs' => false, 'maxfiles' => 1]);
        }

        if (isset($fromform->addastemplate) && in_array($fromform->addastemplate, [1, 2])) {
            if (isset($fromform->submittandaddnew)) {
                $redirecturl = new moodle_url('/mod/booking/edit_optiontemplates.php', ['id' => $cm->id, 'optionid' => -1]);
            }

            redirect($redirecturl, get_string('newtemplatesaved', 'booking'), 0);
        }

        if (isset($fromform->submittandaddnew)) {
            $redirecturl = new moodle_url('/mod/booking/edit_optiontemplates.php', ['id' => $cm->id, 'optionid' => -1]);
            redirect($redirecturl, get_string('newtemplatesaved', 'booking'), 0);
        } else {
            redirect($redirecturl, get_string('changessaved'), 0);
        }
    }
} else {
    $PAGE->set_title(format_string($booking->settings->name));
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();

    $mform->set_data($defaultvalues);
    $mform->display();
}
echo $OUTPUT->footer();
