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
 * Applies the enrolment sync setting of a booking instance to its automatically created options.
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
 * Applies the enrolment sync setting of a booking instance to its automatically created options.
 *
 * Queued when the setting is switched on or off. The setting is read at execution time, so switching back and forth
 * before the task runs is harmless.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolmentsync_instance_adhoc extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskenrolmentsyncinstance', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        global $DB;

        $taskdata = $this->get_custom_data();
        $bookingid = (int)($taskdata->bookingid ?? 0);

        if (empty($bookingid) || !$DB->record_exists('booking', ['id' => $bookingid])) {
            mtrace("enrolmentsync_instance_adhoc: booking instance $bookingid does not exist, nothing to do.");
            return;
        }

        enrolmentsync::apply_instance_setting($bookingid);
    }
}
