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
 * Adhoc Task to send a mail by a rule at a certain time.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use Exception;
use mod_booking\booking_rules\rules_info;
use mod_booking\event\booking_debug;
use mod_booking\local\bulk_check\bulk_check;
use mod_booking\message_controller;
use mod_booking\singleton_service;
use stdClass;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle adhoc Task to send a mail by a rule at a certain time.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_mail_by_rule_adhoc extends \core\task\adhoc_task {
    /** @var int The task finished without sending: the mail will never go out. */
    private const RESULT_SKIPPED = 0;

    /** @var int The mail went out. */
    private const RESULT_SENT = 1;

    /** @var int The bulk send checker parked the mail. */
    private const RESULT_BLOCKED = 2;

    /**
     * Get task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('tasksendmailbyruleadhoc', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * The work is done in run(); this wrapper only settles the row the bulk send checker
     * keeps for this task, so that every way out of run() ends in exactly one place. An
     * exception leaves the row as it is on purpose: the task is retried by core and claims
     * the row again.
     *
     * {@inheritdoc}
     * @throws \coding_exception
     * @throws \dml_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $taskdata = $this->get_custom_data();

        if ($taskdata == null) {
            throw new \coding_exception(
                'send_mail_by_rule_adhoc task: ERROR - missing taskdata.'
            );
        }

        mtrace('send_mail_by_rule_adhoc task: sending mail for option ' . $taskdata->optionid . ' to user '
            . $taskdata->userid);

        // Null when the send is not bulk checked, which is the case for every rule that
        // was not ticked and for the whole site while the checker is off.
        $bulkrow = bulk_check::get_row_by_task((int) $this->get_id());

        $result = $this->run($taskdata, $bulkrow);

        if ($bulkrow === null) {
            return;
        }
        if ($result === self::RESULT_SENT) {
            bulk_check::mark_sent($bulkrow);
        } else if ($result === self::RESULT_SKIPPED) {
            // A send that will never happen must not count towards a window.
            bulk_check::drop($bulkrow);
        }
        // RESULT_BLOCKED: the row is parked and waits on the page. The task completes
        // without an exception, so core does not retry it.
    }

    /**
     * Validates the task, asks the bulk send checker and sends.
     *
     * The validation comes first and the verdict of the checker second: a send that would
     * never happen is dropped before it can count, and a parked send is not validated again
     * until it is released and runs as a fresh task.
     *
     * @param stdClass $taskdata
     * @param stdClass|null $bulkrow The row of the bulk send checker, null when not checked.
     * @return int One of the RESULT_* constants.
     */
    private function run(stdClass $taskdata, ?stdClass $bulkrow): int {
        global $DB;

        // The days-before and specific-time rules re-validate by demanding exactly the run
        // time they computed. A bulk checked task runs later than that, and a released one
        // later still, so the time the rule computed is taken from the row instead.
        $nextruntime = $bulkrow !== null ? (int) $bulkrow->sendtime : $this->get_next_run_time();

        if (!$ruleinstance = $DB->get_record('booking_rules', ['id' => $taskdata->ruleid])) {
            mtrace('send_mail_by_rule_adhoc task: Rule does not exist anymore. Mail was NOT SENT for option ' .
                $taskdata->optionid . ' and user ' . $taskdata->userid);
            return self::RESULT_SKIPPED;
        }

        if (empty($ruleinstance)) {
            return self::RESULT_SKIPPED;
        }
        $option = singleton_service::get_instance_of_booking_option_settings($taskdata->optionid);
        // The first check needs to be if the rule has changed at all, eg. in any of the set values.
        if (
            $taskdata->rulejson !== $ruleinstance->rulejson
            || $option->cmid !== $taskdata->cmid
        ) {
            $abort = false;
            if (in_array($ruleinstance->rulename, ['rule_daysbefore', 'rule_specifictime'])) {
                $abort = true;
            } else {
                $td = json_decode($taskdata->rulejson);
                $rd = json_decode($ruleinstance->rulejson);
                if (
                    $td->actiondata != $rd->actiondata
                    || $td->ruledata != $rd->ruledata
                ) {
                    $abort = true;
                }
            }
            if ($abort) {
                mtrace(
                    'send_mail_by_rule_adhoc task: Rule or Option has changed. Mail was NOT SENT for option.'
                    . $taskdata->optionid
                    . ' and user '
                    . $taskdata->userid
                    .  PHP_EOL
                    . 'This message is expected and not sign of malfunction.'
                );
                return self::RESULT_SKIPPED;
            }
        }

        // We replace the rulejson if it's already provided by the task.
        $ruleinstance->rulejson = $taskdata->rulejson ?? $ruleinstance->rulejson;

        $rule = rules_info::get_rule($taskdata->rulename);
        // Important: Load the rule data in the instance. As we have compared the json before, we can use the record.
        // Thereby, we will also have the ruleid.
        $rule->set_ruledata($ruleinstance);

        // We run the call again to see if something has changed (field in bo, in user profile etc.).
        if (
            !$rule->check_if_rule_still_applies(
                $taskdata->optionid,
                $taskdata->userid,
                $nextruntime,
                $taskdata->optiondateid ?? 0
            )
        ) {
            mtrace('send_mail_by_rule_adhoc task: Rule does not apply anymore. Mail was NOT SENT for option ' .
                $taskdata->optionid . ' and user ' . $taskdata->userid);
            return self::RESULT_SKIPPED;
        }

        // The bulk send checker decides last, once it is certain that the mail would go out
        // otherwise. A blocked mail is parked and waits for a manual release, so this
        // returns quietly instead of throwing and having the task retried.
        if (bulk_check::check($bulkrow, $this->get_custom_data_as_string()) === bulk_check::BLOCKED) {
            mtrace('send_mail_by_rule_adhoc task: blocked by the bulk send check, mail parked for option ' .
                $taskdata->optionid . ' and user ' . $taskdata->userid);
            return self::RESULT_BLOCKED;
        }

        // We might receive an error here, because we refer to cmids which no longer exist.
        // That's not a problem, we just abort sending the task.
        try {
            // Use message controller to send the message.
            $messagecontroller = new message_controller(
                MOD_BOOKING_MSGCONTRPARAM_SEND_NOW,
                MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE,
                $taskdata->cmid,
                $taskdata->optionid,
                $taskdata->userid,
                null,
                null,
                null,
                $taskdata->customsubject,
                $taskdata->custommessage,
                $taskdata->installmentnr ?? 0,
                $taskdata->duedate ?? 0,
                $taskdata->price ?? 0,
                $taskdata->rulejson ?? 0,
                $taskdata->ruleid ?? 0  // Send the ruleid as rulejson often seems to not work.
            );
        } catch (Exception $e) {
            if (get_config('booking', 'bookingdebugmode')) {
                // If debug mode is enabled, we create a debug message.
                $event = booking_debug::create([
                    'objectid' => $taskdata->optionid ?? 0,
                    'context' => \context_system::instance(),
                    'relateduserid' => $taskdata->userid ?? 0,
                    'other' => [
                        'exception' => $e->getMessage(),
                        'cmid' => $taskdata->cmid ?? 0,
                        'optionid' => $taskdata->optionid ?? 0,
                        'userid' => $taskdata->userid ?? 0,
                        'customsubject' => $taskdata->customsubject ?? '',
                        'custommessage' => $taskdata->custommessage ?? '',
                    ],
                ]);
                $event->trigger();
            }
            return self::RESULT_SKIPPED;
        }

        if ($messagecontroller->send_or_queue()) {
            mtrace('send_mail_by_rule_adhoc task: mail successfully sent for option ' . $taskdata->optionid . ' to user '
            . $taskdata->userid);
            return self::RESULT_SENT;
        }

        mtrace('send_mail_by_rule_adhoc task: mail could not be sent for option ' . $taskdata->optionid . ' to user '
        . $taskdata->userid);
        return self::RESULT_SKIPPED;
    }
}
