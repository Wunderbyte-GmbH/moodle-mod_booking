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

        $repeatedrules[] = $mform->createElement('hidden', 'rule_sendmail_id', 0);
        $mform->setType('rule_sendmail_id', PARAM_INT);

        // Get a list of all booking events.
        $bookingevents = get_list_of_booking_events();

        // Event which should trigger the rule.
        $repeatedrules[] = $mform->createElement('select', 'ruleevent', get_string('ruleevent', 'mod_booking'), $bookingevents);
            $mform->setType('ruleevent', PARAM_TEXT);

        // Delete rule button.
        $repeatedrules[] = $mform->createElement('submit', 'deletebookingrule', get_string('deletebookingrule', 'mod_booking'));
        $repeatedrules[] = $mform->createElement('html', '<hr/>');

    }

    /**
     * Get the name of the rule.
     * @return string the name of the rule
     */
    public function get_name_of_rule() {
        return get_string('rule_sendmail', 'mod_booking');
    }
}
