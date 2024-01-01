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

use Exception;
use MoodleQuickForm;
use stdClass;

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
     * @param array $repeateloptions
     * @param array $ajaxformdata
     * @return void
     */
    public static function add_rules_to_mform(MoodleQuickForm &$mform,
        array &$repeateloptions,
        array &$ajaxformdata = null) {

        // First, get all the type of rules there are.
        $rules = self::get_rules();

        $rulesforselect = [];
        $rulesforselect['0'] = get_string('choose...', 'mod_booking');
        foreach ($rules as $rule) {
            $fullclassname = get_class($rule); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $rulesforselect[$shortclassname] = $rule->get_name_of_rule();
        }

        // The custom name of the role has to be at this place, but every rule will implement save and set of rule_name.
        $mform->addElement('text', 'rule_name',
            get_string('rule_name', 'mod_booking'), ['size' => '50']);
        $mform->setType('rule_name', PARAM_TEXT);
        $repeateloptions['rule_name']['type'] = PARAM_TEXT;

        $mform->registerNoSubmitButton('btn_bookingruletype');
        $buttonargs = ['class' => 'd-none'];
        $categoryselect = [
            $mform->createElement('select', 'bookingruletype',
            get_string('bookingrule', 'mod_booking'), $rulesforselect),
            $mform->createElement('submit', 'btn_bookingruletype', get_string('bookingrule', 'mod_booking'), $buttonargs),
        ];
        $mform->addGroup($categoryselect, 'bookingruletype', get_string('bookingrule', 'mod_booking'), [' '], false);
        $mform->setType('btn_bookingruletype', PARAM_NOTAGS);

        if (isset($ajaxformdata['bookingruletype'])) {
            $rule = self::get_rule($ajaxformdata['bookingruletype']);
        } else {
            list($rule) = $rules;
        }

        // We skip if no rule was selected.
        if (empty($rule)) {
            return;
        }

        $rule->add_rule_to_mform($mform, $repeateloptions);

        $mform->addElement('html', '<hr>');

        // At this point, we also load the conditions.
        conditions_info::add_conditions_to_mform($mform, $ajaxformdata);

        $mform->addElement('html', '<hr>');

        // Finally, we load the actions.
        actions_info::add_actions_to_mform($mform, $repeateloptions, $ajaxformdata);
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
            $filename = 'mod_booking\\booking_rules\\rules\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $rules[] = $instance;
            }
        }

        return $rules;
    }

    /**
     * Get booking rule by name.
     * @param string $rulename
     * @return mixed
     */
    public static function get_rule(string $rulename) {

        $filename = 'mod_booking\\booking_rules\\rules\\' . $rulename;

        // We instantiate all the classes, because we need some information.
        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Prepare data to set data to form.
     *
     * @param object $data
     * @return object
     */
    public static function set_data_for_form(object &$data) {

        global $DB;

        if (empty($data->id)) {
            // Nothing to set, we return empty object.
            return new stdClass();
        }

        // If we have an ID, we retrieve the right rule from DB.
        $record = $DB->get_record('booking_rules', ['id' => $data->id]);

        $rule = self::get_rule($record->rulename);

        $rulejsonobject = json_decode($record->rulejson);

        $condition = conditions_info::get_condition($rulejsonobject->conditionname);
        $action = actions_info::get_action($rulejsonobject->actionname);

        // These function just add their bits to the object.
        $condition->set_defaults($data, $record);
        $action->set_defaults($data, $record);
        $rule->set_defaults($data, $record);

        return (object)$data;

    }

    /**
     * Save all booking rules.
     * @param stdClass $data reference to the form data
     * @return void
     */
    public static function save_booking_rule(stdClass &$data) {

        // We receive the form with the data depending on the used handlers.
        // As we know which handler to call, we only instantiate one rule.
        $rule = self::get_rule($data->bookingruletype);
        $condition = conditions_info::get_condition($data->bookingruleconditiontype);
        $action = actions_info::get_action($data->bookingruleactiontype);

        // These function don't really save to DB, they just add the values to the rulejson key.
        $condition->save_condition($data);
        $action->save_action($data);

        // Rule has to be saved last, because it actually writes to DB.
        $rule->save_rule($data);

        self::execute_booking_rules();

        return;
    }

    /**
     * Execute all booking rules.
     */
    public static function execute_booking_rules() {
        global $DB;
        if ($records = $DB->get_records('booking_rules')) {
            foreach ($records as $record) {
                if (!$rule = self::get_rule($record->rulename)) {
                    continue;
                }
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
    public static function execute_rules_for_option(int $optionid) {
        global $DB;

        // Only fetch rules which need to be reapplied. At the moment, it's just one.
        // Eventbased rules don't have to be reapplied.
        if ($records = $DB->get_records('booking_rules', ['rulename' => 'rule_daysbefore'])) {
            foreach ($records as $record) {
                if (!$rule = self::get_rule($record->rulename)) {
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
    public static function execute_rules_for_user(int $userid) {
        global $DB;
        // Only fetch rules which need to be reapplied. At the moment, it's just one.
        // Eventbased rules don't have to be reapplied.
        if ($records = $DB->get_records('booking_rules', ['rulename' => 'rule_daysbefore'])) {
            foreach ($records as $record) {
                if (!$rule = self::get_rule($record->rulename)) {
                    continue;
                }
                // Important: Load the rule data into the rule instance.
                $rule->set_ruledata($record);
                // Now the rule can be executed.
                $rule->execute(0, $userid);
            }
        }
    }

    /**
     * Delete a booking rule by its ID.
     * @param int $ruleid the ID of the rule
     */
    public static function delete_rule(int $ruleid) {
        global $DB;
        $DB->delete_records('booking_rules', ['id' => (int)$ruleid]);
    }
}
