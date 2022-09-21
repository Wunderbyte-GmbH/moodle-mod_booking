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
 * Base class for booking rules information.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules;

use core_component;
use MoodleQuickForm;

/**
 * Class for additional information of booking rules.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules_info {

    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public static function add_rules_to_mform(MoodleQuickForm &$mform,
        array &$repeatedrules, array &$repeateloptions) {

        $rules = self::get_rules();

        $rulesforselect = [];
        foreach ($rules as $rule) {
            $fullclassname = get_class($rule); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $rulesforselect[$shortclassname] = $rule->get_name_of_rule();
        }

        $repeatedrules[] = $mform->createElement('select', 'bookingrule',
                get_string('bookingrule', 'mod_booking'), $rulesforselect);

        foreach ($rules as $rule) {
            // For each rule, add the appropriate form fields.
            $rule->add_rule_to_mform($mform, $repeatedrules, $repeateloptions);
        }

        // Delete rule button.
        $repeatedrules[] = $mform->createElement('submit', 'deletebookingrule',
            get_string('deletebookingrule', 'mod_booking'));
    }

    /**
     * Get all booking rules.
     * @return array an array of booking rules (instances of class booking_rule).
     */
    public static function get_rules() {
        global $CFG;

        // First, we get all the available rules from our directory.
        $path = $CFG->dirroot . '/mod/booking/classes/booking_rules/rules/*.php';
        $filelist = glob($path);

        $rules = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\booking_rules\rules\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $rules[] = $instance;
            }
        }

        return $rules;
    }
}
