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
use mod_booking\message_controller;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle adhoc Task to send a mail by a rule at a certain time.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_mail_by_rule_adhoc extends \core\task\adhoc_task {
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
     * {@inheritdoc}
     * @throws \coding_exception
     * @throws \dml_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {

        global $DB;

        $taskdata = $this->get_custom_data();
        $nextruntime = $this->get_next_run_time();

        mtrace('send_mail_by_rule_adhoc task: sending mail for option ' . $taskdata->optionid . ' to user '
            . $taskdata->userid);

        if ($taskdata != null) {
            if (!$ruleinstance = $DB->get_record('booking_rules', ['id' => $taskdata->ruleid])) {
                mtrace('send_mail_by_rule_adhoc task: Rule does not exist anymore. Mail was NOT SENT for option ' .
                    $taskdata->optionid . ' and user ' . $taskdata->userid);
                return;
            }

            if (empty($ruleinstance)) {
                return;
            }

            // The first check needs to be if the rule has changed at all, eg. in any of the set values.
            if (
                $ruleinstance->rulename === 'rule_daysbefore'
                && ($taskdata->rulejson !== $ruleinstance->rulejson)
            ) {
                mtrace(
                    'send_mail_by_rule_adhoc task: Rule has changed. Mail was NOT SENT for option.'
                    . $taskdata->optionid
                    . ' and user '
                    . $taskdata->userid
                    .  PHP_EOL
                    . 'This message is expected and not signn of malfunction.'
                );
                return;
            }

            // We replace the rulejson if it's already provided by the task.
            $ruleinstance->rulejson = $taskdata->rulejson ?? $ruleinstance->rulejson;

            $rule = rules_info::get_rule($taskdata->rulename);
            // Important: Load the rule data in the instance. As we have compared the json before, we can use the record.
            // Thereby, we will also have the ruleid.
            $rule->set_ruledata($ruleinstance);

            // We run the call again to see if something has changed (field in bo, in user profile etc.).
            if (!$rule->check_if_rule_still_applies($taskdata->optionid, $taskdata->userid, $nextruntime)) {
                mtrace('send_mail_by_rule_adhoc task: Rule does not apply anymore. Mail was NOT SENT for option ' .
                    $taskdata->optionid . ' and user ' . $taskdata->userid);
                return;
            }

            // We add the option that this task can actually rerun the rule which created it.
            // This will be currently done only by the action "send_mail_interval".
            // The important thing: We will not send the mail, because recipients might have changed.
            // We just reexecute the event, which will then determine the right recipients and take over.
            if (!empty($taskdata->repeat)) {
                $rule->execute($taskdata->optionid);
                return;
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
                return;
            }

            if ($messagecontroller->send_or_queue()) {
                mtrace('send_mail_by_rule_adhoc task: mail successfully sent for option ' . $taskdata->optionid . ' to user '
                . $taskdata->userid);
            } else {
                mtrace('send_mail_by_rule_adhoc task: mail could not be sent for option ' . $taskdata->optionid . ' to user '
                . $taskdata->userid);
            }
        } else {
            throw new \coding_exception(
                'send_mail_by_rule_adhoc task: ERROR - missing taskdata.'
            );
        }
    }
}
