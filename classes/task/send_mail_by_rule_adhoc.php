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

use Exception;
use mod_booking\booking_rules\rules_info;
use mod_booking\message_controller;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Adhoc Task to send a mail by a rule at a certain time.
 */
class send_mail_by_rule_adhoc extends \core\task\adhoc_task {

    /**
     * Get task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task_send_mail_by_rule_adhoc', 'mod_booking');
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

            $ruleinstance = $DB->get_record('booking_rules', ['id' => $taskdata->ruleid]);

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

            // We might receive an error here, because we refer to cmids which no longer exist.
            // That's not a problem, we just abort sending the task.
            try {
                // Use message controller to send the message.
                $messagecontroller = new message_controller(
                    MOD_BOOKING_MSGCONTRPARAM_SEND_NOW, MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE,
                    $taskdata->cmid, null, $taskdata->optionid,
                    $taskdata->userid, null, null, $taskdata->customsubject, $taskdata->custommessage
                );
            } catch (Exception $e) {
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
                    'send_mail_by_rule_adhoc task: ERROR - missing taskdata.');
        }
    }
}
