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
 * Adhoc task to remove a user's booking answers after their last unenrolment from a course.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use context_course;
use mod_booking\booking_option;

/**
 * Adhoc task to remove a user's booking answers after their last unenrolment from a course.
 *
 * Queued by the user_enrolment_deleted observer. Re-checks the enrolment at
 * execution time so a user who was re-enrolled in the meantime is left untouched.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remove_responses_on_unenrol_adhoc extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskremoveresponsesonunenrol', 'mod_booking');
    }

    /**
     * Remove the user's booking answers in all options of the course with removeuseronunenrol enabled.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $courseid = (int)($data->courseid ?? 0);
        $userid = (int)($data->userid ?? 0);

        if (empty($courseid) || empty($userid)) {
            mtrace('remove_responses_on_unenrol_adhoc: missing courseid or userid, nothing to do.');
            return;
        }

        // The course may have been deleted between queueing and execution.
        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            mtrace("remove_responses_on_unenrol_adhoc: course {$courseid} no longer exists, nothing to do.");
            return;
        }

        // Re-check at execution time: if the user has any enrolment in the course
        // again (active or not), their bookings must not be touched.
        if (is_enrolled($context, $userid, '', false)) {
            mtrace("remove_responses_on_unenrol_adhoc: user {$userid} is enrolled again in course {$courseid}, skipping.");
            return;
        }

        $sql = 'SELECT bo.id, bo.bookingid
            FROM {booking_options} bo
            JOIN {booking} b ON bo.bookingid = b.id
            WHERE bo.courseid = :courseid
            AND b.removeuseronunenrol = 1';
        $options = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        if (empty($options)) {
            return;
        }

        foreach ($options as $option) {
            try {
                $bo = booking_option::create_option_from_optionid($option->id, $option->bookingid);
                if ($bo) {
                    $bo->user_delete_response($userid);
                }
            } catch (\Exception $e) {
                mtrace("remove_responses_on_unenrol_adhoc: option {$option->id}, user {$userid}: " . $e->getMessage());
            }
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($options), SQL_PARAMS_NAMED);
        $inparams['userid'] = $userid;
        $DB->delete_records_select(
            'booking_teachers',
            "userid = :userid AND optionid $insql",
            $inparams
        );

        mtrace('remove_responses_on_unenrol_adhoc: processed ' . count($options) .
            " options for user {$userid} in course {$courseid}.");
    }
}
