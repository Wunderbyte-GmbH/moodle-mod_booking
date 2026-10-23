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
 * Re-syncs all courses with a course enrolment sync rule.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\sync\enrolmentsync;

/**
 * Re-syncs all courses with a course enrolment sync rule.
 *
 * Catches the changes that fire no per-user enrolment event: an enrol instance enabled or disabled, an enrolment
 * start date reached, missed events. The per-course task only books or removes the difference, so a course that is
 * in sync costs two queries.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_course_enrolment_sync extends \core\task\scheduled_task {
    /**
     * Get the task name.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskreconcilecourseenrolmentsync', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $queued = 0;
        foreach (array_keys(enrolmentsync::get_synced_courses()) as $courseid) {
            if (enrolmentsync::queue_course_sync((int)$courseid)) {
                $queued++;
            }
        }
        mtrace("reconcile_course_enrolment_sync: queued $queued course syncs.");
    }
}
