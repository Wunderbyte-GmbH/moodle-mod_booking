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
 * Base class for booking actions information.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_actions;

use context_module;
use core_analytics\action;
use core_component;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\output\actionslist;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Class for additional information of booking actions.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actions_info {

    /**
     * Add a list of actions and a modal to edit actions to an mform.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public static function add_actions_to_mform(MoodleQuickForm &$mform,
        array &$formdata = []) {

        if (get_config('booking', 'showboactions') && wb_payment::pro_version_is_activated()) {
            // Add header to Element.
            $mform->addElement('header', 'bookingactionsheader',
            '<i class="fa fa-fw fa-bolt" aria-hidden="true"></i>&nbsp;' . get_string('bookingactionsheader', 'mod_booking'));

            $mform->addElement('hidden', 'boactionsjson');
            $mform->setType('boactionsjson', PARAM_RAW);
            if (!empty($formdata['id'])) {
                // Add a list of existing actions, including an edit and a delete button.
                self::add_list_of_existing_actions_for_this_option($mform, $formdata);

            } else {
                $mform->addElement('static', 'onlyaddactionsonsavedoption',
                    get_string('onlyaddactionsonsavedoption', 'mod_booking'));
            }
        }
    }

    /**
     * Add action type selector and form for modal.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public static function add_actionsform_to_mform(MoodleQuickForm &$mform,
        array &$formdata = []) {

        self::add_action($mform, $formdata);

    }

    /**
     * Get all booking actions.
     * @return array an array of booking actions (instances of class booking_action).
     */
    public static function get_action_types() {

        $actionstypes = core_component::get_component_classes_in_namespace('mod_booking', 'bo_actions\\action_types');

        $actions = [];

        // We just want filenames, as they are also the classnames.
        foreach ($actionstypes as $key => $value) {

            // We instantiate all the classes, because we need some information.
            if (class_exists($key)) {
                $actions[] = new $key();
            }
        }

        return $actions;
    }

    /**
     * Get booking action by type.
     * @param string $actiontype
     * @return mixed
     */
    public static function get_action(string $actiontype) {
        global $CFG;

        $filename = 'mod_booking\\bo_actions\\action_types\\' . $actiontype;

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

        // If we have an id, we can load the right booking option.
        $settings = singleton_service::get_instance_of_booking_option_settings($data->optionid);

        if (!empty($settings->boactions[$data->id])) {

            $action = self::get_action($settings->boactions[$data->id]->action_type);
        }

        // These function just add their bits to the object.
        $action->set_defaults($data, $settings->boactions[$data->id]);

        return (object)$data;

    }

    /**
     * Save all booking actions.
     *
     * @param stdClass $data reference to the form data
     * @return void
     */
    public static function save_action(stdClass &$data) {
        // We receive the form with the data depending on the used handlers.
        // As we know which handler to call, we only instantiate one action.
        $action = self::get_action($data->action_type);

        // Action has to be saved last, because it actually writes to DB.
        $action->save_action($data);
    }

    /**
     * Delete a booking action by its ID.
     * @param stdClass $data
     */
    public static function delete_action(stdClass $data) {
        global $USER;

        // Todo: Actually delete information from option.

        $settings = singleton_service::get_instance_of_booking_option_settings($data->optionid);

        // We would certainly expect that we find this booking action with the given id.
        if (isset($settings->boactions[$data->id])) {

            $optionvalues = $settings->return_settings_as_stdclass();
            $optionvalues->optionid = $optionvalues->id;

            unset($optionvalues->jsonobject->boactions[$data->id]);

            $optionvalues->json = json_encode($optionvalues->jsonobject);
            $optionvalues->boactions = $optionvalues->jsonobject->boactions;

            $context = context_module::instance($data->cmid);

            booking_option::update($optionvalues, $context, MOD_BOOKING_UPDATE_OPTIONS_PARAM_REDUCED);

            booking_option::trigger_updated_event($context, $optionvalues->optionid, $USER->id, $USER->id, 'actions');
        }

    }

    /**
     * This function adds a list of existing actions for this function.
     * Every line includes and edit and a delete no-submit-button.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    private static function add_list_of_existing_actions_for_this_option(MoodleQuickForm &$mform, array &$formdata = []) {

        global $DB, $PAGE;

        $optionid = $formdata['id'] ?? $formdata['optionid'] ?? 0;
        $cmid = $formdata['cmid'] ?? 0;

        // TODO: Get existing actions not from table but from json of this option.

        $boactions = booking_option::get_value_of_json_by_key($optionid, 'boactions');

        $data = new actionslist($cmid, $optionid, $boactions ?? []);
        $output = $PAGE->get_renderer('mod_booking');
        $html = $output->render_boactionslist($data);

        $mform->addElement('html', $html ?? '');
    }

    /**
     * Retrieve all available actions and add a select form element to choose between.
     * This form also adds the corresponding form elements of the chosen type.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public static function add_action(MoodleQuickForm &$mform, array &$formdata) {
        // First, get all the type of actions there are.
        $actiontypes = self::get_action_types();

        $actionsforselect = [];
        foreach ($actiontypes as $action) {
            $fullclassname = get_class($action); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $actionsforselect[$shortclassname] = $action->get_name_of_action();
        }

        $mform->registerNoSubmitButton('btn_actiontype');
        $buttonargs = ['style' => 'visibility:hidden;'];
        $categoryselect = [
            $mform->createElement('select', 'action_type',
            get_string('bookingaction', 'mod_booking'), $actionsforselect),
            $mform->createElement('submit',
                'btn_actiontype',
                get_string('bookingaction', 'mod_booking'),
                $buttonargs),
        ];
        $mform->addGroup($categoryselect, 'action_type', get_string('bookingaction', 'mod_booking'), [' '], false);
        $mform->setType('btn_actiontype', PARAM_NOTAGS);

        if (!empty($formdata['action_type'])) {
            $action = self::get_action($formdata['action_type']);
        } else {
            list($action) = $actiontypes;
        }

        // Finally, after having chosen the right type of action, we add the corresponding elements.
        $action->add_action_to_mform($mform, $formdata);
    }

    /**
     * Function to actually execute the actions of the booking option.
     * @param booking_option_settings $settings
     * @param int $userid
     * @return int // Status. 0 is do nothing, 1 aborts after application right away.
     */
    public static function apply_actions(booking_option_settings $settings, int $userid = 0) {

        global $USER;

        $userid = !empty($userid) ? $userid : $USER->id;

        if (empty($settings->boactions)) {
            return 0;
        }

        $returnstatus = 0;
        foreach ($settings->boactions as $actiondata) {

            // Use ID & cmid from current bookingoption.
            $actiondata->cmid = $settings->cmid;
            $actiondata->optionid = $settings->id;

            $action = self::return_action($actiondata);

            $status = $action->apply_action($actiondata, $userid);

            if ($status > $returnstatus) {
                $returnstatus = $status;
            }
        }

        return $status;
    }

    /**
     * Returns the instantiated actions depending on the action data.
     * @param stdClass $actiondata
     * @return ?booking_action
     */
    private static function return_action(stdClass $actiondata) {

        $classname = 'mod_booking\\bo_actions\\action_types\\' . $actiondata->action_type;
        try {
            $action = new $classname();
        } catch (moodle_exception $e) {
            return null;
        }
        return $action;
    }
}
