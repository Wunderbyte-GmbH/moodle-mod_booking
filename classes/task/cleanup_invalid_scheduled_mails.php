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
 * Scheduled task that cleans up invalid scheduled mails in the booking context.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\scheduledmails;

/**
 * Class to handle scheduled task that cleans up invalid scheduled mails.
 *
 * This task runs daily at 2 AM and removes all scheduled mails (adhoc tasks)
 * from context_system that are no longer valid according to their booking rules.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_invalid_scheduled_mails extends \core\task\scheduled_task {
    /**
     * Get name of module.
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskcleanupinvalidscheduledmails', 'mod_booking');
    }

    /**
     * Scheduled task that cleans up invalid scheduled mails.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function execute() {
        mtrace('Starting cleanup of invalid scheduled mails in context_system...');

        // Use context_system (contextid = 1) to clean up all system-level scheduled mails.
        $results = scheduledmails::cleanup_invalid_tasks_in_context(1);

        mtrace('Cleanup completed:');
        mtrace('  - Checked: ' . ($results['checked'] ?? 0) . ' records');
        mtrace('  - Deleted: ' . ($results['deleted'] ?? 0) . ' invalid records');
        mtrace('  - Not found status: ' . ($results['nostatusfound'] ?? 0) . ' records');
        mtrace('  - No tasks found: ' . ($results['notasksfound'] ?? 0) . ' records');
    }
}
