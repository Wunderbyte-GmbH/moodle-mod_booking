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
 * Queue a bulk-book-all-students task for a booking option.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use core\task\manager;
use mod_booking\booking_option;
use mod_booking\task\book_all_students_task;

$optionid = required_param('optionid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    throw new moodle_exception('invalidsesskey');
}

// Not via the option settings: loading them runs the text filters, which may set up the theme
// before require_login() sets the course.
$cmid = booking_option::get_cmid_from_optionid_before_login($optionid);
if (empty($cmid)) {
    throw new moodle_exception('invalidobjectid', 'error', '', 'booking option');
}

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/booking/bulk_book_handler.php', ['optionid' => $optionid]));

require_capability('mod/booking:bookallstudents', $context);

// Queue the adhoc task.
$task = new book_all_students_task();
$task->set_custom_data((object)['optionid' => $optionid]);
manager::queue_adhoc_task($task);

// Redirect back to the booking option view with success notification.
$redirecturl = new moodle_url('/mod/booking/view.php', [
    'id' => $cmid,
]);

redirect(
    $redirecturl,
    get_string('bookallstudentsqueued', 'mod_booking'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
