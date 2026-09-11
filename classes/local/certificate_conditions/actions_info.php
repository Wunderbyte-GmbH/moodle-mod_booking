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
class actions_info {
    /**
     * Add action selector and selected action fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public static function add_actions_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $actions = self::get_actions();
        foreach ($actions as $action) {
            $fullclassname = get_class($action);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);
            $actionsforselect[$shortclassname] = $action->get_name_of_action();
        }
        $mform->registerNoSubmitButton('btn_certificateactiontype');
        $mform->addElement(
            'select',
            'certificateactiontype',
            get_string('certificateaction', 'mod_booking'),
            $actionsforselect
        );
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement(
            'submit',
            'btn_certificateactiontype',
            get_string('certificateaction', 'mod_booking'),
            $buttonargs
        );
        $mform->setType('btn_certificateactiontype', PARAM_NOTAGS);

        $defaultactiontype = '0';
        if (!empty($actions)) {
            $firstactionclassname = get_class($actions[0]);
            $classnameparts = explode('\\', $firstactionclassname);
            $defaultactiontype = end($classnameparts) ?: '0';
        }

        $selectedactiontype = $ajaxformdata['certificateactiontype'] ?? $defaultactiontype;
        $action = self::get_action((string)$selectedactiontype);
        if (!$action) {
            $selectedactiontype = $defaultactiontype;
            $action = self::get_action((string)$selectedactiontype);
        }

        $mform->setDefault('certificateactiontype', $selectedactiontype);
        if (is_array($ajaxformdata)) {
            $ajaxformdata['certificateactiontype'] = $selectedactiontype;
        }

        if ($action) {
            $action->add_action_to_mform($mform, $ajaxformdata);
        }
    }

    /**
     * Return instances of all available action handlers.
     *
     * @return array
     */
    public static function get_actions() {
        $classes = [];
        $actions = core_component::get_component_classes_in_namespace(
            'mod_booking',
            'local\\certificate_conditions\\actions'
        );
        foreach ($actions as $classname => $namespace) {
            $classes[] = new $classname();
        }
        return $classes;
    }

    /**
     * Return one action handler instance by short name.
     *
     * @param string $name
     * @return mixed|null
     */
    public static function get_action(string $name) {
        $classname = 'mod_booking\\local\\certificate_conditions\\actions\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }

    /**
     * Check if a certificate has already been issued for a specific condition and user.
     *
     * @param int $conditionid
     * @param int $certid
     * @param int $userid
     *
     * @return bool
     *
     */
    public static function certificate_already_issued(int $conditionid, int $certid, int $userid) {
        global $DB;
        $records = $DB->get_records('tool_certificate_issues', ['userid' => $userid, 'templateid' => $certid]);
        if (!$records) {
            return false;
        }
        foreach ($records as $record) {
            $jsonobject = json_decode($record->data);
            if (!isset($jsonobject->conditionid)) {
                continue;
            }
            if ($jsonobject->conditionid == $conditionid) {
                return true;
            }
        }
        return false;
    }
}
