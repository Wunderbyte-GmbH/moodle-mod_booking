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

use mod_booking\event\bookingoption_completed;
use mod_booking\local\certificate_conditions\certificate_conditions_interface;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Helper class for booking option related certificate condition logic.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption implements certificate_conditions_interface {
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

        $bookingoptions = [
            'tags' => false,
            'multiple' => true,
            'ajax' => 'mod_booking/form_booking_options_selector',
            'cmid' => $cmid,
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;

                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }

                $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)$value);
                $instancesettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);

                $details = (object)[
                    'id' => $optionsettings->id,
                    'titleprefix' => $optionsettings->titleprefix,
                    'text' => $optionsettings->text,
                    'instancename' => $instancesettings->name,
                ];

                return $OUTPUT->render_from_template(
                    'mod_booking/form_booking_options_selector_suggestion',
                    $details
                );
            },
        ];

        $mform->addElement(
            'autocomplete',
            'condition_bookingoption_optionid',
            get_string('condition_bookingoption_optionid', 'mod_booking'),
            [],
            $bookingoptions
        );
        if (!empty($cmid)) {
            $element = $mform->getElement('condition_bookingoption_optionid');
            if ($element) {
                $element->updateAttributes([
                    'data-cmid' => $cmid,
                ]);
            }
        }
        $mform->setType('condition_bookingoption_optionid', PARAM_INT);

        $mform->addElement(
            'text',
            'condition_bookingoption_requiredcount',
            get_string('condition_bookingoption_requiredcount', 'mod_booking')
        );
        $mform->setType('condition_bookingoption_requiredcount', PARAM_INT);
        $mform->setDefault('condition_bookingoption_requiredcount', 1);
    }

    /**
     * Return logic label.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_logic(bool $localized = true): string {
        return $localized ? get_string('condition_bookingoption', 'mod_booking') : 'condition_bookingoption';
    }

    /**
     * Persist logic configuration into JSON payload.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_condition(stdClass &$data): void {
        global $DB;
        $requiredcount = (int)($data->condition_bookingoption_requiredcount ?? 1);
        $requiredcount = max(1, $requiredcount);

        $jsonobject = new stdClass();
        $jsonobject->conditionname = 'bookingoption';
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
        $optionids = [];
        if (!empty($data->condition_bookingoption_optionid)) {
            if (is_array($data->condition_bookingoption_optionid)) {
                $optionids = array_map('intval', $data->condition_bookingoption_optionid);
            } else {
                $optionids = [(int)$data->condition_bookingoption_optionid];
            }
        }
        // First we delete existing items for the condition, then we insert the new ones.
        $DB->delete_records(
            'booking_cert_cond_item',
            ['conditionid' => $conditionid, 'component' => 'mod_booking', 'area' => 'bookingoption']
        );
        foreach ($optionids as $optionid) {
            $record = new stdClass();
            $record->conditionid = $conditionid;
            $record->itemid = $optionid;
            $record->component = 'mod_booking';
            $record->area = 'bookingoption';
            // Placeholder so far there is no config for bookingoption conditions, but we keep the field for possible future use.
            $record->configjson = json_encode([]);
            // Placeholder so far there is no sortorder feature.
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
        global $DB;

        // Load option IDs from items table.
        $optionids = [];
        if (!empty($record->id)) {
            $items = $DB->get_records(
                'booking_cert_cond_item',
                ['conditionid' => (int)$record->id, 'component' => 'mod_booking', 'area' => 'bookingoption'],
                '',
                'itemid'
            );
            if (!empty($items)) {
                $optionids = array_map('intval', array_column($items, 'itemid'));
            }
        }

        $data->condition_bookingoption_optionid = $optionids;

        // Load required count from JSON.
        $requiredcount = 1;
        if (!empty($record->conditionjson)) {
            $jsonobject = json_decode($record->conditionjson);
            if ($jsonobject) {
                $requiredcount = max(1, (int)($jsonobject->requiredcount ?? 1));
            }
        }
        $data->condition_bookingoption_requiredcount = $requiredcount;
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
     * Returns true if the event object corresponds to the configured option id.
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool {
        $event = $context->event ?? null;
        if (!$event) {
            return false;
        }
        if ($event instanceof bookingoption_completed) {
            $candidateoptionids = $this->optionids;
            if (empty($candidateoptionids) && !empty($this->optionid)) {
                $candidateoptionids = [$this->optionid];
            }

            if (empty($candidateoptionids)) {
                return false;
            }

            $userid = (int)($context->userid ?? $event->relateduserid ?? $event->userid ?? 0);
            if (empty($userid)) {
                return false;
            }

            $requiredcount = min(max(1, (int)$this->requiredcount), count($candidateoptionids));
            $completedcount = 0;

            foreach ($candidateoptionids as $candidateoptionid) {
                $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)$candidateoptionid);
                if (empty($optionsettings->id)) {
                    continue;
                }

                $bookinganswers = singleton_service::get_instance_of_booking_answers($optionsettings);
                if ((int)$bookinganswers->is_activity_completed($userid) === 1) {
                    $completedcount++;
                    if ($completedcount >= $requiredcount) {
                        return true;
                    }
                }
            }

            return false;
        }
        return false;
    }

    /**
     * Validate logic-specific form data.
     *
     * @param array $data
     * @return array
     */
    public function validate(array $data): array {
        $errors = [];

        if (empty($data['condition_bookingoption_optionid'])) {
            $errors['condition_bookingoption_optionid'] = get_string('error:entervalue', 'mod_booking');
        }

        if (empty($data['condition_bookingoption_requiredcount'])) {
            $errors['condition_bookingoption_requiredcount'] = get_string('error:entervalue', 'mod_booking');
        }
        return $errors;
    }
}
