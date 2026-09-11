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

/**
 * Adhoc task to process one cohort/group membership change for booking sync.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_source_membership_adhoc extends \core\task\adhoc_task {
    /**
     * Get task name.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskprocesssourcemembershipsyncadhoc', 'mod_booking');
    }

    /**
     * Execute task.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (empty($data) || empty($data->sourcetype) || empty($data->sourceid) || empty($data->userid)) {
            return;
        }

        \mod_booking\local\sync\booking_enrolment::process_source_membership(
            (string)$data->sourcetype,
            (int)$data->sourceid,
            (int)$data->userid,
            !empty($data->membershipadded)
        );
    }
}
