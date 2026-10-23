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
 * Syncs the enrolments of a course to the booking option that was automatically created for it.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\sync\enrolmentsync;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Syncs the enrolments of a course to the booking option that was automatically created for it.
 *
 * Queued once per course by the user_enrolment observers, see \mod_booking\local\sync\enrolmentsync. The task runs
 * without a user on purpose, so cron executes it as admin.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_course_enrolments_adhoc extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('tasksynccourseenrolments', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $taskdata = $this->get_custom_data();
        $courseid = (int)($taskdata->courseid ?? 0);

        if (empty($courseid)) {
            mtrace("sync_course_enrolments_adhoc: missing courseid, nothing to do.");
            return;
        }

        $result = enrolmentsync::sync_course($courseid);
        mtrace("sync_course_enrolments_adhoc: course $courseid, booked {$result['enrolled']}, " .
            "removed {$result['unenrolled']} users.");
    }
}
