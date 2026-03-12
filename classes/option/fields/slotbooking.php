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
 * Slot booking option field.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use context_course;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\field_base;
use mod_booking\option\type_resolver;
use MoodleQuickForm;
use stdClass;

/**
 * Class to manage slot booking settings on booking option form.
 */
class slotbooking extends field_base {
    /** @var int field sort id */
    public static $id = 206;

    /** @var int execution timing */
    public static $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /** @var string form header */
    public static $header = MOD_BOOKING_HEADER_GENERAL;

    /** @var array field categories */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /** @var array alternative import identifiers */
    public static $alternativeimportidentifiers = [
        'slot_enabled',
    ];

    /** @var array incompatible fields */
    public static $incompatiblefields = [];

    /**
     * Persist JSON fragment in booking_options.json before postsave actions.
     *
     * @param stdClass $formdata form data
     * @param stdClass $newoption option object to persist
     * @param int $updateparam update mode
     * @param mixed $returnvalue unused
     * @return array
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        type_resolver::normalize_formdata($formdata, (int)($newoption->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT));
        booking_option::add_data_to_json(
            $newoption,
            'slot_enabled',
            (int)$formdata->slot_enabled
        );

        return [];
    }

    /**
     * Add slot booking settings to option form.
     *
     * @param MoodleQuickForm $mform form
     * @param array $formdata form context
     * @param array $optionformconfig unused
     * @param array $fieldstoinstanciate unused
     * @param bool $applyheader unused
     * @return void
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {
        global $DB;

        $mform->addElement(
            'header',
            'slotsettingsheader',
            '<i class="fa fa-fw fa-calendar" aria-hidden="true"></i>&nbsp;' . get_string('slot_settings_header', 'mod_booking')
        );
        $mform->setExpanded('slotsettingsheader', false);

        $mform->addElement('hidden', 'slot_enabled', 0);
        $mform->setType('slot_enabled', PARAM_INT);

        $mform->hideIf('slotsettingsheader', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement('static', 'slot_price_source_info', '', get_string('slot_price_source_info', 'mod_booking'));
        $mform->hideIf('slot_price_source_info', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement('static', 'slot_session_dates_hint', '', get_string('slot_session_dates_hint', 'mod_booking'));
        $mform->hideIf('slot_session_dates_hint', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement('select', 'slot_type', get_string('slot_type', 'mod_booking'), [
            'fixed' => get_string('slot_type_fixed', 'mod_booking'),
            'rolling' => get_string('slot_type_rolling', 'mod_booking'),
            'session' => get_string('slot_type_session', 'mod_booking'),
        ]);
        $mform->setType('slot_type', PARAM_ALPHA);
        $mform->hideIf('slot_type', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->registerNoSubmitButton('btn_slot_type');
        $mform->addElement(
            'submit',
            'btn_slot_type',
            'xxx',
            [
                'class' => 'd-none',
                'data-action' => 'btn_slot_type',
            ]
        );

        $mform->addElement('text', 'slot_duration_minutes', get_string('slot_duration_minutes', 'mod_booking'));
        $mform->setType('slot_duration_minutes', PARAM_INT);
        $mform->hideIf('slot_duration_minutes', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_duration_minutes', 'slot_type', 'eq', 'session');

        $mform->addElement('text', 'slot_interval_minutes', get_string('slot_interval_minutes', 'mod_booking'));
        $mform->setType('slot_interval_minutes', PARAM_INT);
        $mform->hideIf('slot_interval_minutes', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_interval_minutes', 'slot_type', 'eq', 'fixed');
        $mform->hideIf('slot_interval_minutes', 'slot_type', 'eq', 'session');

        $mform->addElement('text', 'slot_opening_time', get_string('slot_opening_time', 'mod_booking'));
        $mform->setType('slot_opening_time', PARAM_TEXT);
        $mform->hideIf('slot_opening_time', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_opening_time', 'slot_type', 'eq', 'session');

        $mform->addElement('text', 'slot_closing_time', get_string('slot_closing_time', 'mod_booking'));
        $mform->setType('slot_closing_time', PARAM_TEXT);
        $mform->hideIf('slot_closing_time', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_closing_time', 'slot_type', 'eq', 'session');

        $mform->addElement('date_selector', 'slot_valid_from', get_string('slot_valid_from', 'mod_booking'));
        $mform->setType('slot_valid_from', PARAM_INT);
        $mform->hideIf('slot_valid_from', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_valid_from', 'slot_type', 'eq', 'session');

        $mform->addElement('date_selector', 'slot_valid_until', get_string('slot_valid_until', 'mod_booking'));
        $mform->setType('slot_valid_until', PARAM_INT);
        $mform->hideIf('slot_valid_until', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_valid_until', 'slot_type', 'eq', 'session');

        $mform->addElement('advcheckbox', 'slot_day_1', get_string('slot_day_mon', 'mod_booking'));
        $mform->addElement('advcheckbox', 'slot_day_2', get_string('slot_day_tue', 'mod_booking'));
        $mform->addElement('advcheckbox', 'slot_day_3', get_string('slot_day_wed', 'mod_booking'));
        $mform->addElement('advcheckbox', 'slot_day_4', get_string('slot_day_thu', 'mod_booking'));
        $mform->addElement('advcheckbox', 'slot_day_5', get_string('slot_day_fri', 'mod_booking'));
        $mform->addElement('advcheckbox', 'slot_day_6', get_string('slot_day_sat', 'mod_booking'));
        $mform->addElement('advcheckbox', 'slot_day_7', get_string('slot_day_sun', 'mod_booking'));

        for ($i = 1; $i <= 7; $i++) {
            $mform->setType('slot_day_' . $i, PARAM_INT);
            $mform->hideIf('slot_day_' . $i, 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
            $mform->hideIf('slot_day_' . $i, 'slot_type', 'eq', 'session');
        }

        $mform->addElement('text', 'slot_max_participants_per_slot', get_string('slot_max_participants_per_slot', 'mod_booking'));
        $mform->setType('slot_max_participants_per_slot', PARAM_INT);
        $mform->hideIf('slot_max_participants_per_slot', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement('text', 'slot_max_slots_per_user', get_string('slot_max_slots_per_user', 'mod_booking'));
        $mform->setType('slot_max_slots_per_user', PARAM_INT);
        $mform->hideIf('slot_max_slots_per_user', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement('select', 'slot_booking_view_mode', get_string('slot_booking_view_mode', 'mod_booking'), [
            'list' => get_string('slot_booking_view_list', 'mod_booking'),
            'calendar' => get_string('slot_booking_view_calendar', 'mod_booking'),
        ]);
        $mform->setType('slot_booking_view_mode', PARAM_ALPHA);
        $mform->hideIf('slot_booking_view_mode', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $teacheroptions = [];
        if (!empty($formdata['cmid'])) {
            [$course] = get_course_and_cm_from_cmid((int)$formdata['cmid']);
            $coursecontext = context_course::instance($course->id);
            $teachers = get_enrolled_users($coursecontext, 'mod/booking:addinstance');
            foreach ($teachers as $teacher) {
                $teacheroptions[$teacher->id] = fullname($teacher);
            }
        }

        $mform->addElement('autocomplete', 'slot_teacher_pool', get_string('slot_teacher_pool', 'mod_booking'), $teacheroptions, [
            'multiple' => true,
            'tags' => false,
        ]);
        $mform->setType('slot_teacher_pool', PARAM_RAW_TRIMMED);
        $mform->hideIf('slot_teacher_pool', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement('text', 'slot_teachers_required', get_string('slot_teachers_required', 'mod_booking'));
        $mform->setType('slot_teachers_required', PARAM_INT);
        $mform->hideIf('slot_teachers_required', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
    }

    /**
     * Validate slot settings.
     *
     * @param array $data form values
     * @param array $files uploaded files
     * @param array $errors error collection
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        if ((int)($data['optiontype'] ?? MOD_BOOKING_OPTIONTYPE_DEFAULT) !== MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
            return $errors;
        }

        $slottype = (string)($data['slot_type'] ?? 'fixed');
        if (!in_array($slottype, ['fixed', 'rolling', 'session'], true)) {
            $errors['slot_type'] = get_string('required');
        }

        if ($slottype !== 'session') {
            if (!preg_match('/^\d{2}:\d{2}$/', (string)($data['slot_opening_time'] ?? ''))) {
                $errors['slot_opening_time'] = get_string('slot_error_timeformat', 'mod_booking');
            }

            if (!preg_match('/^\d{2}:\d{2}$/', (string)($data['slot_closing_time'] ?? ''))) {
                $errors['slot_closing_time'] = get_string('slot_error_timeformat', 'mod_booking');
            }

            if ((int)($data['slot_duration_minutes'] ?? 0) <= 0) {
                $errors['slot_duration_minutes'] = get_string('slot_error_positive', 'mod_booking');
            }

            if ($slottype === 'rolling' && (int)($data['slot_interval_minutes'] ?? 0) <= 0) {
                $errors['slot_interval_minutes'] = get_string('slot_error_positive', 'mod_booking');
            }

            if ((int)($data['slot_valid_until'] ?? 0) < (int)($data['slot_valid_from'] ?? 0)) {
                $errors['slot_valid_until'] = get_string('slot_error_validrange', 'mod_booking');
            }
        }

        if ((int)($data['slot_max_participants_per_slot'] ?? 0) <= 0) {
            $errors['slot_max_participants_per_slot'] = get_string('slot_error_positive', 'mod_booking');
        }

        if ((int)($data['slot_max_slots_per_user'] ?? 0) <= 0) {
            $errors['slot_max_slots_per_user'] = get_string('slot_error_positive', 'mod_booking');
        }

        $mode = (string)($data['slot_booking_view_mode'] ?? 'calendar');
        if (!in_array($mode, ['list', 'calendar'], true)) {
            $errors['slot_booking_view_mode'] = get_string('required');
        }

        if ((int)($data['slot_teachers_required'] ?? 0) < 0) {
            $errors['slot_teachers_required'] = get_string('slot_error_nonnegative', 'mod_booking');
        }

        return $errors;
    }

    /**
     * Save slot settings to dedicated table and base slot price to booking_prices.
     *
     * @param stdClass $formdata form data
     * @param stdClass $option option record
     * @return void
     */
    public static function save_data(stdClass &$formdata, stdClass &$option) {
        global $DB;

        type_resolver::normalize_formdata($formdata, (int)($option->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT));

        $optionid = (int)$option->id;

        if (empty($formdata->slot_enabled)) {
            $DB->delete_records('booking_slot_config', ['optionid' => $optionid]);
            return;
        }

        $now = time();
        $record = new stdClass();
        $record->optionid = $optionid;
        $slottype = (string)($formdata->slot_type ?? 'fixed');
        if (!in_array($slottype, ['fixed', 'rolling', 'session'], true)) {
            $slottype = 'fixed';
        }

        $record->slot_type = $slottype;
        $record->slot_duration_minutes = max(1, (int)($formdata->slot_duration_minutes ?? 30));
        $record->slot_interval_minutes = $record->slot_type === 'rolling'
            ? max(1, (int)($formdata->slot_interval_minutes ?? 15))
            : $record->slot_duration_minutes;
        $record->opening_time = $record->slot_type === 'session'
            ? '00:00'
            : (string)($formdata->slot_opening_time ?? '08:00');
        $record->closing_time = $record->slot_type === 'session'
            ? '23:59'
            : (string)($formdata->slot_closing_time ?? '18:00');
        $record->valid_from = $record->slot_type === 'session' ? 0 : (int)($formdata->slot_valid_from ?? 0);
        $record->valid_until = $record->slot_type === 'session' ? 0 : (int)($formdata->slot_valid_until ?? 0);
        $record->days_of_week = $record->slot_type === 'session' ? '1,2,3,4,5,6,7' : self::extract_days_of_week($formdata);
        $record->max_participants_per_slot = max(1, (int)($formdata->slot_max_participants_per_slot ?? 1));
        $record->max_slots_per_user = max(1, (int)($formdata->slot_max_slots_per_user ?? 1));
        $record->booking_interface = ($formdata->slot_booking_view_mode ?? 'calendar') === 'calendar' ? 'calendar' : 'list';
        $record->teacher_pool = json_encode(array_values(array_map('intval', (array)($formdata->slot_teacher_pool ?? []))));
        $record->teachers_required = max(0, (int)($formdata->slot_teachers_required ?? 0));
        $record->timemodified = $now;

        if ($existing = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', IGNORE_MISSING)) {
            $record->id = $existing->id;
            $record->timecreated = $existing->timecreated;
            $DB->update_record('booking_slot_config', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('booking_slot_config', $record);
        }
    }

    /**
     * Set form defaults from slot config and option JSON.
     *
     * @param stdClass $data form data container
     * @param booking_option_settings $settings option settings
     * @return void
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        global $DB;

        $optionid = (int)($data->id ?? $settings->id ?? 0);

        $data->slot_enabled = 0;
        $data->slot_type = 'fixed';
        $data->slot_duration_minutes = 30;
        $data->slot_interval_minutes = 15;
        $data->slot_opening_time = '08:00';
        $data->slot_closing_time = '18:00';
        $data->slot_valid_from = 0;
        $data->slot_valid_until = 0;
        $data->slot_max_participants_per_slot = 1;
        $data->slot_max_slots_per_user = 1;
        $data->slot_booking_view_mode = 'calendar';
        $data->slot_teacher_pool = [];
        $data->slot_teachers_required = 0;
        for ($i = 1; $i <= 7; $i++) {
            $field = 'slot_day_' . $i;
            $data->{$field} = ($i <= 5) ? 1 : 0;
        }

        if (!empty($optionid)) {
            $config = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', IGNORE_MISSING);
            if (!empty($config)) {
                $data->slot_enabled = 1;
                $data->slot_type = $config->slot_type;
                $data->slot_duration_minutes = (int)$config->slot_duration_minutes;
                $data->slot_interval_minutes = (int)$config->slot_interval_minutes;
                $data->slot_opening_time = $config->opening_time;
                $data->slot_closing_time = $config->closing_time;
                $data->slot_valid_from = (int)$config->valid_from;
                $data->slot_valid_until = (int)$config->valid_until;
                $data->slot_max_participants_per_slot = (int)$config->max_participants_per_slot;
                $data->slot_max_slots_per_user = (int)$config->max_slots_per_user;
                $interface = (string)($config->booking_interface ?? 'calendar');
                $data->slot_booking_view_mode = in_array(
                    $interface,
                    ['list', 'calendar'],
                    true
                )
                    ? (string)$config->booking_interface
                    : 'calendar';
                $data->slot_teachers_required = (int)$config->teachers_required;

                $pool = json_decode((string)$config->teacher_pool, true);
                if (is_array($pool)) {
                    $data->slot_teacher_pool = array_values(array_map('intval', $pool));
                }

                for ($i = 1; $i <= 7; $i++) {
                    $field = 'slot_day_' . $i;
                    $data->{$field} = 0;
                }
                $days = array_filter(array_map('intval', explode(',', (string)$config->days_of_week)));
                foreach ($days as $day) {
                    if ($day >= 1 && $day <= 7) {
                        $field = 'slot_day_' . $day;
                        $data->{$field} = 1;
                    }
                }
            }
        }

        type_resolver::normalize_formdata($data, (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT));
    }

    /**
     * Extract selected weekdays from form.
     *
     * @param stdClass $formdata form data
     * @return string
     */
    private static function extract_days_of_week(stdClass $formdata): string {
        $days = [];
        for ($i = 1; $i <= 7; $i++) {
            $field = 'slot_day_' . $i;
            if (!empty($formdata->{$field})) {
                $days[] = $i;
            }
        }

        if (empty($days)) {
            $days = [1, 2, 3, 4, 5];
        }

        return implode(',', $days);
    }
}
