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

use MoodleQuickForm;
use stdClass;

/**
 * Class for additional information of booking rules.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actions_info {

    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @param array|null $ajaxformdata
     * @return void
     */
    public static function add_actions_to_mform(MoodleQuickForm &$mform,
        array &$repeateloptions,
        array &$ajaxformdata = null) {

        $actions = self::get_actions();

        $actionsforselect = [];
        foreach ($actions as $action) {
            $fullclassname = get_class($action); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $actionsforselect[$shortclassname] = $action->get_name_of_action();
        }

        $mform->registerNoSubmitButton('btn_bookingruleactiontype');
        $buttonargs = array('style' => 'visibility:hidden;');
        $categoryselect = [
            $mform->createElement('select', 'bookingruleactiontype',
            get_string('bookingruleaction', 'mod_booking'), $actionsforselect),
            $mform->createElement('submit', 'btn_bookingruleactiontype', get_string('bookingruleaction', 'mod_booking'), $buttonargs)
        ];
        $mform->addGroup($categoryselect, 'bookingruleactiontype', get_string('bookingruleaction', 'mod_booking'), [' '], false);
        $mform->setType('btn_bookingruleactiontype', PARAM_NOTAGS);

        foreach ($actions as $action) {

            if ($ajaxformdata && isset($ajaxformdata['bookingruleactiontype'])) {

                $actionname = $action->get_name_of_action();
                if ($ajaxformdata['bookingruleactiontype']
                    && $actionname == get_string($ajaxformdata['bookingruleactiontype'], 'mod_booking')) {
                    // For each rule, add the appropriate form fields.
                    $action->add_action_to_mform($mform, $repeateloptions);
                }
            } else {
                // We only render the first rule.
                $action->add_action_to_mform($mform, $repeateloptions);
                break;
            }
        }
    }

    /**
     * Get all booking rules actions.
     * @return array an array of booking rule actions (instances of class booking_rule_action).
     */
    public static function get_actions() {
        global $CFG;

        // First, we get all the available rules from our directory.
        $path = $CFG->dirroot . '/mod/booking/classes/booking_rules/actions/*.php';
        $filelist = glob($path);

        $actions = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\booking_rules\actions\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $actions[] = $instance;
            }
        }

        return $actions;
    }

    /**
     * Get booking rule action by name.
     * @param string $actionname
     * @return mixed
     */
    public static function get_action(string $actionname) {
        global $CFG;

        $filename = 'mod_booking\booking_rules\actions\\' . $actionname;

        // We instantiate all the classes, because we need some information.
        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Save all booking rule acitons.
     * @param stdClass &$data reference to the form data
     * @return void
     */
    public static function save_booking_actions(stdClass &$data) {
        global $DB;
        // Truncate the table.
        $DB->delete_records('booking_rules');
        // Then save all rules specified in the form.
        $actions = self::get_actions();
        foreach ($actions as $action) {
            $action::save_actions($data);
        }
        return;
    }

    /**
     * Execute all booking rules.
     */
    public static function execute_booking_rules() {
        global $DB;
        if ($records = $DB->get_records('booking_rules')) {
            foreach ($records as $record) {
                $rule = rules_info::get_rule($record->rulename);
                // Important: Load the rule data from JSON into the rule instance.
                $rule->set_ruledata($record);
                // Now the rule can be executed.
                $rule->execute();
            }
        }
    }

    /**
     * After an option has been added or updated,
     * we need to check if any rules need to be applied or changed.
     * @param int $optionid
     */
    public static function check_actions_for_option(int $optionid) {
        global $DB;
        if ($records = $DB->get_records('booking_rules')) {
            foreach ($records as $record) {
                if (!$rule = rules_info::get_rule($record->conditionname)) {
                    continue;
                }
                // Important: Load the rule data from JSON into the rule instance.
                $rule->set_ruledata($record);
                // Now the rule can be executed.
                $rule->execute($optionid);
            }
        }
    }

    /**
     * After a user has been added or updated,
     * we need to check if any rules need to be applied or changed.
     * @param int $userid
     */
    public static function check_actions_for_user(int $userid) {
        global $DB;
        if ($records = $DB->get_records('booking_rules')) {
            foreach ($records as $record) {
                if (!$rule = self::get_action($record->actionname)) {
                    continue;
                }
                // Important: Load the rule data into the rule instance.
                $rule->set_ruledata($record);
                // Now the rule can be executed.
                $rule->execute(null, $userid);
            }
        }
    }
}
