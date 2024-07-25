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
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->libdir . '/formslib.php');

use mod_booking\utils\wb_payment;
use core\output\notification;

global $DB, $PAGE, $OUTPUT;

$id = required_param('id', PARAM_INT); // Course Module ID.

$url = new moodle_url('/mod/booking/instancetemplateadd.php', ['id' => $id]);
$urlredirect = new moodle_url('/mod/booking/view.php', ['id' => $id]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

require_capability('mod/booking:manageoptiontemplates', $context);

$PAGE->navbar->add(get_string("saveinstanceastemplate", "booking"));
$PAGE->set_title(format_string(get_string("saveinstanceastemplate", "booking")));
$PAGE->set_heading(get_string("saveinstanceastemplate", "booking"));
$PAGE->set_pagelayout('standard');

$mform = new mod_booking\form\instancetemplateadd_form($url);

// Count the number of instance templates.
$templatesdata = $DB->get_records("booking_instancetemplate");
$numberoftemplates = count($templatesdata);

if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($urlredirect, '', 0);
} else if ($data = $mform->get_data()) {
    // Only allow generation of templates if it is either the first one
    // ... OR the user has set a valid PRO licensekey in the config settings.
    if (wb_payment::pro_version_is_activated() || $numberoftemplates == 0) {
        $instance = $DB->get_record("course_modules", ['id' => $id], 'instance');
        $booking = $DB->get_record("booking", ['id' => $instance->instance]);

        $newtemplate = new stdClass();
        $newtemplate->name = $data->name;
        $newtemplate->template = json_encode((array) $booking);

        $DB->insert_record("booking_instancetemplate", $newtemplate);
        redirect($urlredirect, get_string('instancesuccessfullysaved', 'booking'), 5);
    } else {
        // If the user does not match the requirements he will be redirected to view.php
        // ... with the corresponding message.
        redirect($urlredirect, get_string('instancenotsavednovalidlicense', 'booking'), 1, notification::NOTIFY_ERROR);
    }

} else {
    echo $OUTPUT->header();

    $defaultvalues = new stdClass();
    $mform->display();
}

echo $OUTPUT->footer();
