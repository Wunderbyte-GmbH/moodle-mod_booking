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

        $mform->addElement(
            'static',
            'mailintervalwarning',
            '',
            get_string('mailintervalwarning', 'mod_booking'),
        );

        // Here we can set the interval in which the mails will be released.
        $mform->addElement(
            'text',
            'action_send_mail_interval_interval',
            get_string('interval', 'mod_booking')
        );
        $mform->addHelpButton('action_send_mail_interval_interval', 'interval', 'mod_booking');
        $mform->setType('action_send_mail_interval_interval', PARAM_INT);
        $mform->setDefault('action_send_mail_interval_interval', 60);

        // Placeholders info text.
        $placeholders = placeholders_info::return_list_of_placeholders();
        $mform->addElement('html', get_string('helptext:placeholders', 'mod_booking', $placeholders));

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
     *
     * Deliberately a no-op since the waitlist-progression refactoring (Phase 3,
     * WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.5): this action's only remaining role is as a
     * configuration source (interval/subject/template, read via rule_condition_checker and
     * progression's own offer_interval_seconds()/messaging_gateway) - the actual offering and
     * mailing now happens through progression::reconcile(), driven by the trigger adapters in
     * classes/event/observer/, not through this rule-engine dispatch path anymore. Kept as a
     * no-op rather than removed because booking_rule_action::execute() is a required interface
     * method and rule_react_on_event's dispatch still calls it for every matching candidate.
     *
     * @param stdClass $record
     */
    public function execute(stdClass $record) {
        // Intentionally empty - see docblock above.
    }
}
