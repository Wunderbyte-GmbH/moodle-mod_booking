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

use core_component;
use core_plugin_manager;
use MoodleQuickForm;

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
     * @param ?array $ajaxformdata
     * @return void
     */
    public static function add_conditions_to_mform(MoodleQuickForm &$mform,
        ?array &$ajaxformdata = null) {

        $conditions = self::get_conditions();

        $conditionsforselect = [];
        foreach ($conditions as $condition) {
            if (!empty($ajaxformdata['bookingruletype'])
                && !$condition->can_be_combined_with_bookingruletype($ajaxformdata['bookingruletype'])) {
                continue;
            }
            $fullclassname = get_class($condition); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $conditionsforselect[$shortclassname] = $condition->get_name_of_condition();
        }

        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->registerNoSubmitButton('btn_bookingruleconditiontype');
        $mform->addElement('select', 'bookingruleconditiontype',
            get_string('bookingrulecondition', 'mod_booking'), $conditionsforselect);
        $mform->addElement('submit', 'btn_bookingruleconditiontype',
            get_string('bookingrulecondition', 'mod_booking'), $buttonargs);
        $mform->setType('btn_bookingruleconditiontype', PARAM_NOTAGS);

        if (isset($ajaxformdata['bookingruleconditiontype'])) {
            $condition = self::get_condition($ajaxformdata['bookingruleconditiontype']);
        } else {
            list($condition) = $conditions;
        }
        $condition->add_condition_to_mform($mform, $ajaxformdata);

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
            $filename = 'mod_booking\\booking_rules\\conditions\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $conditions[] = $instance;
            }
        }
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classes = core_component::get_component_classes_in_namespace(
                "bookingextension_{$plugin->name}",
                'rules\\conditions'
            );
            foreach ($classes as $classname => $path) {
                if (class_exists($classname)) {
                          $instance = new $classname();
                          $conditions[] = $instance;
                }
            }
        }
        return $conditions;
    }

    /**
     * Get booking rule condition by name.
     * @param string $conditionname
     * @return mixed
     */
    public static function get_condition(string $conditionname) {
        global $CFG;

        $filename = 'mod_booking\\booking_rules\\conditions\\' . $conditionname;

        // We instantiate all the classes, because we need some information.
        if (class_exists($filename)) {
            return new $filename();
        }
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classname = "\\bookingextension_{$plugin->name}\\rules\\conditions\\{$conditionname}";
            if (class_exists($classname)) {
                return new $classname();
            }
        }
        return null;
    }
}
