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
use core_component;
use mod_booking\output\actionslist;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
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
            $mform->addElement('header', 'bookingactionsheader', get_string('bookingactionsheader', 'mod_booking'));

            if (!empty($formdata['optionid'])) {
                // Add a list of existing actions, including an edit and a delete button.
                self::add_list_of_existing_actions_for_this_option($mform, $formdata);

            } else {
                $mform->addElement('static', 'onlyaddactionsonsavedoption',
                    get_string('onlyaddactionsonsavedoption', 'mod_booking'));
            }
        }
    }

    /**
     * Get all booking actions.
     * @return array an array of booking actions (instances of class booking_action).
     */
    public static function get_action_types() {
        global $CFG;

        $actions = core_component::get_component_classes_in_namespace('mod_booking', 'bo_actions\\action_tpes');

        $actions = [];

        // We just want filenames, as they are also the classnames.
        foreach ($actions as $key => $value) {

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

        // If we have an ID, we retrieve the right action from DB.
        $record = $DB->get_record('booking_action_options', ['id' => $data->id]);

        $action = self::get_action($record->type);

        $data->optionid = $record->optionid;
        $data->action_type = $record->type;

        // These function just add their bits to the object.
        $action->set_defaults($data, $record);

        return (object)$data;

    }

    /**
     * Save all booking actions.
     * @param stdClass &$data reference to the form data
     * @return void
     */
    public static function save_action(stdClass &$data) {

        global $USER;

        // We receive the form with the data depending on the used handlers.
        // As we know which handler to call, we only instantiate one action.
        $action = self::get_action($data->action_type);

        // action has to be saved last, because it actually writes to DB.
        $action->save_action($data);

        // Every time we save the action, we have to invalidate caches.
        // Trigger an event that booking option has been updated.

        $context = context_module::instance($data->cmid);
        $event = \mod_booking\event\bookingoption_updated::create(array('context' => $context, 'objectid' => $data->optionid,
                'userid' => $USER->id));
        $event->trigger();

        return;
    }

    /**
     * Delete a booking action by its ID.
     * @param int $actionid the ID of the action
     */
    public static function delete_action(int $actionid) {
        global $DB;
        $DB->delete_records('booking_action_options', ['id' => (int)$actionid]);
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

        $optionid = $formdata['optionid'];
        $cmid = $formdata['cmid'];

        // TODO: Get existing actions not from table but from json of this option.

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $data = new actionslist($cmid, $optionid, $settings->bookingactions);
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
        $buttonargs = array('style' => 'visibility:hidden;');
        $categoryselect = [
            $mform->createElement('select', 'action_type',
            get_string('bookingaction', 'mod_booking'), $actionsforselect),
            $mform->createElement('submit',
                'btn_actiontype',
                get_string('bookingaction', 'mod_booking'),
                $buttonargs)
        ];
        $mform->addGroup($categoryselect, 'action_type', get_string('bookingaction', 'mod_booking'), [' '], false);
        $mform->setType('btn_actiontype', PARAM_NOTAGS);

        if (isset($formdata['action_type'])) {
            $action = self::get_action($formdata['action_type']);
        } else {
            list($action) = $actiontypes;
        }

        // Finally, after having chosen the right type of action, we add the corresponding elements.
        $action->add_action_to_mform($mform, $formdata);
    }

    /**
     * This function checks if a action blocks the main booking option.
     * A action can use the option as container only and therby block its booking.
     * When this is the case, the action can return a description (which could be a modal eg.)...
     * ... to render instead of booking-button.
     * If there is more than one blocking action, this is recognized as well.
     *
     * @param object $settings
     * @return bool
     */
    public static function is_blocked(object $settings) {

        $isblocked = false;
        foreach ($settings->actions as $action) {
            if ($action->block == 1) {
                $isblocked = true;
            }
        }

        return $isblocked;
    }

    /**
     * This function checks if there are any not blocking actions in the main booking option.
     * While the not blocking subblocking doesn't prevent the blocking of the main option...
     * ... it still needs to announce the presence of options...
     * ... which may want to introduce a page in the booking process.
     * Blocking actions are handled by a different bo_condition.
     *
     * @param object $settings
     * @return bool
     */
    public static function has_soft_actions(object $settings) {

        foreach ($settings->actions as $action) {
            if ($action->block != 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load all the available actions for a specific ID and return them as array.
     * We return the instantiated classes to be able to call functions on them.
     *
     * @param integer $optionid
     * @return array
     */
    public static function load_actions(int $optionid) {

        global $DB;

        $records = $DB->get_records('booking_action_options', ['optionid' => $optionid]);
        $returnarray = [];

        foreach ($records as $record) {
            $action = self::get_action($record->type);
            $action->set_actiondata($record);
            $returnarray[] = $action;
        }

        return $returnarray;
    }

    /**
     * Returns a given action option instance.
     * The ID might actually come from the area.
     *
     * @param string $area
     * @param integer $itemid
     * @return object
     */
    public static function get_action_by_area_and_id(string $area, int $itemid) {
        global $DB;

        // First, we need to isolate the id of the action. We might find it in the area.

        $array = explode('-', $area);

        $area = array_shift($array);
        $sbid = array_shift($array);

        // If we found an id, we use it as itemid and design the  slotid.
        if ($sbid) {
            $record = $DB->get_record('booking_action_options', ['id' => $sbid]);
        } else {
            $record = $DB->get_record('booking_action_options', ['id' => $itemid]);
        }

        // If action type is missing we cannot instantiate a action.
        if (empty($record->type)) {
            return null;
        }

        $action = self::get_action($record->type);
        $action->set_actiondata($record);

        return $action;
    }

    /**
     * Function to save answer to a action.
     * We can provide a status which will then decide what actually happens.
     *
     * @param string $area
     * @param integer $itemid
     * @param integer $userid
     * @param integer $status
     * @return boolean
     */
    public static function save_response(string $area, int $itemid, int $status, $userid = 0):bool {

        global $USER;

        // Make sure we have the right user.
        if ($userid == 0) {
            $userid = $USER->id;
        }

        $action = self::get_action_by_area_and_id($area, $itemid);

        if (empty($action)) {
            // No action could be found.
            return true;
        }

        // Do we need to update and if so, which records?

        switch ($status) {
            case STATUSPARAM_BOOKED: // We actually book.
                // Check if there was a reserved or waiting list entry before.
                self::update_or_insert_answer(
                    $action,
                    $itemid,
                    $userid,
                    STATUSPARAM_BOOKED,
                    [STATUSPARAM_RESERVED,
                    STATUSPARAM_WAITINGLIST]);
                break;
            case STATUSPARAM_WAITINGLIST: // We move to the waiting list.
                // Check if there was a reserved entry before.
                self::update_or_insert_answer(
                    $action,
                    $itemid,
                    $userid,
                    STATUSPARAM_WAITINGLIST,
                    [STATUSPARAM_RESERVED]);
                break;
            case STATUSPARAM_RESERVED: // We only want to use shortterm reservation.
                // Check if there was a reserved or waiting list entry before.
                self::update_or_insert_answer(
                    $action,
                    $itemid,
                    $userid,
                    STATUSPARAM_RESERVED,
                    [STATUSPARAM_WAITINGLIST, STATUSPARAM_RESERVED]);
                break;
            case STATUSPARAM_NOTBOOKED: // We only want to delete the shortterm reservation.
                // Check if there was a reserved or waiting list entry before.
                self::update_or_insert_answer(
                    $action,
                    $itemid,
                    $userid,
                    STATUSPARAM_NOTBOOKED,
                    [STATUSPARAM_RESERVED]);
                break;
            case STATUSPARAM_DELETED: // We delete the existing subscription.
                // Check if there was a booked entry before.
                self::update_or_insert_answer(
                    $action,
                    $itemid,
                    $userid,
                    STATUSPARAM_DELETED,
                    [STATUSPARAM_BOOKED]);
                break;
        };
        return true;
    }

    /**
     * This function looks for old answers of a special type and if there are any, it deletes them.
     * Also, it creates a new answer record if necessary.
     *
     * @param object $action
     * @param integer $itemid
     * @param integer $userid
     * @param integer $newstatus
     * @param array $oldstatus
     * @return bool
     */
    private static function update_or_insert_answer(object $action, int $itemid, int $userid,
        int $newstatus, array $oldstatus) {

        global $DB, $USER;

        $now = time();

        if ($records = self::return_action_answers($action->id, $itemid, $action->optionid, $userid, $oldstatus)) {
            while (count($records) > 0) {
                $record = array_pop($records);
                // We already popped one record, so count has to be 0.
                if (count($records) == 0 && $newstatus !== STATUSPARAM_NOTBOOKED) {
                    $record->timemodified = $now;
                    $record->status = $newstatus;
                    $DB->update_record('booking_action_answers', $record);
                } else {
                    // This is just for cleaning, should never happen.
                    $DB->delete_records('booking_action_answers', ['id' => $record->id]);
                }
            }
        } else if ($newstatus !== STATUSPARAM_DELETED || $newstatus !== STATUSPARAM_NOTBOOKED) {

            $data = $action->return_action_information($itemid, $userid);
            $record = (object)[
                'itemid' => $itemid,
                'sboptionid' => $action->id,
                'optionid' => $action->optionid,
                'userid' => $userid,
                'usermodified' => $USER->id,
                'status' => $newstatus,
                'json' => $action->return_answer_json($itemid),
                'timestart' => $data['coursestarttime'] ?? null,
                'timeend' => $data['courseendtime'] ?? null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];

            $DB->insert_record('booking_action_answers', $record);
        }
    }

    /**
     * Returns all answer records for a certain PARAMSTATUS if defined.
     *
     * @param integer $sboid
     * @param integer $itemid
     * @param integer $optionid
     * @param integer $userid
     * @param array $status
     * @return array
     */
    private static function return_action_answers(int $sboid, int $itemid, int $optionid, int $userid, array $status = []) {

        global $DB;

        // We always fetch all the entries.
        $sql = "SELECT *
                FROM {booking_action_answers}
                WHERE itemid=:itemid
                AND sboptionid=:sboptionid
                AND userid=:userid
                AND optionid=:optionid";

        $params = [
            'itemid' => $itemid,
            'sboptionid' => $sboid,
            'userid' => $userid,
            'optionid' => $optionid,
        ];

        if (!empty($status)) {

            list ($inorequal, $ieparams) = $DB->get_in_or_equal($status, SQL_PARAMS_NAMED);
            $sql .= " AND status $inorequal";

            foreach ($ieparams as $key => $value) {
                $params[$key] = $value;
            }
        }

        $records = $DB->get_records_sql($sql, $params);

        return $records;
    }

    /**
     * Returns an array of area and itemid, written for unloading actions from shopping cart.
     *
     * @param integer $optionid
     * @return array
     */
    public static function return_array_of_actions(int $optionid): array {
        global $DB;

        $records = $DB->get_records('booking_action_options', ['optionid' => $optionid]);
        $returnarray = [];

        foreach ($records as $record) {
            $returnarray[] = (object)[
                'area' => 'action',
                'itemid' => $record->id,
            ];
        }

        return $returnarray;
    }
}
