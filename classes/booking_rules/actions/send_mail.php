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

use mod_booking\booking_rules\booking_rule;
use mod_booking\booking_rules\booking_rule_action;
use mod_booking\singleton_service;
use mod_booking\task\send_mail_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * action how to identify concerned users by matching booking option field and user profile field.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_mail implements booking_rule_action {

    /** @var string $rulename */
    public $actionname = 'send_mail';

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $ruleid */
    public $ruleid = null;

    /** @var string $subject */
    public $subject = null;

    /** @var string $template */
    public $template = null;

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
        $mform->addElement('text', 'action_send_mail_subject', get_string('messagesubject', 'mod_booking'),
            ['size' => '66']);
        $mform->setType('action_send_mail_subject', PARAM_TEXT);

        // Mail template.
        $mform->addElement('editor', 'action_send_mail_template',
            get_string('message'), ['rows' => 15], ['subdirs' => 0, 'maxfiles' => 0, 'context' => null]);

        $mform->addElement('html', get_string('helptext:placeholders', 'mod_booking'));

    }

    /**
     * Get the name of the rule action
     * @param bool $localized
     * @return string the name of the rule action
     */
    public function get_name_of_action($localized = true) {
        return get_string('send_mail', 'mod_booking');
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_action(stdClass &$data) {
        global $DB;

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->name = $data->name ?? $this->actionname;
        $jsonobject->actionname = $this->actionname;
        $jsonobject->actiondata = new stdClass();
        $jsonobject->actiondata->subject = $data->action_send_mail_subject;
        $jsonobject->actiondata->template = $data->action_send_mail_template['text'];
        $jsonobject->actiondata->templateformat = $data->action_send_mail_template['format'];

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

        $data->action_send_mail_subject = $actiondata->subject;
        $data->action_send_mail_template = [];
        $data->action_send_mail_template['text'] = $actiondata->template;
        $data->action_send_mail_template['format'] = $actiondata->templateformat;
    }

    /**
     * Execute the action.
     * The stdclass has to have the keys userid, optionid & cmid & nextruntime.
     * @param stdClass $record
     */
    public function execute(stdClass $record) {
        global $DB;

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
        ];
        $task->set_custom_data($taskdata);
        $task->set_userid($record->userid);

        $task->set_next_run_time($record->nextruntime);

        // Now queue the task or reschedule it if it already exists (with matching data).
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }
}
