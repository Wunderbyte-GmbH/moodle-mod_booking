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
 * Scheduled task writing a daily snapshot of the booking cache report metrics.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\cachereport\cachereport_service;

/**
 * Scheduled task writing a daily snapshot of the booking cache report metrics.
 *
 * The snapshot contains aggregates only (no user data) and is strictly
 * read-only towards the caches; the report page renders the stored rows as a
 * trend so cache problems become visible before anybody has to ask.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_cachereport_snapshot extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcreatecachereportsnapshot', 'mod_booking');
    }

    /**
     * Create one snapshot and apply the retention.
     *
     * @return void
     */
    public function execute(): void {
        $service = new cachereport_service();
        $id = $service->create_snapshot(cachereport_service::DEFAULTSAMPLESIZE);
        mtrace("mod_booking: cache report snapshot $id created.");
    }
}
