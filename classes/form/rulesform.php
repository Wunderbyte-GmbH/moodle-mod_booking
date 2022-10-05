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

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use mod_booking\booking_rules\rules_info;
use moodleform;

/**
 * Dynamic optiondate form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rulesform extends moodleform {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        // Repeated elements.
        $repeatedrules = [];

        // Options to store help button texts etc.
        $repeateloptions = [];

        rules_info::add_rules_to_mform($mform, $repeatedrules, $repeateloptions);

        // Get number of existing rules from DB.
        if ($rulerecords = $DB->get_records('booking_rules')) {
            $numberofrulestoshow = count($rulerecords);
        } else {
            $numberofrulestoshow = 0;
        }

        $this->repeat_elements($repeatedrules, $numberofrulestoshow, $repeateloptions,
            'rulesno', 'addbookingrule', 1, get_string('addbookingrule', 'mod_booking'), true,
            'deletebookingrule');

        // Add submit button to create optiondate series. (Use $this, not $mform).
        $this->add_action_buttons(false);
    }

    /**
     * Validate dates.
     *
     * {@inheritdoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {
        $errors = array();
        foreach ($data['bookingrule'] as $idx => $value) {
            if (isset($data['rule_sendmail_daysbefore_days'][$idx]) &&
                $data['rule_sendmail_daysbefore_days'][$idx] == '0') {
                $errors["rule_sendmail_daysbefore_days[$idx]"] = get_string('error:nofieldchosen', 'mod_booking');
            }
            if (isset($data['rule_sendmail_daysbefore_datefield'][$idx]) &&
                $data['rule_sendmail_daysbefore_datefield'][$idx] == '0') {
                $errors["rule_sendmail_daysbefore_datefield[$idx]"] = get_string('error:nofieldchosen', 'mod_booking');
            }
            if (isset($data['rule_sendmail_daysbefore_cpfield'][$idx]) &&
                $data['rule_sendmail_daysbefore_cpfield'][$idx] == '0') {
                $errors["rule_sendmail_daysbefore_cpfield[$idx]"] = get_string('error:nofieldchosen', 'mod_booking');
            }
            if (isset($data['rule_sendmail_daysbefore_optionfield'][$idx]) &&
                $data['rule_sendmail_daysbefore_optionfield'][$idx] == '0') {
                $errors["rule_sendmail_daysbefore_optionfield[$idx]"] = get_string('error:nofieldchosen', 'mod_booking');
            }
            if (isset($data['rule_sendmail_daysbefore_subject'][$idx]) &&
                empty($data['rule_sendmail_daysbefore_subject'][$idx])) {
                $errors["rule_sendmail_daysbefore_subject[$idx]"] = get_string('error:mustnotbeempty', 'mod_booking');
            }
            if (isset($data['rule_sendmail_daysbefore_template'][$idx]['text']) &&
                empty($data['rule_sendmail_daysbefore_template'][$idx]['text'])) {
                $errors["rule_sendmail_daysbefore_template_group[$idx]"] = get_string('error:mustnotbeempty', 'mod_booking');
            }
        }
        return $errors;
    }

    /**
     * Get data.
     * @return object $data
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}
