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

use mod_booking\output\certificateconditionslist;
use context_system;
use dml_exception;
use stdClass;

/**
 * Helper class for certificate condition.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificate_conditions {
    /** @var array $condition cached records */
    public static $condition = [];

    /** @var array|null $optiontargets cached option ids targeted by certificate condition */
    private static $optiontargets = null;

    /**
     * Returns rendered HTML for a list of condition.
     *
     * @param int $contextid
     * @param bool $enableaddbutton
     * @return string
     */
    public static function get_rendered_list_of_saved_conditions(int $contextid = 1, bool $enableaddbutton = true) {
        global $PAGE;
        $condition = self::get_list_of_saved_conditions($contextid);
        $data = new certificateconditionslist($condition, $contextid, $enableaddbutton);
        /** @var \mod_booking\output\renderer $output */
        $output = $PAGE->get_renderer('mod_booking');
        return $output->render_certificateconditionslist($data);
    }

    /**
     * Returns all saved certificate condition, optionally filtering by context.
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
        if (empty(self::$condition)) {
            self::$condition = $DB->get_records('booking_cert_cond');
        }
        return array_filter(self::$condition, fn($a) => $a->contextid == $contextid);
    }

    /**
     * Delete all certificate condition of a context (not system).
     * @param int $contextid
     * @return void
     */
    public static function delete_condition_by_context(int $contextid) {
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
        self::$condition = [];
        self::$optiontargets = null;
    }

    /**
     * Returns true when the booking option is targeted by any certificate condition condition.
     *
     * @param int $optionid
     * @return bool
     */
    public static function option_is_targeted_by_condition(int $optionid): bool {
        if (self::$optiontargets === null) {
            self::build_option_targets_cache();
        }

        return !empty(self::$optiontargets[$optionid]);
    }

    /**
     * Build cache with all option ids referenced by bookingoption condition condition.
     *
     * @return void
     */
    private static function build_option_targets_cache(): void {
        global $DB;

        self::$optiontargets = [];

        // Get all bookingoption items from the items table.
        $items = $DB->get_records(
            'booking_cert_cond_item',
            ['component' => 'mod_booking', 'area' => 'bookingoption'],
            '',
            'id,itemid'
        );

        foreach ($items as $item) {
            $optionid = (int)$item->itemid;
            if ($optionid > 0) {
                self::$optiontargets[$optionid] = true;
            }
        }
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

        $filterjson = !empty($record->filterjson) ? json_decode($record->filterjson) : new stdClass();
        $conditionjson = !empty($record->logicjson) ? json_decode($record->logicjson) : new stdClass();
        $actionjson = !empty($record->actionjson) ? json_decode($record->actionjson) : new stdClass();

        $data->contextid = $record->contextid;
        $data->name = $record->name;
        $data->isactive = (int)($record->isactive ?? 1);
        $data->certificatefiltertype = $filterjson->filtername ?? '0';
        $data->certificateconditiontype = $conditionjson->conditionname ?? ($conditionjson->logicname ?? '0');
        $data->certificateactiontype = $actionjson->actionname ?? '0';

        $filter = filters_info::get_filter($filterjson->filtername ?? '');
        $condition = conditions_info::get_condition($conditionjson->conditionname ?? ($conditionjson->logicname ?? ''));
        $action = actions_info::get_action($actionjson->actionname ?? '');
        if ($filter) {
            $filter->set_defaults($data, $record);
        }
        if ($condition) {
            $condition->set_defaults($data, $record);
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
        $record = new stdClass();
        $record->contextid = $data->contextid ?? 1;
        $record->name = $data->name ?? '';
        $record->isactive = isset($data->isactive) ? $data->isactive : 1;
        $record->useastemplate = isset($data->useastemplate) ? $data->useastemplate : 0;
        $record->filterjson = json_encode(new stdClass());
        if (!empty($data->certificatefiltertype)) {
            $filter = filters_info::get_filter($data->certificatefiltertype);
            if ($filter) {
                $filter->save_filter($data);
                $record->filterjson = $data->filterjson ?? json_encode(new stdClass());
            }
        }
        if (!empty($data->certificateconditiontype)) {
            $condition = conditions_info::get_condition($data->certificateconditiontype);
            if ($condition) {
                $condition->save_condition($data);
                $record->logicjson = $data->conditionjson ?? null;
            }
        }
        if (!empty($data->certificateactiontype)) {
            $action = actions_info::get_action($data->certificateactiontype);
            if ($action) {
                $action->save_action($data);
                $record->actionjson = $data->actionjson ?? null;
            }
        }
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
        self::$condition = [];
        self::$optiontargets = null;
        return $id;
    }

    /**
     * Save items for a specific condition.
     *
     * @param int $conditionid
     * @param stdClass $data
     *
     * @return void
     *
     */
    public static function save_items_for_condition(int $conditionid, stdClass $data) {
        global $DB;
        $condition = conditions_info::get_condition($data->certificateconditiontype);
        if ($condition) {
            $condition->save_items($conditionid, $data);
        }
    }

    /**
     * Evaluate all certificate conditions against event context and execute actions if they pass.
     * @param object $event the event object
     * @param int $userid user to apply action to
     * @param int $optionid booking option
     * @return void
     */
    public static function evaluate_certificate_conditions(
        object $event,
        int $userid,
        int $optionid
    ): void {
        global $DB;
        $eventcontext = new stdClass();
        $eventcontext->event = $event;
        $eventcontext->userid = $userid;
        $eventcontext->optionid = $optionid;

        $records = $DB->get_records('booking_cert_cond', ['isactive' => 1]);

        foreach ($records as $record) {
            self::evaluate_single_condition($record, $eventcontext, $userid, $optionid);
        }
    }

    /**
     * Evaluate a single certificate condition and execute action if it passes.
     * @param stdClass $record condition record from booking_cert_cond
     * @param stdClass $eventcontext object with 'event' and other data for evaluation
     * @param int $userid user to apply action to
     * @param int $optionid booking option
     * @return bool true if condition passed and action executed
     */
    private static function evaluate_single_condition(
        stdClass $record,
        stdClass $eventcontext,
        int $userid,
        int $optionid
    ): bool {
        $filterobj = json_decode($record->filterjson);
        $conditionobj = json_decode($record->logicjson);
        $actionobj = json_decode($record->actionjson);

        $filter = filters_info::get_filter($filterobj->filtername ?? '');
        $condition = conditions_info::get_condition($conditionobj->conditionname ?? '');
        $action = actions_info::get_action($actionobj->actionname ?? '');

        if (!$condition || !$action) {
            return false;
        }

        if ($filter) {
            $filter->set_filterdata_from_json($record->filterjson ?? '{}');
        }
        if ($condition) {
            $condition->set_conditiondata_from_json($record->logicjson ?? '{}');
            $condition->set_logicdata($record);
        }
        if ($action) {
            $action->set_actiondata_from_json($record->actionjson ?? '{}');
        }
        if ($filter && !$filter->evaluate($eventcontext)) {
            return false;
        }
        if (!$condition->evaluate($eventcontext)) {
            return false;
        }

        $actioncontext = new stdClass();
        $actioncontext->userid = $userid;
        $actioncontext->optionid = $optionid;
        $actioncontext->event = $eventcontext->event ?? null;

        $action->execute_action($actioncontext, $record);

        return true;
    }
}
