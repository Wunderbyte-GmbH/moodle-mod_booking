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

declare(strict_types=1);

namespace mod_booking;

use advanced_testcase;
use context_module;
use context_system;
use core\task\manager as task_manager;
use mod_booking\local\scheduledmails;
use mod_booking\task\send_mail_by_rule_adhoc;

/**
 * Scheduled mails must be listed context-specifically: a booking instance shows only its own
 * rules' mails, and the system context (contextid 1) shows only site-wide rules' mails.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\scheduledmails::get_sql
 */
final class scheduledmails_context_test extends advanced_testcase {
    /**
     * A scheduled mail created by a rule in one context must not appear in another context.
     *
     * @return void
     */
    public function test_get_sql_lists_only_mails_of_the_requested_context(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        $systemcontextid = (int)context_system::instance()->id;
        $modcontextid = (int)context_module::instance($booking->cmid)->id;

        // One rule + one scheduled mail in the system context, one in the booking instance context.
        $systemruleid = $this->create_rule_row($systemcontextid, 'System rule');
        $modruleid = $this->create_rule_row($modcontextid, 'Module rule');
        $this->queue_scheduled_mail($systemruleid, (int)$user->id);
        $this->queue_scheduled_mail($modruleid, (int)$user->id);

        // System context: the system rule's mail is shown, the module rule's mail is NOT.
        $systemruleids = $this->listed_rule_ids($systemcontextid);
        $this->assertContains($systemruleid, $systemruleids);
        $this->assertNotContains($modruleid, $systemruleids);

        // Booking instance context: the module rule's mail is shown, the system rule's mail is NOT.
        $modruleids = $this->listed_rule_ids($modcontextid);
        $this->assertContains($modruleid, $modruleids);
        $this->assertNotContains($systemruleid, $modruleids);
    }

    /**
     * Run scheduledmails::get_sql() for a context and return the rule ids of the listed mails.
     *
     * @param int $contextid
     * @return int[]
     */
    private function listed_rule_ids(int $contextid): array {
        global $DB;
        [$fields, $from, $where, $params] = scheduledmails::get_sql($contextid);
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        return array_map(static fn ($row): int => (int)$row->ruleid, $rows);
    }

    /**
     * Insert a minimal booking rule in the given context and return its id.
     *
     * @param int $contextid
     * @param string $name
     * @return int
     */
    private function create_rule_row(int $contextid, string $name): int {
        global $DB;
        return (int)$DB->insert_record('booking_rules', (object)[
            'contextid' => $contextid,
            'rulename' => 'rule_react_on_event',
            'eventname' => '\\mod_booking\\event\\bookingoption_booked',
            'rulejson' => json_encode([
                'name' => $name,
                'actionname' => 'send_mail',
                'actiondata' => ['subject' => $name . ' subject', 'template' => $name . ' body'],
            ]),
            'isactive' => 1,
            'useastemplate' => 0,
        ]);
    }

    /**
     * Queue a send_mail_by_rule_adhoc task referencing a rule, as the rule actions do at runtime.
     *
     * @param int $ruleid
     * @param int $userid
     * @return void
     */
    private function queue_scheduled_mail(int $ruleid, int $userid): void {
        $task = new send_mail_by_rule_adhoc();
        $task->set_custom_data([
            'ruleid' => $ruleid,
            'userid' => $userid,
            'optionid' => 0,
            'cmid' => 0,
        ]);
        task_manager::queue_adhoc_task($task);
    }
}
