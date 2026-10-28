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
 * Syncs the courses that already exist in the linked course category with a booking instance.
 *
 * Shows what the sync would do and, after confirmation, queues the adhoc task that creates the missing booking
 * options and books the enrolled users (when the enrolment sync is on).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use mod_booking\local\autocreateoption;
use mod_booking\singleton_service;
use mod_booking\task\sync_autocreate_category_adhoc;
use mod_booking\utils\wb_payment;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

$cmid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/booking:updatebooking', $context);

$bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cm->id);
$bookingid = (int)$bookingsettings->id;

$pageurl = new moodle_url('/mod/booking/syncautocreatecategory.php', ['id' => $cmid]);
$returnurl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
$settingsurl = new moodle_url('/course/modedit.php', ['update' => $cmid]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(format_string($bookingsettings->name) . ': ' . get_string('autocreateoption:syncnow', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));
// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

if (!wb_payment::pro_version_is_activated()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('infotext:prolicensenecessarytextandlink', 'mod_booking'), notification::NOTIFY_WARNING);
    echo $OUTPUT->continue_button($returnurl);
    echo $OUTPUT->footer();
    exit;
}

$preview = autocreateoption::get_category_sync_preview($bookingid);

if (empty($preview['categoryid'])) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('autocreateoption:syncnow:nocategory', 'mod_booking'), notification::NOTIFY_INFO);
    echo $OUTPUT->continue_button($settingsurl);
    echo $OUTPUT->footer();
    exit;
}

if ($confirm) {
    require_sesskey();
    $task = new sync_autocreate_category_adhoc();
    // No user is set on purpose: cron then runs the task as admin, see the task class.
    $task->set_custom_data(['bookingid' => $bookingid]);
    // A second click while the task is still queued does not queue a second one.
    \core\task\manager::queue_adhoc_task($task, true);
    redirect($returnurl, get_string('autocreateoption:syncnow:queued', 'mod_booking'), null, notification::NOTIFY_SUCCESS);
}

$category = core_course_category::get($preview['categoryid'], IGNORE_MISSING);
$a = (object)[
    'category' => $category ? $category->get_formatted_name() : (string)$preview['categoryid'],
    'courses' => $preview['courses'],
    'missing' => $preview['missing'],
];
$message = html_writer::tag('p', get_string('autocreateoption:syncnow:confirm', 'mod_booking', $a));
if ($preview['syncenrolments']) {
    $message .= html_writer::tag('p', get_string('autocreateoption:syncnow:confirmusers', 'mod_booking'));
} else {
    $message .= html_writer::tag(
        'p',
        get_string('autocreateoption:syncnow:confirmnousers', 'mod_booking', $settingsurl->out(false))
    );
}

$continuebutton = new single_button(
    new moodle_url($pageurl, ['confirm' => 1, 'sesskey' => sesskey()]),
    get_string('autocreateoption:syncnow:button', 'mod_booking'),
    'post'
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('autocreateoption:syncnow', 'mod_booking'));
echo $OUTPUT->confirm($message, $continuebutton, $returnurl);
echo $OUTPUT->footer();
