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

namespace mod_booking\local\certificate_conditions\conditions;

use mod_booking\local\certificate_conditions\certificate_conditions_interface;
use mod_booking\local\certificate_conditions\completion_counter_trait;
use MoodleQuickForm;
use stdClass;

/**
 * Helper class for booking option related certificate condition logic.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taggedoptions implements certificate_conditions_interface {
    use completion_counter_trait;

    /**
     * Booking option ID to filter by.
     *
     * @var int
     */
    public $optionid = 0;
    /**
     * Array of booking options that must be completed to satisfy the condition.
     *
     * @var array
     */
    public $optionids = [];
    /**
     * Required count of completed booking options to satisfy the condition.
     *
     * @var int
     */
    public $requiredcount = 1;

    /**
     * Add logic fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public function add_logic_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $cmid = 0;
        if (!empty($ajaxformdata['cmid'])) {
            $cmid = (int)$ajaxformdata['cmid'];
        } else if (!empty($ajaxformdata['contextid'])) {
            global $DB;
            $contextrecord = $DB->get_record('context', ['id' => (int)$ajaxformdata['contextid']], 'id,contextlevel,instanceid');
            if (!empty($contextrecord) && (int)$contextrecord->contextlevel === CONTEXT_MODULE) {
                $cmid = (int)$contextrecord->instanceid;
            }
        }
        $mform->addElement(
            'text',
            'condition_taggedoptions_requiredcount',
            get_string('condition_taggedoptions_requiredcount', 'mod_booking')
        );
        $mform->setType('condition_taggedoptions_requiredcount', PARAM_INT);
        $mform->setDefault('condition_taggedoptions_requiredcount', 1);
    }

    /**
     * Return logic label.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_logic(bool $localized = true): string {
        return $localized ? get_string('condition_taggedoptions', 'mod_booking') : 'condition_taggedoptions';
    }

    /**
     * Persist logic configuration into JSON payload.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_condition(stdClass &$data): void {
        $requiredcount = (int)($data->condition_taggedoptions_requiredcount ?? 1);
        $requiredcount = max(1, $requiredcount);

        $jsonobject = new stdClass();
        $jsonobject->conditionname = 'taggedoptions';
        $jsonobject->requiredcount = $requiredcount;
        $data->conditionjson = json_encode($jsonobject);
    }

    /**
     * Save items for the booking option condition.
     *
     * @param int $conditionid
     * @param stdClass $data
     *
     * @return void
     *
     */
    public function save_items(int $conditionid, stdClass $data): void {
        global $DB;
        $conditionids = $data->conditions ?? [];
        foreach ($conditionids as $targetconditionid) {
            // First we delete old Record.
            $DB->delete_records(
                'booking_cert_cond_item',
                [
                    'conditionid' => (int) $targetconditionid,
                    'itemid' => (int) $data->optionid,
                    'component' => 'mod_booking',
                    'area' => 'bookingoption',
                ],
            );
            $record = new stdClass();
            $record->conditionid = (int)$targetconditionid;
            $record->itemid = $data->optionid;
            $record->component = 'mod_booking';
            $record->area = 'bookingoption';
            $record->configjson = json_encode([]);
            $record->sortorder = 0;
            $DB->insert_record('booking_cert_cond_item', $record);
        }
    }

    /**
     * Set default form values from existing record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return void
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        // Load required count from JSON.
        $requiredcount = 1;
        if (!empty($record->logicjson)) {
            $jsonobject = json_decode($record->logicjson);
            if ($jsonobject) {
                $requiredcount = max(1, (int)($jsonobject->requiredcount ?? 1));
            }
        }
        $data->condition_taggedoptions_requiredcount = $requiredcount;
    }

    /**
     * Set internal logic data from condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_logicdata(stdClass $record): void {
        global $DB;

        // Load option IDs from items table.
        $this->optionids = [];
        if (!empty($record->id)) {
            $items = $DB->get_records(
                'booking_cert_cond_item',
                ['conditionid' => (int)$record->id, 'component' => 'mod_booking', 'area' => 'bookingoption'],
                '',
                'itemid'
            );
            if (!empty($items)) {
                $this->optionids = array_map('intval', array_column($items, 'itemid'));
            }
        }

        $this->optionid = (int)($this->optionids[0] ?? 0);

        // Load required count from JSON.
        $this->requiredcount = 1;
        if (!empty($record->logicjson)) {
            $jsonobject = json_decode($record->logicjson);
            if ($jsonobject) {
                $this->requiredcount = max(1, (int)($jsonobject->requiredcount ?? 1));
            }
        }
    }

    /**
     * Set internal logic data from JSON payload.
     *
     * @param string $json
     * @return void
     */
    public function set_conditiondata_from_json(string $json): void {
        $jsonobject = json_decode($json);
        if ($jsonobject) {
            $this->requiredcount = max(1, (int)($jsonobject->requiredcount ?? 1));
        }
    }

    /**
     * Apply logic constraints to SQL builder context.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params): void {
    }

    /**
     * Returns true when one of the tagged options was completed and enough of them are completed by the user.
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool {
        return $this->evaluate_completion($context);
    }

    /**
     * Return the ids of the options tagged with this condition.
     *
     * @return int[]
     */
    public function get_targeted_optionids(): array {
        $optionids = array_map('intval', $this->optionids);
        if (empty($optionids) && !empty($this->optionid)) {
            $optionids = [(int)$this->optionid];
        }
        return array_values(array_unique($optionids));
    }

    /**
     * Return the required number of completed options.
     *
     * @return int
     */
    public function get_requiredcount(): int {
        return max(1, (int)$this->requiredcount);
    }

    /**
     * Return a short summary like "1 of 4 tagged booking options".
     *
     * @param stdClass $record
     * @return string
     */
    public function get_description(stdClass $record): string {
        $this->set_logicdata($record);
        $a = (object)[
            'required' => min($this->get_requiredcount(), max(1, count($this->get_targeted_optionids()))),
            'total' => count($this->get_targeted_optionids()),
        ];
        return get_string('condition_description_taggedoptions', 'mod_booking', $a);
    }

    /**
     * Validate logic-specific form data.
     *
     * @param array $data
     * @return array
     */
    public function validate(array $data): array {
        $errors = [];
        return $errors;
    }
}
