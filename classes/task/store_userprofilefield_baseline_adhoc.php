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
 * Adhoc task to remember the values of the custom user profile fields watched by booking rules.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\booking_rules\local\userprofilefield_change;

/**
 * Remembers the values of the custom user profile fields watched by booking rules for all the users
 * whose values are not known yet.
 *
 * The task is queued when a user is created (see userprofilefield_change::queue_missing_baselines) and
 * when a rule starts to watch a field (see userprofilefield_change::save). One task serves any number
 * of users, and it can run more than once without harm.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class store_userprofilefield_baseline_adhoc extends \core\task\adhoc_task {
    /**
     * Task name shown in the admin task list.
     *
     * @return \lang_string|string
     */
    public function get_name() {
        return get_string('taskstoreuserprofilefieldbaselineadhoc', 'mod_booking');
    }

    /**
     * Stores the current values of the watched profile fields of the users we do not know yet.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();

        // A rule which is just being saved tells us its field, as the rule itself might not be stored yet.
        $shortname = (string) ($data->shortname ?? '');

        userprofilefield_change::store_missing_baselines($shortname === '' ? [] : [$shortname]);
    }
}
