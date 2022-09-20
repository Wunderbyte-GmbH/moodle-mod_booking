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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules;

use mod_booking\booking_option_settings;
use MoodleQuickForm;

/**
 * Base class for a single booking rule.
 *
 * All booking rules must extend this interface.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface booking_rule {

    /**
     * Adds the form elements for this rule to the provided mform.
     * @param MoodleQuickForm $mform the mform where the rule should be added
     * @param array $repeatedrules repeated rules
     * @param array $repateloptions options for repeated elements
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform, array &$repeatedrules, array &$repeateloptions);

    /**
     * Gets the human-readable name of a rule (localized).
     * @return string the name of the rule
     */
    public function get_name_of_rule();

}
