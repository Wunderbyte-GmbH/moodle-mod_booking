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

namespace mod_booking\booking_rules\rules;

use context_system;
use mod_booking\booking_rules\booking_rule;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Rule to send a mail notification based on an event and additional settings.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_sendmail implements booking_rule {

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform,
        array &$repeatedrules, array &$repeateloptions) {
        global $DB;

        // Get a list of all booking events.
        $bookingevents = get_list_of_booking_events();

        // Workaround: We need a group to get hideif to work.
        $groupitems = [];
        $groupitems[] = $mform->createElement('static', 'rule_sendmail_desc', '', get_string('rule_sendmail_desc', 'mod_booking'));
        $repeatedrules[] = $mform->createElement('group', 'rule_sendmail_desc_group', '',
            $groupitems, null, false);
        $repeateloptions['rule_sendmail_desc_group']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail');

        // Event which should trigger the rule.
        $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_event',
            get_string('ruleevent', 'mod_booking'), $bookingevents);
        $repeateloptions['rule_sendmail_event']['type'] = PARAM_TEXT;
        $repeateloptions['rule_sendmail_event']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail');

        // Custom user profile field to be checked.
        $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
        if (!empty($customuserprofilefields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('userinfofieldoff', 'mod_booking');

            // Create an array of key => value pairs for the dropdown.
            foreach ($customuserprofilefields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
            }

            $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_customuserprofilefield',
                get_string('rule_sendmail_customuserprofilefield', 'mod_booking'), $customuserprofilefieldsarray);
            $repeateloptions['rule_sendmail_customuserprofilefield']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail');

            $operators = [
                '=' => get_string('equals', 'mod_booking'),
                '!=' => get_string('equalsnot', 'mod_booking'),
                '<' => get_string('lowerthan', 'mod_booking'),
                '>' => get_string('biggerthan', 'mod_booking'),
                '~' => get_string('contains', 'mod_booking'),
                '!~' => get_string('containsnot', 'mod_booking'),
                '[]' => get_string('inarray', 'mod_booking'),
                '[!]' => get_string('notinarray', 'mod_booking'),
                '()' => get_string('isempty', 'mod_booking'),
                '(!)' => get_string('isnotempty', 'mod_booking')
            ];
            $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_customuserprofilefield_operator',
                get_string('rule_sendmail_customuserprofilefield_operator', 'mod_booking'), $operators);
            $repeateloptions['rule_sendmail_customuserprofilefield_operator']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail');

            $repeatedrules[] = $mform->createElement('text', 'rule_sendmail_customuserprofilefield_value',
                get_string('rule_sendmail_customuserprofilefield_value', 'mod_booking'));
            $repeateloptions['rule_sendmail_customuserprofilefield_value']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail');
        }

        // Mail template. We need to use text area as editor does not work correctly.
        $repeatedrules[] = $mform->createElement('textarea', 'rule_sendmail_template',
            get_string('rule_sendmail_template', 'mod_booking'), 'wrap="virtual" rows="20" cols="25"');
        $repeateloptions['rule_sendmail_template']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail');

    }

    /**
     * Get the name of the rule.
     * @return string the name of the rule
     */
    public function get_name_of_rule() {
        return get_string('rule_sendmail', 'mod_booking');
    }
}
