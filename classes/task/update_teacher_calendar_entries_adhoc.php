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
 * Adhoc task to update teacher calendar entries after booking custom fields were changed.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\calendar;

/**
 * Adhoc task to update teacher calendar entries after booking custom fields were changed.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_teacher_calendar_entries_adhoc extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskupdateteachercalendarentries', 'mod_booking');
    }

    /**
     * Update the calendar entries of all teachers of all calendar-enabled booking options.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $recordset = $DB->get_recordset_sql(
            "SELECT " . $DB->sql_concat('bt.optionid', "'-'", 'bt.userid') . " AS uniqueid,
                    bo.id AS optionid, cm.id AS cmid, bt.userid
               FROM {booking_options} bo
               JOIN {course_modules} cm ON cm.instance = bo.bookingid
               JOIN {modules} md ON md.id = cm.module AND md.name = 'booking'
               JOIN {booking_teachers} bt ON bt.optionid = bo.id AND bt.calendarid > 0
              WHERE bo.addtocalendar IN (1, 2) AND bo.calendarid > 0"
        );

        $count = 0;
        foreach ($recordset as $record) {
            try {
                new calendar($record->cmid, $record->optionid, $record->userid, calendar::MOD_BOOKING_TYPETEACHERUPDATE);
                $count++;
            } catch (\Exception $e) {
                mtrace("update_teacher_calendar_entries_adhoc: option {$record->optionid}, " .
                    "user {$record->userid}: " . $e->getMessage());
            }
        }
        $recordset->close();

        mtrace("update_teacher_calendar_entries_adhoc: updated {$count} teacher calendar entries.");
    }
}
