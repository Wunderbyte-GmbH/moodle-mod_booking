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

namespace mod_booking\bo_actions;

use context_module;
use mod_booking\singleton_service;
use stdClass;

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_action {

    /**
     * Returns description
     * @return string
     * @throws coding_exception
     */
    public static function get_name_of_action() {

        $classname = get_called_class();

        // We only want the last part of the classname.
        $array = explode('\\', $classname);

        $classname = array_pop($array);
        return get_string($classname, 'mod_booking');
    }

    /**
     * This actually only translates the action values and stores them in the json property of the data object.
     * @param stdClass &$data form data reference
     */
    public static function save_action(stdClass &$data) {

        $settings = singleton_service::get_instance_of_booking_option_settings($data->optionid);

        $optionvalues = $settings->return_settings_as_stdclass();
        $optionvalues->optionid = $optionvalues->id;

        // Make sure we have a jsonobject in settings.
        if (empty($settings->jsonobject)) {
            $settings->jsonobject = new stdClass();
        }
        $jsonobject = $settings->jsonobject;

        // If id is 0, we need to add a new action id.
        // Therefore, we need to see how many actions are already stored.
        if (empty($data->id)) {

            if (!isset($jsonobject->boactions)) {
                $jsonobject->boactions = [];
            } else {
                // Make sure we have the boactions actually as an array.
                $jsonobject->boactions = (array)$jsonobject->boactions;
            }
            // Now get the boactions key and count how many actions are there and add 1 for the new id.
            $data->id = count((array)$jsonobject->boactions) + 1;
        } else {
            // Also if we have a data id, we need to first treat the boactions as array.
            $jsonobject->boactions = (array)$jsonobject->boactions;
        }

        // In any case, we use the data-id as key for this action.
        $jsonobject->boactions[$data->id] = $data;

        // Make sure we don't lose any other information stored in the json.
        // And store it back as string.
        $optionvalues->json = json_encode($jsonobject);

        $context = context_module::instance($data->cmid);

        booking_update_options($optionvalues, $context, MOD_BOOKING_UPDATE_OPTIONS_PARAM_REDUCED);
    }

    /**
     * This function adds the key of the saved action to the formdata.
     * @param stdClass $data
     * @param stdClass $record
     * @return void
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        foreach ($record as $key => $value) {
            $data->{$key} = $value;
        }

    }

    /**
     * Apply action.
     * @param stdClass $actiondata
     * @param ?int $userid
     * @return int // Status. 0 is do nothing, 1 aborts after application right away.
     */
    public function apply_action(stdClass $actiondata, int $userid = 0) {

        return 0;
    }
}
