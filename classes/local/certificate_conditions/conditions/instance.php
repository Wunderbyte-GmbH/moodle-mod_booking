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

use context_module;
use core\context;
use mod_booking\booking;
use mod_booking\event\bookingoption_completed;
use mod_booking\local\certificate_conditions\certificate_conditions_interface;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Certificate condition: user must complete N options from a selected booking instance.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance implements certificate_conditions_interface {
    /**
     * Booking instance ID (booking.id).
     *
     * @var int
     */
    public $bookingid = 0;

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
        $cmid = (int)($ajaxformdata['cmid'] ?? 0);
        if (empty($cmid) && !empty($ajaxformdata['contextid'])) {
            $context = context::instance_by_id((int)$ajaxformdata['contextid'], IGNORE_MISSING);
            if ($context instanceof context_module) {
                $cmid = $context->instanceid;
            }
        }

        $instanceoptions = [
            'tags' => false,
            'multiple' => false,
            'ajax' => 'mod_booking/form_booking_instances_selector',
            'valuehtmlcallback' => function ($value) {
                global $DB, $OUTPUT;

                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }

                $record = $DB->get_record_sql(
                    "SELECT b.id, b.name AS text, c.fullname AS coursename, cm.visible AS visibility
                       FROM {booking} b
                       JOIN {course} c ON c.id = b.course
                       JOIN {modules} m ON m.name = 'booking'
                       JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = b.id
                       WHERE b.id = :id",
                    ['id' => (int)$value]
                );

                if (empty($record)) {
                    return get_string('choose...', 'mod_booking');
                }

                $record->visibility = empty($record->visible)
                    ? get_string('hiddenfromstudents')
                    : get_string('visible');

                return $OUTPUT->render_from_template(
                    'mod_booking/form_booking_instances_selector_suggestion',
                    $record
                );
            },
        ];

        $mform->addElement(
            'autocomplete',
            'condition_instance_bookingid',
            get_string('condition_instance_bookingid', 'mod_booking'),
            [],
            $instanceoptions
        );
        $mform->setType('condition_instance_bookingid', PARAM_INT);

        if (!empty($cmid)) {
            $element = $mform->getElement('condition_instance_bookingid');
            if ($element) {
                $element->updateAttributes(['data-cmid' => $cmid]);
            }
        }

        $mform->addElement(
            'text',
            'condition_instance_requiredcount',
            get_string('condition_instance_requiredcount', 'mod_booking')
        );
        $mform->setType('condition_instance_requiredcount', PARAM_INT);
        $mform->setDefault('condition_instance_requiredcount', 1);
    }

    /**
     * Return logic label.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_logic(bool $localized = true): string {
        return $localized ? get_string('condition_instance', 'mod_booking') : 'condition_instance';
    }

    /**
     * Persist logic configuration into JSON payload.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_condition(stdClass &$data): void {
        $bookingid = (int)($data->condition_instance_bookingid ?? 0);
        $requiredcount = max(1, (int)($data->condition_instance_requiredcount ?? 1));

        $jsonobject = new stdClass();
        $jsonobject->conditionname = 'instance';
        $jsonobject->bookingid = $bookingid;
        $jsonobject->requiredcount = $requiredcount;
        $data->conditionjson = json_encode($jsonobject);
    }

    /**
     * Save a sentinel item so the booking option form can do an indexed lookup.
     *
     * @param int $conditionid
     * @param stdClass $data
     * @return void
     */
    public function save_items(int $conditionid, stdClass $data): void {
        global $DB;

        $bookingid = (int)($data->condition_instance_bookingid ?? 0);

        $DB->delete_records(
            'booking_cert_cond_item',
            ['conditionid' => $conditionid, 'component' => 'mod_booking', 'area' => 'bookinginstance']
        );

        if (empty($bookingid)) {
            return;
        }

        $record = new stdClass();
        $record->conditionid = $conditionid;
        $record->itemid = $bookingid;
        $record->component = 'mod_booking';
        $record->area = 'bookinginstance';
        $record->configjson = json_encode([]);
        $record->sortorder = 0;
        $DB->insert_record('booking_cert_cond_item', $record);
    }

    /**
     * Set default form values from existing record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return void
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        $bookingid = 0;
        $requiredcount = 1;

        if (!empty($record->logicjson)) {
            $jsonobject = json_decode($record->logicjson);
            if ($jsonobject) {
                $bookingid = (int)($jsonobject->bookingid ?? 0);
                $requiredcount = max(1, (int)($jsonobject->requiredcount ?? 1));
            }
        }

        $data->condition_instance_bookingid = $bookingid;
        $data->condition_instance_requiredcount = $requiredcount;
    }

    /**
     * Set internal logic data from condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_logicdata(stdClass $record): void {
        $this->bookingid = 0;
        $this->requiredcount = 1;

        if (!empty($record->logicjson)) {
            $jsonobject = json_decode($record->logicjson);
            if ($jsonobject) {
                $this->bookingid = (int)($jsonobject->bookingid ?? 0);
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
            $this->bookingid = (int)($jsonobject->bookingid ?? 0);
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
     * Returns true when the user has completed the required number of options in the booking instance.
     *
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool {
        $event = $context->event ?? null;
        if (!$event) {
            return false;
        }

        if (!($event instanceof bookingoption_completed)) {
            return false;
        }

        if (empty($this->bookingid)) {
            return false;
        }

        $userid = (int)($context->userid ?? $event->relateduserid ?? $event->userid ?? 0);
        if (empty($userid)) {
            return false;
        }

        // Get all option IDs in this booking instance.
        $alloptionids = booking::get_all_optionids($this->bookingid);
        if (empty($alloptionids)) {
            return false;
        }

        $requiredcount = min(max(1, (int)$this->requiredcount), count($alloptionids));
        $completedcount = 0;

        foreach ($alloptionids as $optionid) {
            $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)$optionid);
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

    /**
     * Validate logic-specific form data.
     *
     * @param array $data
     * @return array
     */
    public function validate(array $data): array {
        $errors = [];

        if (empty($data['condition_instance_bookingid'])) {
            $errors['condition_instance_bookingid'] = get_string('error:entervalue', 'mod_booking');
        }

        if (empty($data['condition_instance_requiredcount']) || (int)$data['condition_instance_requiredcount'] < 1) {
            $errors['condition_instance_requiredcount'] = get_string('error:entervalue', 'mod_booking');
        }

        return $errors;
    }
}
