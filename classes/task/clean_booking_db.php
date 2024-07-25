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
 * Scheduled task that removes relicts and unnecessary artifacts from the DB.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use cache_helper;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle scheduled task that removes relicts and unnecessary artifacts from the DB.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clean_booking_db extends \core\task\scheduled_task {

    /**
     * Get name of module.
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskcleanbookingdb', 'mod_booking');
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
        cache_helper::purge_by_event('setbackcachedteachersjournal');

        // Remove entries from table "booking_teachers" that belong to non-existing options.
        $DB->delete_records_select('booking_teachers',
        "bookingid NOT IN (
            SELECT cm.instance
            FROM {course_modules} cm
            JOIN {modules} m
            ON m.id = cm.module
            WHERE m.name = 'booking'
        )");

        // TODO: In the future, we can add additional cleaning.
    }
}
