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
 * Adhoc task to trigger the events for the change of a custom user profile field of a user.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\booking_rules\local\userprofilefield_change;
use mod_booking\booking_rules\rules_info;

/**
 * Triggers the event userprofilefield_changed for every booking option concerned by the change of
 * custom user profile fields of one user, and executes the rules reacting on it.
 *
 * The task is queued when a user is updated and a watched field changed to a value a rule is waiting
 * for (see userprofilefield_change::queue_events_for_user), so the update of users is not slowed down.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trigger_userprofilefield_events_adhoc extends \core\task\adhoc_task {
    /**
     * Task name shown in the admin task list.
     *
     * @return \lang_string|string
     */
    public function get_name() {
        return get_string('tasktriggeruserprofilefieldeventsadhoc', 'mod_booking');
    }

    /**
     * Triggers the events and executes the rules which react on them.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();

        userprofilefield_change::trigger_events((int) ($data->userid ?? 0), (array) ($data->changes ?? []));

        // Rules are executed at the very end of the request (see register_shutdown_function in lib.php).
        // Cron runs many tasks in one request, so we execute them here: the work belongs to this task.
        // To avoid infinite loops, we need a counter.
        $counter = 0;
        while (
            (count(rules_info::$rulestoexecute) > 0
            || count(rules_info::$eventstoexecute) > 0)
            && $counter < 10
        ) {
            rules_info::filter_rules_and_execute();
            rules_info::events_to_execute();
            $counter++;
        }
    }
}
