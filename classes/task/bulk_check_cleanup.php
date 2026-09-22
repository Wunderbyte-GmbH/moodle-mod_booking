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
 * Keeps the bulk check table small.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\bulk_check\bulk_check;

/**
 * Tidies up the rows of the bulk check that can only be found by looking.
 *
 * Rows leave the table the moment they stop mattering: a send that will never happen is
 * dropped by its own task, a dismissed one by the admin. What that cannot catch is a pending
 * row whose task died, a sent row that has aged out of every window, and a release that
 * nobody is working on any more, and those are what this looks for.
 *
 * It runs whether or not the check is switched on: the rows of a check that was switched
 * off are exactly the ones that nobody will look at again.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_cleanup extends \core\task\scheduled_task {
    /**
     * Get's the name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskbulkcheckcleanup', 'mod_booking');
    }

    /**
     * Runs one round of the cleanup.
     *
     * @return void
     */
    public function execute() {
        $result = bulk_check::cleanup();
        mtrace(sprintf(
            'Bulk check cleanup: %d orphaned rows removed, %d sent rows purged, %d releases rescued.',
            $result['orphaned'],
            $result['sentpurged'],
            $result['rescued']
        ));
    }
}
