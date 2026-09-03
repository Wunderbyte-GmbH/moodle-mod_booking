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

namespace mod_booking\local\certificate_conditions;

use core_component;
use MoodleQuickForm;

/**
 * Helper to display certificate condition references on booking option form.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conditions_info {
    /**
     * Add condition selector and selected condition fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public static function add_conditions_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $logics = self::get_conditions();
        $logicsforselect = ['0' => get_string('choose...', 'mod_booking')];
        foreach ($logics as $logic) {
            $fullclassname = get_class($logic);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);
            $logicsforselect[$shortclassname] = $logic->get_name_of_logic();
        }
        $mform->registerNoSubmitButton('btn_certificateconditiontype');
        $mform->addElement(
            'select',
            'certificateconditiontype',
            get_string('certificatecondition', 'mod_booking'),
            $logicsforselect
        );
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement(
            'submit',
            'btn_certificateconditiontype',
            get_string('certificatecondition', 'mod_booking'),
            $buttonargs
        );
        $mform->setType('btn_certificateconditiontype', PARAM_NOTAGS);

        $defaultlogictype = '0';

        $selectedlogictype = $ajaxformdata['certificateconditiontype'] ?? $defaultlogictype;
        $logic = self::get_condition((string)$selectedlogictype);
        if (!$logic) {
            $selectedlogictype = $defaultlogictype;
            $logic = self::get_condition((string)$selectedlogictype);
        }

        $mform->setDefault('certificateconditiontype', $selectedlogictype);
        if (is_array($ajaxformdata)) {
            $ajaxformdata['certificateconditiontype'] = $selectedlogictype;
        }

        if ($logic) {
            $logic->add_logic_to_mform($mform, $ajaxformdata);
        }
    }

    /**
     * Return instances of all available condition handlers.
     *
     * @return array
     */
    public static function get_conditions() {
        $classes = [];
        $conditions = core_component::get_component_classes_in_namespace(
            'mod_booking',
            'local\\certificate_conditions\\conditions'
        );
        foreach ($conditions as $classname => $namespace) {
            $classes[] = new $classname();
        }
        return $classes;
    }

    /**
     * Return one condition handler instance by short name.
     *
     * @param string $name
     * @return mixed|null
     */
    public static function get_condition(string $name) {
        $classname = 'mod_booking\\local\\certificate_conditions\\conditions\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }
}
