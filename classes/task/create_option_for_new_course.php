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
 * Creates a booking option for a course that was created in a linked course category.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\autocreateoption;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Creates a booking option for a course that was created in a linked course category.
 *
 * Queued by the course_created / course_restored observers, see \mod_booking\local\autocreateoption. The task runs
 * without a user on purpose, so cron executes it as admin and the full option form field set is available.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_option_for_new_course extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskcreateoptionfornewcourse', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @throws \moodle_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $taskdata = $this->get_custom_data();
        $courseid = (int)($taskdata->courseid ?? 0);
        $bookingid = (int)($taskdata->bookingid ?? 0);

        if (empty($courseid) || empty($bookingid)) {
            mtrace("create_option_for_new_course: missing courseid or bookingid, nothing to do.");
            return;
        }

        autocreateoption::create_option_for_course($bookingid, $courseid);
    }
}
