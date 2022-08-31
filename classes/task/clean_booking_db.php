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

namespace mod_booking\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Scheduled task that removes relicts and unnecessary artifacts from the DB.
 */
class clean_booking_db extends \core\task\scheduled_task {

    /**
     * Get name of module.
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task_clean_booking_db', 'mod_booking');
    }

    /**
     * Scheduled task that removes relicts and unnecessary artifacts from the DB.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function execute() {

        global $DB;

        // Remove entries from table "booking_optiondates_teachers" that belong to non-existing options.
        $DB->delete_records_select('booking_optiondates_teachers', "optiondateid NOT IN (SELECT id FROM {booking_optiondates})");

        // TODO: In the future, we can add additional cleaning.
    }
}
