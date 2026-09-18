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
 * Adhoc Task to send an entry ticket by a rule at a certain time.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use Exception;
use mod_booking\booking_rules\rules_info;
use mod_booking\event\booking_debug;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\message_controller;
use mod_booking\singleton_service;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle the adhoc task sending an entry ticket by a rule.
 *
 * Behaves exactly like send_mail_by_rule_adhoc, but attaches the ticket PDF of the recipient and
 * aborts when there is no valid ticket to send.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_ticket_by_rule_adhoc extends \core\task\adhoc_task {
    /**
     * Get task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('tasksendticketbyruleadhoc', 'mod_booking');
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

        if ($taskdata === null) {
            throw new \coding_exception('send_ticket_by_rule_adhoc task: ERROR - missing taskdata.');
        }

        mtrace('send_ticket_by_rule_adhoc task: sending ticket for option ' . $taskdata->optionid . ' to user '
            . $taskdata->userid);

        if (!$ruleinstance = $DB->get_record('booking_rules', ['id' => $taskdata->ruleid])) {
            mtrace('send_ticket_by_rule_adhoc task: Rule does not exist anymore. Ticket was NOT SENT for option ' .
                $taskdata->optionid . ' and user ' . $taskdata->userid);
            return;
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
                    'send_ticket_by_rule_adhoc task: Rule or Option has changed. Ticket was NOT SENT for option.'
                    . $taskdata->optionid
                    . ' and user '
                    . $taskdata->userid
                    . PHP_EOL
                    . 'This message is expected and not sign of malfunction.'
                );
                return;
            }
        }

        // We replace the rulejson if it's already provided by the task.
        $ruleinstance->rulejson = $taskdata->rulejson ?? $ruleinstance->rulejson;

        $rule = rules_info::get_rule($taskdata->rulename);
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
            mtrace('send_ticket_by_rule_adhoc task: Rule does not apply anymore. Ticket was NOT SENT for option ' .
                $taskdata->optionid . ' and user ' . $taskdata->userid);
            return;
        }

        // Resolve the ticket by option and user, so this action also works on events other than ticket_created.
        $ticket = ticket_manager::find_valid_ticket((int) $taskdata->optionid, (int) $taskdata->userid);
        if (empty($ticket)) {
            mtrace('send_ticket_by_rule_adhoc task: no valid ticket for option ' . $taskdata->optionid . ' and user '
                . $taskdata->userid . '. Nothing was sent.');
            return;
        }

        $file = ticket_manager::get_file($ticket) ?? ticket_manager::regenerate_pdf((int) $ticket->id);
        if (empty($file)) {
            mtrace('send_ticket_by_rule_adhoc task: ticket PDF could not be created for option ' . $taskdata->optionid
                . ' and user ' . $taskdata->userid . '. Nothing was sent.');
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
                0,
                0,
                0,
                $taskdata->rulejson ?? 0,
                $taskdata->ruleid ?? 0  // Send the ruleid as rulejson often seems to not work.
            );

            // The message controller copies the file, so a request directory path is safe here.
            $filename = clean_filename(
                get_string('ticketfilenameprefix', 'mod_booking') . '_' . $ticket->code . '.pdf'
            );
            $path = make_request_directory() . DIRECTORY_SEPARATOR . $filename;
            $file->copy_content_to($path);
            $messagecontroller->set_custom_attachment($path, $filename);
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
            mtrace('send_ticket_by_rule_adhoc task: ticket successfully sent for option ' . $taskdata->optionid
                . ' to user ' . $taskdata->userid);
        } else {
            mtrace('send_ticket_by_rule_adhoc task: ticket could not be sent for option ' . $taskdata->optionid
                . ' to user ' . $taskdata->userid);
        }
    }
}
