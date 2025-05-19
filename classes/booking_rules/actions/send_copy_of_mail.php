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
use mod_booking\singleton_service;
use mod_booking\task\send_mail_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Action to send a copy of an email.
 * Needs to be combined with an event containing "subject" and "message"
 * in the "other"-array.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_copy_of_mail implements booking_rule_action {
    /** @var string $rulename */
    public $actionname = 'send_copy_of_mail';

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $ruleid */
    public $ruleid = null;

    /** @var string $subject */
    public $subject = null;

    /** @var string $message */
    public $message = null;

    /** @var array $compatibleevents */
    public $compatibleevents = [
        '\mod_booking\event\custom_message_sent',
        '\mod_booking\event\custom_bulk_message_sent',
    ];

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
        $event = $jsonobject->ruledata->boevent::restore((array)$jsonobject->datafromevent, []);

        $datafromevent = $event->get_data();

        $settings = singleton_service::get_instance_of_booking_option_settings($event->objectid);
        $fulltitle = $settings->get_title_with_prefix();
        $optionformatted = "<b>" . get_string('bookingoption', 'mod_booking') . "</b>: $fulltitle<br>";

        $userfrom = singleton_service::get_instance_of_user((int) $datafromevent['userid']);
        $userfromformatted = "$userfrom->firstname $userfrom->lastname &lt;$userfrom->email&gt;";
        $userfromformatted = "<b>" . get_string('from', 'mod_booking') . "</b>: $userfromformatted<br>";

        $usertoformatted = '';
        if (!empty($datafromevent['relateduserid'])) {
            $userto = singleton_service::get_instance_of_user((int) $datafromevent['relateduserid']);
            $usertoformatted .= "$userto->firstname $userto->lastname &lt;$userto->email&gt;";
            $usertoformatted = "<b>" . get_string('to', 'mod_booking') . "</b>: $usertoformatted<br>";
        }

        $this->subject = $actiondata->subjectprefix . ": " . $datafromevent['other']->subject;
        $this->message = "$actiondata->messageprefix<hr>" .
            $optionformatted . $userfromformatted . $usertoformatted . $datafromevent['other']->message;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {

        // Mail subject.
        $mform->addElement(
            'text',
            'action_send_copy_of_mail_subject_prefix',
            get_string('sendcopyofmailsubjectprefix', 'mod_booking'),
            ['size' => '33']
        );
        $mform->setType('action_send_copy_of_mail_subject_prefix', PARAM_TEXT);

        // Mail template.
        $mform->addElement(
            'editor',
            'action_send_copy_of_mail_message_prefix',
            get_string('sendcopyofmailmessageprefix', 'mod_booking'),
            ['rows' => 5],
            ['subdirs' => 0, 'maxfiles' => 0, 'context' => null]
        );

        // Placeholders info text.
        $placeholders = placeholders_info::return_list_of_placeholders();
        $mform->addElement('html', get_string('helptext:placeholders', 'mod_booking', $placeholders));
    }

    /**
     * Get the name of the rule action
     * @param bool $localized
     * @return string the name of the rule action
     */
    public function get_name_of_action($localized = true) {
        return get_string('sendcopyofmail', 'mod_booking');
    }

    /**
     * Is the booking rule action compatible with the current form data?
     * @param array $ajaxformdata the ajax form data entered by the user
     * @return bool true if compatible, else false
     */
    public function is_compatible_with_ajaxformdata(array $ajaxformdata = []) {
        // For compatible events we return true.
        if (
            isset($ajaxformdata["bookingruleactiontype"]) &&
            $ajaxformdata["bookingruleactiontype"] == "send_copy_of_mail"
        ) {
            return true;
        } else if (
            isset($ajaxformdata["rule_react_on_event_event"]) &&
            in_array($ajaxformdata["rule_react_on_event_event"], $this->compatibleevents)
        ) {
            return true;
        }
        // For anything else, it's not compatible and won't be shown.
        return false;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_action(stdClass &$data): void {

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->name = $data->name ?? $this->actionname;
        $jsonobject->actionname = $this->actionname;
        $jsonobject->actiondata = new stdClass();
        $jsonobject->actiondata->subjectprefix = $data->action_send_copy_of_mail_subject_prefix;
        $jsonobject->actiondata->messageprefix = $data->action_send_copy_of_mail_message_prefix['text'];

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

        $data->action_send_copy_of_mail_subject_prefix = $actiondata->subjectprefix;
        $data->action_send_copy_of_mail_message_prefix['text'] = $actiondata->messageprefix;
        $data->action_send_copy_of_mail_message_prefix['format'] = FORMAT_HTML;
    }

    /**
     * Execute the action.
     * The stdclass has to have the keys userid, optionid & cmid & nextruntime.
     * @param stdClass $record
     */
    public function execute(stdClass $record) {

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
            'custommessage' => $this->message,
        ];
        // Only add the optiondateid if it is set.
        // We need it for session reminders.
        if (!empty($record->optiondateid)) {
            $taskdata['optiondateid'] = $record->optiondateid;
        }
        $task->set_custom_data($taskdata);
        $task->set_userid($record->userid);

        $task->set_next_run_time($record->nextruntime);

        // Now queue the task or reschedule it if it already exists (with matching data).
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }
}
