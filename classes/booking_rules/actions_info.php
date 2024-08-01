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
use mod_booking\booking_rules\booking_rule_action;

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
        ?array &$ajaxformdata = null) {

        $actions = self::get_actions();

        $actionsforselect = [];
        /** @var booking_rule_action $action */
        foreach ($actions as $action) {
            $fullclassname = get_class($action); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            if (!$action->is_compatible_with_ajaxformdata($ajaxformdata)) {
                continue;
            }
            $actionsforselect[$shortclassname] = $action->get_name_of_action();
        }
        $actionsforselect = array_reverse($actionsforselect);
        $mform->registerNoSubmitButton('btn_bookingruleactiontype');
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement('select', 'bookingruleactiontype',
            get_string('bookingruleaction', 'mod_booking'), $actionsforselect);
        if (isset($ajaxformdata['bookingruleactiontype'])) {
            $mform->setDefault('bookingruleactiontype', $ajaxformdata['bookingruleactiontype']);
        }
        $mform->addElement('submit', 'btn_bookingruleactiontype',
            get_string('bookingruleaction', 'mod_booking'), $buttonargs);
        $mform->setType('btn_bookingruleactiontype', PARAM_NOTAGS);

        foreach ($actions as $action) {

            if ($ajaxformdata && isset($ajaxformdata['bookingruleactiontype'])) {

                $actionname = $action->get_name_of_action();
                if ($ajaxformdata['bookingruleactiontype']
                    && $actionname == get_string(str_replace("_", "", $ajaxformdata['bookingruleactiontype']), 'mod_booking')) {
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
            $filename = 'mod_booking\\booking_rules\\actions\\' . $path['filename'];

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

        $filename = 'mod_booking\\booking_rules\\actions\\' . $actionname;

        // We instantiate all the classes, because we need some information.
        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }
}
