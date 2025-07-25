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

namespace mod_booking\booking_rules\actions;

use mod_booking\booking_rules\booking_rule_action;
use mod_booking\task\confirm_bookinganswer_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Action to create an ad-hoc task that confirms booking answers with a price,
 * or sets the confirmation JSON for booking answers with no price for persons on the waiting list.
 *
 * The ad-hoc task will execute only if 'confirmationonnotification' is enabled.
 *
 * If 'confirmationonnotification' is equal to 2, the task will set the confirmation
 * only for only one person at a time from the waiting list.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm_bookinganswer implements booking_rule_action {
    /** @var string $actionname */
    public $actionname = 'confirm_bookinganswer';

    /** @var int $ruleid */
    public $ruleid = null;

    /**
     * The adhoc task will be runned at this time.
     * @var int $actionname
     */
    public $adhocnextruntime = 0;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule action record from DB
     */
    public function set_actiondata(stdClass $record) {
        // Nothing to set.
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_actiondata_from_json(string $json) {
        // Nothing to set.
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {
        // No form.
    }

    /**
     * Get the name of the rule action
     * @param bool $localized
     * @return string the name of the rule action
     */
    public function get_name_of_action($localized = true) {
        return get_string('confirmbookinganswer', 'mod_booking');
    }

    /**
     * Is the booking rule action compatible with the current form data?
     * @param array $ajaxformdata the ajax form data entered by the user
     * @return bool true if compatible, else false
     */
    public function is_compatible_with_ajaxformdata(array $ajaxformdata = []) {
        return false;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_action(stdClass &$data): void {
        // Nothing to save.
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        // Nothing to set.
    }

    /**
     * Execute the action.
     * The stdclass has to have the keys userid, optionid & cmid & nextruntime.
     * @param stdClass $record
     */
    public function execute(stdClass $record) {
        $task = new confirm_bookinganswer_by_rule_adhoc();

        $taskdata = [
            'rulename' => $record->rulename,
            'ruleid' => $this->ruleid,
            'userid' => $record->userid,
            'optionid' => $record->optionid,
            'cmid' => $record->cmid,
        ];
        // Only add the optiondateid if it is set.
        // We need it for session reminders.
        if (!empty($record->optiondateid)) {
            $taskdata['optiondateid'] = $record->optiondateid;
        }
        $task->set_custom_data($taskdata);
        $task->set_userid($record->userid);

        if ($this->adhocnextruntime !== 0) {
            $task->set_next_run_time($this->adhocnextruntime);
        }

        // Now queue the task or reschedule it if it already exists (with matching data).
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Summary of set_next_runtime
     * @param mixed $timeinseconds
     * @return void
     */
    public function set_next_runtime_for_adhoc($timeinseconds) {
        $this->adhocnextruntime = $timeinseconds;
    }

    /**
     * Setter for ruleid.
     * @param mixed $ruleid
     * @return void
     */
    public function set_ruleid($ruleid) {
        $this->ruleid = $ruleid;
    }
}
