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
 * Dummy rule.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_dummy implements booking_rule {

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform,
        array &$repeatedrules, array &$repeateloptions) {

        $groupitems = [];

        // Event description.
        $groupitems[] = $mform->createElement('static', 'rule_dummy_desc', '', get_string('rule_dummy_desc', 'mod_booking'));

        // Without a group hideif won't work with all elements.
        $repeatedrules[] = $mform->createElement('group', 'rule_dummy_group', get_string('rule_dummy', 'mod_booking'),
            $groupitems, null, false);
        $repeateloptions['rule_dummy_group']['hideif'] = array('bookingrule', 'neq', 'rule_dummy');
    }

    /**
     * Get the name of the rule.
     * @return string the name of the rule
     */
    public function get_name_of_rule() {
        return get_string('rule_dummy', 'mod_booking');
    }
}
