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

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Legacy adhoc task, kept only so the class stays loadable for any {task_adhoc} row still
 * referencing this classname (upgrade_step::run() deletes all such rows as part of the Phase 3
 * migration, so this should never actually fire on an upgraded site - kept as a defensive no-op,
 * not deleted outright, in case cron picks up a row mid-upgrade).
 *
 * Deliberately a no-op since the waitlist-progression refactoring (Phase 3,
 * WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.5): granting waitlist confirmation is now
 * progression::offer()'s job (local/waitlist/progression.php, grant_confirmation_if_required()).
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm_bookinganswer_by_rule_adhoc extends \core\task\adhoc_task {
    /**
     * Get task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskconfirmbookinganswerbymailbyruleadhoc', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * Intentionally empty - see class docblock.
     *
     * {@inheritdoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        mtrace(
            'confirm_bookinganswer_by_rule_adhoc task: no-op (superseded by the waitlist-progression ' .
            'refactoring, Phase 3) - this task row should have been removed by upgrade_step::run().'
        );
    }
}
