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

use context_system;
use core\task\adhoc_task;
use mod_booking\local\book_all_students;
use moodle_url;

/**
 * Adhoc task to bulk-book all students into a booking option.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_all_students_task extends adhoc_task {
    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('bookallstudents', 'mod_booking');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $PAGE;

        $customdata = $this->get_custom_data();
        if (empty($customdata->optionid)) {
            throw new \coding_exception('book_all_students_task requires optionid in custom data');
        }

        // Some booking condition checks read $PAGE->url even in task context.
        $PAGE->set_context(context_system::instance());
        $PAGE->set_url(new moodle_url('/mod/booking/view.php'));

        $result = book_all_students::execute((int)$customdata->optionid);

        mtrace(sprintf(
            'Bulk booking completed: booked=%d, waitinglist=%d, skipped=%d, failed=%d, stopped_for_capacity=%s',
            $result->booked,
            $result->waitinglist,
            $result->skipped,
            $result->failed,
            $result->stoppedforcapacity ? 'yes' : 'no'
        ));
    }
}
