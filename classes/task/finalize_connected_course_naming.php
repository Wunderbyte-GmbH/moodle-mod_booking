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
 * Re-applies the connected course naming scheme after an asynchronous course copy.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\connectedcourse;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Re-applies the connected course naming scheme after an asynchronous course copy.
 *
 * Copying a Moodle course is always asynchronous, so the names the naming scheme writes when the
 * booking option is saved do not survive: \core\task\asynchronous_copy_task feeds the provisional
 * fullname and shortname it was created with back into the restore, and overwrites the idnumber
 * with the (empty) one from the copy data. Everything the naming scheme did is undone the moment
 * cron picks the copy up.
 *
 * This task therefore runs once the copy has settled and applies the naming scheme again. It is
 * queued alongside the immediate call, so the option shows the intended name straight away and
 * still carries it after cron.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalize_connected_course_naming extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskfinalizeconnectedcoursenaming', 'mod_booking');
    }

    /**
     * Whether an asynchronous copy or restore into this course is still pending.
     *
     * A task_adhoc record is deleted only once the task has finished successfully, and the copy
     * task writes the course names inside its execute(). So as long as a record referencing the
     * restore of this course exists, the naming would just be overwritten again.
     *
     * @param int $courseid
     * @return bool
     */
    public static function copy_still_running(int $courseid): bool {

        global $DB;

        // Same detection as finalize_template_course and the option form's course selector.
        $sql = "SELECT c.id
                  FROM {course} c
                  JOIN {backup_controllers} bc ON c.id = bc.itemid
                  JOIN {task_adhoc} ta
                    ON ta.customdata LIKE " . $DB->sql_concat("'%backupid%'", "bc.backupid", "'%'") . "
                 WHERE bc.operation = 'restore' AND c.id = :courseid";

        return $DB->record_exists_sql($sql, ['courseid' => $courseid]);
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws moodle_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {

        global $DB;

        $taskdata = $this->get_custom_data();
        $courseid = (int) ($taskdata->courseid ?? 0);
        $optionid = (int) ($taskdata->optionid ?? 0);

        if (empty($courseid) || empty($optionid)) {
            return;
        }

        // If the copy is still running, retry later - throwing reschedules this adhoc task with
        // the standard exponential backoff.
        if (self::copy_still_running($courseid)) {
            throw new moodle_exception(
                'connectedcoursestillduplicating',
                'mod_booking',
                '',
                $courseid
            );
        }

        // Both might have been deleted in the meantime.
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            mtrace("finalize_connected_course_naming: course $courseid no longer exists, nothing to do.");
            return;
        }
        if (!$DB->record_exists('booking_options', ['id' => $optionid])) {
            mtrace("finalize_connected_course_naming: option $optionid no longer exists, nothing to do.");
            return;
        }

        connectedcourse::apply_naming_scheme($courseid, $optionid);

        mtrace("finalize_connected_course_naming: re-applied the naming scheme to course $courseid.");
    }
}
