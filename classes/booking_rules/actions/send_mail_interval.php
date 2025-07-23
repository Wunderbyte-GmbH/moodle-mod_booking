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
use mod_booking\placeholders\placeholders_info;
use mod_booking\task\send_mail_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * action how to identify concerned users by matching booking option field and user profile field.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_mail_interval implements booking_rule_action {
    /** @var string $actionname */
    public $actionname = 'send_mail_interval';

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $ruleid */
    public $ruleid = null;

    /** @var string $subject */
    public $subject = null;

    /** @var string $template */
    public $template = null;

    /** @var int $interval is set in minutes */
    public $interval = 0;

    /** @var int $counter */
    public $counter = 0;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule action record from DB
     */
    public function set_actiondata(stdClass $record) {
        $this->set_actiondata_from_json($record->rulejson);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_actiondata_from_json(string $json) {
        $this->rulejson = $json;
        $jsonobject = json_decode($json);
        $actiondata = $jsonobject->actiondata;
        $this->subject = $actiondata->subject;
        $this->template = $actiondata->template;
        $this->interval = $actiondata->interval;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {

        // Here we can set the interval in which the mails will be released.
        $mform->addElement(
            'text',
            'action_send_mail_interval_interval',
            get_string('interval', 'mod_booking')
        );
        $mform->addHelpButton('action_send_mail_interval_interval', 'interval', 'mod_booking');
        $mform->setType('action_send_mail_interval_interval', PARAM_INT);
        $mform->setDefault('action_send_mail_interval_interval', 60);

        // Mail subject.
        $mform->addElement(
            'text',
            'action_send_mail_interval_subject',
            get_string('messagesubject', 'mod_booking'),
            ['size' => '66']
        );
        $mform->setType('action_send_mail_interval_subject', PARAM_TEXT);

        // Mail template.
        $mform->addElement(
            'editor',
            'action_send_mail_interval_template',
            get_string('message'),
            ['rows' => 15],
            ['subdirs' => 0, 'maxfiles' => 0, 'context' => null]
        );

        $placeholders = placeholders_info::return_list_of_placeholders();
        $mform->addElement('html', get_string('helptext:placeholders', 'mod_booking', $placeholders));
    }

    /**
     * Get the name of the rule action
     * @param bool $localized
     * @return string the name of the rule action
     */
    public function get_name_of_action($localized = true) {
        return get_string('sendmailinterval', 'mod_booking');
    }

    /**
     * Is the booking rule action compatible with the current form data?
     * @param array $ajaxformdata the ajax form data entered by the user
     * @return bool true if compatible, else false
     */
    public function is_compatible_with_ajaxformdata(array $ajaxformdata = []) {
        return true;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_action(stdClass &$data): void {
        global $DB;

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->name = $data->name ?? $this->actionname;
        $jsonobject->actionname = $this->actionname;
        $jsonobject->actiondata = new stdClass();
        $jsonobject->actiondata->interval = $data->action_send_mail_interval_interval ?? 60;
        $jsonobject->actiondata->subject = $data->action_send_mail_interval_subject;
        $jsonobject->actiondata->template = $data->action_send_mail_interval_template['text'];
        $jsonobject->actiondata->templateformat = $data->action_send_mail_interval_template['format'];

        $data->rulejson = json_encode($jsonobject);
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $jsonobject = json_decode($record->rulejson);
        $actiondata = $jsonobject->actiondata;

        $data->action_send_mail_interval_interval = $actiondata->interval ?? 60;
        $data->action_send_mail_interval_subject = $actiondata->subject;
        $data->action_send_mail_interval_template = [];
        $data->action_send_mail_interval_template['text'] = $actiondata->template;
        $data->action_send_mail_interval_template['format'] = $actiondata->templateformat;
    }

    /**
     * Execute the action.
     * The stdclass has to have the keys userid, optionid & cmid & nextruntime.
     * @param stdClass $record
     */
    public function execute(stdClass $record) {
        global $DB;

        // This will be potentially run multiple times in a loop.
        // The first time, we just want to send the normal mail.
        // But we need to give the task already the information that we want to repeat this.
        // And we need to store the information, to which user we have sent this information already.
        // Then we abort.
        // When the same action is run again, we will see the information that this is a rerun.
        // We check if the currently to treat record was already treated (user 1 on waintinlist might still be user one).
        // If that's the case, we skip it.
        // We send message to next user.

        $interval = $this->interval;

        $nextruntime = $record->nextruntime;

        $jsonobject = json_decode($this->rulejson);
        $repeat = 0;

        if (!isset($jsonobject->intervaldata)) {
            $jsonobject->intervaldata = (object)[
                'nextruntime' => $nextruntime,
                'usersalreadytreated' => [],
                'interval' => $interval,
            ];
        } else {
            // If we are dealing with an interval execution...
            // We first check if the current user has already been treated.
            // If so, we abort.
            if (in_array($record->userid, $jsonobject->intervaldata->usersalreadytreated)) {
                return;
            }
        }

        if ($this->counter === 0) {
            // If it's a new user, we store the information.
            $jsonobject->intervaldata->usersalreadytreated[] = $record->userid;
            $userid = $record->userid;
        } else if ($this->counter === 1) {
            // If this is the second user, we set the repeat flag.
            $repeat = 1;
            // The next execution will be delayed.
            $nextruntime = $nextruntime + $interval * 60;
        } else if ($this->counter > 1) {
            return;
        }

        $this->rulejson = json_encode($jsonobject);

        $this->counter++;

        // Create adhioc to set confirmation settings for the booking answer record.
        $action = new confirm_bookinganswer();
        $action->set_next_runtime_for_adhoc($nextruntime);
        $action->set_ruleid($this->ruleid);
        $action->execute($record);

        // Create adhoc for sending email.
        $task = new send_mail_by_rule_adhoc();
        $taskdata = [
            // We need the JSON, so we can check if the rule still applies...
            // ...on task execution.
            'rulename' => $record->rulename,
            'ruleid' => $this->ruleid,
            'rulejson' => $this->rulejson,
            'userid' => $record->userid,
            'optionid' => $record->optionid,
            'cmid' => $record->cmid,
            'customsubject' => $this->subject,
            'custommessage' => $this->template,
            'repeat' => $repeat,
        ];
        // Only add the optiondateid if it is set.
        // We need it for session reminders.
        if (!empty($record->optiondateid)) {
            $taskdata['optiondateid'] = $record->optiondateid;
        }
        $task->set_custom_data($taskdata);
        $task->set_userid($record->userid);

        $task->set_next_run_time($nextruntime);

        // Now queue the task or reschedule it if it already exists (with matching data).
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }
}
