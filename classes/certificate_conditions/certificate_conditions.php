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
 * Helper class for certificate conditions.
 *
 * @package    mod_booking
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\certificate_conditions;

use mod_booking\output\certificateconditionslist;
use context;
use context_system;
use context_module;
use dml_exception;

class certificate_conditions {
    /** @var array $conditions cached records */
    public static $conditions = [];

    /**
     * Returns rendered HTML for a list of conditions.
     *
     * @param int $contextid
     * @param bool $enableaddbutton
     * @return string
     */
    public static function get_rendered_list_of_saved_conditions(int $contextid = 1, bool $enableaddbutton = true) {
        global $PAGE;
        $conditions = self::get_list_of_saved_conditions($contextid);
        $data = new certificateconditionslist($conditions, $contextid, $enableaddbutton);
        /** @var \mod_booking\output\renderer $output */
        $output = $PAGE->get_renderer('mod_booking');
        return $output->render_certificateconditionslist($data);
    }

    /**
     * Returns all saved certificate conditions, optionally filtering by context.
     *
     * @param int $contextid
     * @return array
     * @throws dml_exception
     */
    public static function get_list_of_saved_conditions(int $contextid = 0) {
        global $DB;
        if (empty($contextid)) {
            return $DB->get_records('booking_cert_cond');
        }
        if (empty(self::$conditions)) {
            self::$conditions = $DB->get_records('booking_cert_cond');
        }
        return array_filter(self::$conditions, fn($a) => $a->contextid == $contextid);
    }

    /**
     * Delete all certificate conditions of a context (not system).
     * @param int $contextid
     * @return void
     */
    public static function delete_conditions_by_context(int $contextid) {
        global $DB;
        if ($contextid == context_system::instance()->id) {
            return;
        }
        $conds = $DB->get_records('booking_cert_cond', ['contextid' => $contextid]);
        foreach ($conds as $cond) {
            self::delete_condition($cond->id);
        }
    }

    /**
     * Delete a single certificate condition by id.
     * @param int $id
     * @return void
     */
    public static function delete_condition(int $id) {
        global $DB;
        $DB->delete_records('booking_cert_cond_item', ['conditionid' => $id]);
        $DB->delete_records('booking_cert_cond', ['id' => $id]);
    }

    /**
     * Prepare data object for given form/ajax data or DB record.
     * @param object $data
     * @return object
     */
    public static function set_data_for_form(object &$data) {
        global $DB;
        if (empty($data->id)) {
            return new stdClass();
        }
        $record = $DB->get_record('booking_cert_cond', ['id' => $data->id]);
        if (!$record) {
            return new stdClass();
        }
        $data->contextid = $record->contextid;
        $data->name = $record->name;
        // decode json sections and populate defaults via respective handlers
        $filter = filters_info::get_filter($record->filterjson ? json_decode($record->filterjson)->filtername ?? '' : '');
        $logic = logics_info::get_logic($record->logicjson ? json_decode($record->logicjson)->logicname ?? '' : '');
        $action = actions_info::get_action($record->actionjson ? json_decode($record->actionjson)->actionname ?? '' : '');
        if ($filter) {
            $filter->set_defaults($data, $record);
        }
        if ($logic) {
            $logic->set_defaults($data, $record);
        }
        if ($action) {
            $action->set_defaults($data, $record);
        }
        return (object)$data;
    }

    /**
     * Save a certificate condition from form data.
     * @param stdClass $data
     * @return int the inserted/updated record id
     */
    public static function save_certificate_condition(stdClass &$data) {
        global $DB;
        // prepare record object
        $record = new stdClass();
        $record->contextid = $data->contextid ?? 1;
        $record->name = $data->name ?? '';
        $record->isactive = isset($data->isactive) ? $data->isactive : 1;
        $record->useastemplate = isset($data->useastemplate) ? $data->useastemplate : 0;
        // call filter/logic/action to populate json
        if (!empty($data->certificatefiltertype)) {
            $filter = filters_info::get_filter($data->certificatefiltertype);
            if ($filter) {
                $filter->save_filter($data);
                $record->filterjson = $data->filterjson ?? null;
            }
        }
        if (!empty($data->certificatelogictype)) {
            $logic = logics_info::get_logic($data->certificatelogictype);
            if ($logic) {
                $logic->save_logic($data);
                $record->logicjson = $data->logicjson ?? null;
            }
        }
        if (!empty($data->certificateactiontype)) {
            $action = actions_info::get_action($data->certificateactiontype);
            if ($action) {
                $action->save_action($data);
                $record->actionjson = $data->actionjson ?? null;
            }
        }
        // timestamp fields
        $now = time();
        if (!empty($data->id) && $data->id > 0) {
            $record->id = $data->id;
            $record->timemodified = $now;
            $DB->update_record('booking_cert_cond', $record);
            $id = $record->id;
        } else {
            $record->timecreated = $now;
            $record->timemodified = $now;
            $id = $DB->insert_record('booking_cert_cond', $record);
        }
        return $id;
    }

    /**
     * Evaluate a certificate condition against event context and execute action if passes.
     * @param stdClass $record condition record from booking_cert_cond
     * @param stdClass $eventcontext object with 'event' and/or other data for evaluation
     * @param int $userid user to apply action to
     * @param int $optionid booking option
     * @return bool true if condition passed and action executed
     */
    public static function evaluate_and_execute_condition(
        stdClass $record,
        stdClass $eventcontext,
        int $userid,
        int $optionid
    ): bool {
        // Parse filter/logic/action
        $filterobj = json_decode($record->filterjson);
        $logicobj = json_decode($record->logicjson);
        $actionobj = json_decode($record->actionjson);

        // Get handler instances
        $filter = filters_info::get_filter($filterobj->filtername ?? '');
        $logic = logics_info::get_logic($logicobj->logicname ?? '');
        $action = actions_info::get_action($actionobj->actionname ?? '');

        // If any is missing, skip
        if (!$filter || !$logic || !$action || !$record->isactive) {
            return false;
        }

        // Restore state from JSON
        if (!empty($record->filterjson)) {
            $filter->set_filterdata_from_json($record->filterjson);
        }
        if (!empty($record->logicjson)) {
            $logic->set_logicdata_from_json($record->logicjson);
        }
        if (!empty($record->actionjson)) {
            $action->set_actiondata_from_json($record->actionjson);
        }

        // Evaluate filters and logic
        if (!$filter->evaluate($eventcontext)) {
            return false;
        }
        if (!$logic->evaluate($eventcontext)) {
            return false;
        }

        // Condition passed; prepare context for action
        $actioncontext = new stdClass();
        $actioncontext->userid = $userid;
        $actioncontext->optionid = $optionid;
        $actioncontext->event = $eventcontext->event ?? null;

        // Execute action
        $action->execute_action($actioncontext);

        return true;
    }
}
