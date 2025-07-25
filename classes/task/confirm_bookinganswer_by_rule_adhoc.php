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
use mod_booking\booking_option;
use mod_booking\booking_rules\rules_info;
use mod_booking\event\booking_debug;
use mod_booking\singleton_service;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Ad-hoc task that confirms booking answers with a price,
 * or sets the confirmation JSON for booking answers with no price for persons on the waiting list.
 *
 * Thistask will execute only if 'confirmationonnotification' is enabled.
 *
 * If 'confirmationonnotification' is equal to 2, the task will set the confirmation
 * only for only one person at a time from the waiting list.
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

        mtrace('confirm_bookinganswer_by_rule_adhoc task: for option ' . $taskdata->optionid . ' to user ' . $taskdata->userid);

        if ($taskdata != null) {
            if (!$ruleinstance = $DB->get_record('booking_rules', ['id' => $taskdata->ruleid])) {
                mtrace('confirm_bookinganswer_by_rule_adhoc task: Rule does not exist anymore. NO execution for option ' .
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
                    'confirm_bookinganswer_by_rule_adhoc task: Rule has changed. Confirmation was NOT given for option.'
                    . $taskdata->optionid . ' and user ' . $taskdata->userid .  PHP_EOL
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
                mtrace('confirm_bookinganswer_by_rule_adhoc task: Rule does not apply anymore. NO execution for option ' .
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
                // Check if booking option has confirmationonnotification enabled,
                // in this case we need to set some settings for the booking answer record
                // for the user who is going to reserve this (priced) option.
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($taskdata->optionid);
                if ($optionsettings->confirmationonnotification == 0) {
                    mtrace(
                        'confirm_bookinganswer_by_rule_adhoc task: setting in the booking option is set to 0, so no confirmation '
                        . 'is required for this option.' . $taskdata->optionid . ' and user ' . $taskdata->userid . PHP_EOL
                    );
                    return;
                }

                $optionsettings = singleton_service::get_instance_of_booking_option_settings($taskdata->optionid);
                // Run the main process.
                // Get sprecific booking answer record.
                $bookinganswer = $DB->get_record('booking_answers', [
                    'optionid' => $taskdata->optionid,
                    'userid' => $taskdata->userid,
                    'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                ]);

                // The record of booking answer for an optoon with price will be updated to set confirmation json.
                // And the record of booking answers with no price price will be updated to set
                // waiting list to 0 as it should be confirmed.
                if ($optionsettings->jsonobject->useprice == 0) {
                    $user = singleton_service::get_instance_of_user($taskdata->userid);
                    $option = singleton_service::get_instance_of_booking_option($optionsettings->cmid, $optionsettings->id);
                    $option->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
                } else {
                    // Update booking answer.
                    booking_option::write_user_answer_to_db(
                        $bookinganswer->bookingid,
                        $bookinganswer->frombookingid,
                        $bookinganswer->userid,
                        $bookinganswer->optionid,
                        MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                        $bookinganswer->id,
                        null,
                        MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION,
                        "",
                        0
                    );

                    // Set json to null for all other users on waiting list for this optuion
                    // in booking answer records if confirmationonnotification is equal to 2.
                    if ($optionsettings->confirmationonnotification == 2) {
                        // Get sprecific booking answer record.
                        $bookinganswers = $DB->get_records('booking_answers', [
                            'optionid' => $taskdata->optionid,
                            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                        ]);

                        foreach ($bookinganswers as $ba) {
                            if ($ba->userid == $taskdata->userid) {
                                continue; // Ignore current user as we need to set json for current user.
                            }
                            // Update booking answer.
                            booking_option::write_user_answer_to_db(
                                $ba->bookingid,
                                $ba->frombookingid,
                                $ba->userid,
                                $ba->optionid,
                                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                                $ba->id,
                                null,
                                MOD_BOOKING_BO_SUBMIT_STATUS_UN_CONFIRM,
                                "",
                                0
                            );
                        }
                    }
                }
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
        } else {
            throw new \coding_exception(
                'confirm_bookinganswer_by_rule_adhoc task: ERROR - missing taskdata.'
            );
        }
    }
}
