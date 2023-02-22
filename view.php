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
 * This is the main entrance point for the booking plugin
 * showing a table of all booking options.
 * Completely rebuilt in January 2023.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner, Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\output\business_card;
use mod_booking\output\view;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/comment/lib.php');

// No guest autologin.
require_login(0, false);

global $DB, $PAGE, $OUTPUT, $USER;

$cmid = required_param('id', PARAM_INT); // Course Module ID.
$optionid = optional_param('optionid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$whichview = optional_param('whichview', '', PARAM_ALPHA);

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);
$context = context_module::instance($cm->id);

// URL params.
$urlparams = [
    'id' => $cmid
];
// SQL params.
$params = [];

$booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
$booking->checkautocreate();

$PAGE->set_context($context);

// Trigger course_module_viewed event.
$event = \mod_booking\event\course_module_viewed::create(
    array('objectid' => $cm->instance, 'context' => $context));
$event->add_record_snapshot('course', $course);
$event->trigger();

// Get the booking instance settings by cmid.
$bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

$title = $bookingsettings->name;

$baseurl = new moodle_url('/mod/booking/view.php', $urlparams);
$PAGE->set_url($baseurl);

$PAGE->navbar->add($title);
$PAGE->set_title(format_string($title));
$PAGE->set_heading($title);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('mod_booking-view');

// Get the renderer.
$output = $PAGE->get_renderer('mod_booking');

echo $OUTPUT->header();

// If we have specified a teacher as organizer, we show a "busines_card" with photo, else legacy organizer description.
if (!empty($bookingsettings->organizatorname)
    && ($organizerid = (int)$bookingsettings->organizatorname)) {
    $data = new business_card($bookingsettings, $organizerid);
    echo $output->render_business_card($data);
}
// As of Moodle 4.0 activity description will be shown automatically in module header.
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/* else {
    $data = new instance_description($bookingsettings);
    echo $output->render_instance_description($data);
} */

// Now we show the actual view.
$view = new view($cmid, $whichview, $optionid);
echo $output->render_view($view);

// Wunderbyte info and footer.
$logourl = new moodle_url('/mod/booking/pix/wb-logo.png');
echo $OUTPUT->box('<a href="https://www.wunderbyte.at" target="_blank"><img src="' . $logourl . '" width="200px" alt="Wunderbyte logo"><br>' .
    get_string('createdbywunderbyte', 'mod_booking') . "</a>", 'box mdl-align');
echo $OUTPUT->footer();
