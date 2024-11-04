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
use mod_booking\event\bookinganswercustomformconditions_deleted;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use Exception;
use mod_booking\booking_rules\rules_info;
use mod_booking\event\booking_debug;
use mod_booking\message_controller;
use mod_booking\singleton_service;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle adhoc Task to send a mail by a rule at a certain time.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_conditions_from_bookinganswer_by_rule_adhoc extends \core\task\adhoc_task {

    /**
     * Get task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('deletedatafrombookingansweradhoc', 'mod_booking');
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
        mtrace('delete_conditions_from_bookinganswer_by_rule_adhoc task:
        checking if data should be deleted from option from bookinganswer '
        . $taskdata->baid );

        if ($taskdata != null) {
            if (!$ruleinstance = $DB->get_record('booking_rules', ['id' => $taskdata->ruleid])) {
                mtrace('delete_conditions_from_bookinganswer_by_rule_adhoc task: Rule does not exist anymore.
                Action was NOT EXECUTED for bookinganswer ' .
                $taskdata->baid);
                return;
            }

            if (empty($ruleinstance)) {
                return;
            }

            // The first check needs to be if the rule has changed at all, eg. in any of the set values.
            if (
                $ruleinstance->rulename === 'days_before'
                && ($taskdata->rulejson !== $ruleinstance->rulejson)
            ) {
                mtrace('delete_conditions_from_bookinganswer_by_rule_adhoc task: Rule has changed.
                   Action was NOT EXECUTED for bookinganswer ' .
                    $taskdata->baid
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
                mtrace('delete_conditions_from_bookinganswer_by_rule_adhoc task: Rule does not apply anymore.
                Action was NOT EXECUTED for bookinganswer ' .
                $taskdata->baid
                );
                return;
            }

            // We might receive an error here, because we refer to baid which no longer exist.
            // That's not a problem, we just abort the action.
            try {
                // We should read answers from cache!
                $settings = singleton_service::get_instance_of_booking_option_settings($taskdata->optionid);
                $answers = singleton_service::get_instance_of_booking_answers($settings);
                $ba = $answers->answers[$taskdata->baid];
                // Decode the JSON to an associative array.
                $data = json_decode($ba->json, true);
                $change = false;
                // Check if 'condition_customform' key is set.
                if (isset($data['condition_customform'])) {
                    // Can be defined from user or from admin (teacher).
                    if ((isset($data['condition_customform']['customform_deleteinfoscheckboxuser'])
                    && !empty($data['condition_customform']['customform_deleteinfoscheckboxuser']))
                    || (isset($data['condition_customform']['deleteinfoscheckboxadmin'])
                    && !empty($data['condition_customform']['deleteinfoscheckboxadmin']))) {
                        // Remove 'condition_customform' key and its value.
                        unset($data['condition_customform']);
                        $change = true;
                    }
                }
                if ($change) {
                    $data = (object) $data;
                    $data = json_encode($data);
                    $ba->json = $data;
                    $DB->update_record('booking_answers', $ba);

                    global $USER;
                    $event = bookinganswercustomformconditions_deleted::create([
                        'objectid' => $taskdata->baid,
                        'context' => \context_module::instance($taskdata->cmid),
                        'userid' => $USER->id,
                        'relateduserid' => $taskdata->userid,
                         'other' => [
                            'cmid' => $taskdata->cmid,
                            'optionid' => $taskdata->optionid,
                            'bookinganswerid' => $taskdata->baid,
                            'columnconcerened' => 'json',
                         ],
                    ]);
                    $event->trigger();
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
                            'bookinganswerid' => $taskdata->baid ?? 0,
                        ],
                    ]);
                    $event->trigger();
                }
                return;
            }
        } else {
            throw new \coding_exception(
                    'delete_conditions_from_bookinganswer_by_rule_adhoc task: ERROR - missing taskdata.');
        }
    }
}
