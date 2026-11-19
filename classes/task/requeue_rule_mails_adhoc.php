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
 * Queues the scheduled mails of one date based booking rule again.
 *
 * The migration of the waiting list progression (upgrade step 2026090304) deleted all pending
 * send_mail_by_rule_adhoc tasks, also the reminders of rules that have nothing to do with the
 * waiting list. This task does for one rule what saving the rule in the rules form does: it
 * executes the rule, which queues its mails. Mails whose time has passed are skipped by the
 * rules themselves and a mail that is still queued is reused, so the task can run more than once.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\booking_rules\rules_info;

/**
 * Executes one date based booking rule again, so that its scheduled mails are queued.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requeue_rule_mails_adhoc extends \core\task\adhoc_task {
    /** @var string Rules of this type only run on an event and have no mails to queue in advance. */
    private const EVENTRULE = 'rule_react_on_event';

    /**
     * Task name shown in the admin task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrequeuerulemailsadhoc', 'mod_booking');
    }

    /**
     * Executes the rule of this task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $ruleid = (int) ($this->get_custom_data()->ruleid ?? 0);
        if (empty($ruleid)) {
            // Without an id execute_booking_rules() would execute every rule.
            return;
        }
        // The rule may have been deleted or switched off since the task was queued.
        if (!$DB->record_exists('booking_rules', ['id' => $ruleid, 'isactive' => 1])) {
            return;
        }

        rules_info::execute_booking_rules($ruleid);
    }

    /**
     * Queues one task for every active rule that schedules its mails by a date.
     *
     * One task per rule, so a rule that fails does not hold back the others.
     *
     * @return int the number of rules a task is queued for
     */
    public static function queue_for_all_rules(): int {
        global $DB;

        $ruleids = $DB->get_fieldset_select(
            'booking_rules',
            'id',
            'isactive = 1 AND rulename <> :eventrule',
            ['eventrule' => self::EVENTRULE]
        );

        foreach ($ruleids as $ruleid) {
            $task = new self();
            $task->set_custom_data(['ruleid' => (int) $ruleid]);
            \core\task\manager::queue_adhoc_task($task, true);
        }

        return count($ruleids);
    }
}
