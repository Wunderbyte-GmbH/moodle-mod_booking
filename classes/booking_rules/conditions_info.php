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
 * Base class for booking conditions information.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules;

use MoodleQuickForm;
use stdClass;

/**
 * Class for additional information of booking conditions.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conditions_info {

    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public static function add_conditions_to_mform(MoodleQuickForm &$mform,
        array &$repeatedconditions, array &$repeateloptions) {

        $conditions = self::get_conditions();

        $conditionsforselect = [];
        foreach ($conditions as $condition) {
            $fullclassname = get_class($condition); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $conditionsforselect[$shortclassname] = $condition->get_name_of_condition();
        }

        $repeatedconditions[] = $mform->createElement('html', '<hr>');
        $repeatedconditions[] = $mform->createElement('select', 'bookingcondition',
                get_string('bookingcondition', 'mod_booking') . ' {no}', $conditionsforselect);

        foreach ($conditions as $condition) {
            // For each condition, add the appropriate form fields.
            $condition->add_condition_to_mform($mform, $repeatedconditions, $repeateloptions);
        }

        // Delete condition button.
        $repeatedconditions[] = $mform->createElement('submit', 'deletebookingcondition',
            get_string('deletebookingcondition', 'mod_booking'));
    }

    /**
     * Get all booking conditions.
     * @return array an array of booking conditions (instances of class booking_condition).
     */
    public static function get_conditions() {
        global $CFG;

        // First, we get all the available conditions from our directory.
        $path = $CFG->dirroot . '/mod/booking/classes/booking_rules/conditions/*.php';
        $filelist = glob($path);

        $conditions = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\booking_rules\conditions\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $conditions[] = $instance;
            }
        }

        return $conditions;
    }

    /**
     * Execute all booking conditions.
     */
    public static function execute_booking_conditions() {
        global $DB;
        if ($records = $DB->get_records('booking_conditions')) {
            foreach ($records as $record) {
                $conditionfullpath = "\\mod_booking\\booking_rules\\conditions\\" . $record->conditionname;
                $condition = new $conditionfullpath;
                // Important: Load the condition data from JSON into the condition instance.
                $condition->set_conditiondata($record);
                // Now the condition can be executed.
                $condition->execute();
            }
        }
    }

    /**
     * After an option has been added or updated,
     * we need to check if any conditions need to be applied or changed.
     * @param int $optionid
     */
    public static function check_conditions_for_option(int $optionid) {
        global $DB;
        if ($records = $DB->get_records('booking_conditions')) {
            foreach ($records as $record) {
                $conditionfullpath = "\\mod_booking\\booking_rules\\condition\\" . $record->conditionname;
                $condition = new $conditionfullpath;
                // Important: Load the condition data from JSON into the condition instance.
                $condition->set_conditiondata($record);
                // Now the condition can be executed.
                $condition->execute($optionid);
            }
        }
    }

    /**
     * After a user has been added or updated,
     * we need to check if any conditions need to be applied or changed.
     * @param int $userid
     */
    public static function check_rules_for_user(int $userid) {
        global $DB;
        if ($records = $DB->get_records('booking_rules')) {
            foreach ($records as $record) {
                $conditionfullpath = "\\mod_booking\\booking_rules\\conditions\\" . $record->conditioname;
                $condition = new $conditionfullpath;
                // Important: Load the condition data into the condition instance.
                $condition->set_conditiondata($record);
                // Now the condition can be executed.
                $condition->execute(null, $userid);
            }
        }
    }
}
