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
use mod_booking\semester;
use mod_booking\singleton_service;
use mod_booking\option\field_base;
use mod_booking\option\type_resolver;
use html_writer;
use MoodleQuickForm;
use moodle_url;
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
        // The header's expand/collapse state is restored centrally in
        // fields_info::restore_header_collapse_state(), so it persists across no-submit reloads.

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
            'userdefined' => get_string('slot_type_userdefined', 'mod_booking'),
        ]);
        $mform->setType('slot_type', PARAM_ALPHA);
        $mform->hideIf('slot_type', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement('select', 'slot_booking_view_mode', get_string('slot_booking_view_mode', 'mod_booking'), [
            'list' => get_string('slot_booking_view_list', 'mod_booking'),
            'calendar' => get_string('slot_booking_view_calendar', 'mod_booking'),
        ]);
        $mform->setType('slot_booking_view_mode', PARAM_ALPHA);
        $mform->hideIf('slot_booking_view_mode', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_booking_view_mode', 'slot_type', 'eq', 'userdefined');

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
        $mform->hideIf('slot_duration_minutes', 'slot_type', 'eq', 'userdefined');

        $mform->addElement('text', 'slot_interval_minutes', get_string('slot_interval_minutes', 'mod_booking'));
        $mform->setType('slot_interval_minutes', PARAM_INT);
        $mform->hideIf('slot_interval_minutes', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_interval_minutes', 'slot_type', 'eq', 'fixed');
        $mform->hideIf('slot_interval_minutes', 'slot_type', 'eq', 'session');
        $mform->hideIf('slot_interval_minutes', 'slot_type', 'eq', 'userdefined');

        $mform->addElement('duration', 'slot_custom_max_duration', get_string('slot_custom_max_duration', 'mod_booking'));
        $mform->setType('slot_custom_max_duration', PARAM_INT);
        $mform->setDefault('slot_custom_max_duration', 30 * MINSECS);
        $mform->hideIf('slot_custom_max_duration', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_custom_max_duration', 'slot_type', 'neq', 'userdefined');

        $mform->addElement('duration', 'slot_custom_min_duration', get_string('slot_custom_min_duration', 'mod_booking'));
        $mform->setType('slot_custom_min_duration', PARAM_INT);
        $mform->setDefault('slot_custom_min_duration', 60 * MINSECS);
        $mform->hideIf('slot_custom_min_duration', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_custom_min_duration', 'slot_type', 'neq', 'userdefined');

        $mform->addElement('duration', 'slot_custom_max_days', get_string('slot_custom_max_days', 'mod_booking'));
        $mform->setType('slot_custom_max_days', PARAM_INT);
        $mform->setDefault('slot_custom_max_days', DAYSECS);
        $mform->hideIf('slot_custom_max_days', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_custom_max_days', 'slot_type', 'neq', 'userdefined');

        $mform->addElement(
            'select',
            'slot_custom_start_interval_minutes',
            get_string('slot_custom_start_interval_minutes', 'mod_booking'),
            [
                1 => '1',
                5 => '5',
                10 => '10',
                15 => '15',
                30 => '30',
                60 => '60',
            ]
        );
        $mform->setType('slot_custom_start_interval_minutes', PARAM_INT);
        $mform->setDefault('slot_custom_start_interval_minutes', 30);
        $mform->hideIf('slot_custom_start_interval_minutes', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_custom_start_interval_minutes', 'slot_type', 'neq', 'userdefined');

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

        $mform->addElement('select', 'slot_add_examiners', get_string('slot_add_examiners_to_slots', 'mod_booking'), [
            0 => get_string('no'),
            1 => get_string('yes'),
        ]);
        $mform->setType('slot_add_examiners', PARAM_INT);
        $mform->setDefault('slot_add_examiners', 0);
        $mform->hideIf('slot_add_examiners', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_add_examiners', 'slot_type', 'eq', 'userdefined');

        // Examiner Pool: Autocomplete für beliebige Nutzer (wie bei Lehrerauswahl in anderen booking-Formularen).
        $useroptions = [];
        if (!empty($formdata['cmid'])) {
            global $CFG;

            require_once($CFG->dirroot . '/user/lib.php');

            [$course] = get_course_and_cm_from_cmid((int)$formdata['cmid']);
            $coursecontext = context_course::instance($course->id);
            // Alle Nutzer mit Namen und E-Mail (ggf. einschränken, z.B. auf course users oder site users, je nach Policy).
            $users = get_enrolled_users($coursecontext, '', 0, 'u.id, u.firstname, u.lastname, u.email');
            $userids = array_map(static function ($user): int {
                return (int)$user->id;
            }, $users);
            $loadedusers = !empty($userids) ? user_get_users_by_id($userids) : [];

            foreach ($userids as $userid) {
                if (empty($loadedusers[$userid])) {
                    continue;
                }
                $loadeduser = $loadedusers[$userid];
                $email = (string)($loadeduser->email ?? '');
                $useroptions[$userid] = fullname($loadeduser) . ' (' . $email . ')';
            }
        }
        $mform->addElement('autocomplete', 'slot_teacher_pool', get_string('slot_teacher_pool', 'mod_booking'), $useroptions, [
            'multiple' => true,
            'tags' => false,
            'placeholder' => get_string('chooseusers', 'mod_booking'),
        ]);
        $mform->setType('slot_teacher_pool', PARAM_INT);
        $mform->hideIf('slot_teacher_pool', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_teacher_pool', 'slot_add_examiners', 'eq', 0);
        $mform->hideIf('slot_teacher_pool', 'slot_type', 'eq', 'userdefined');

        $mform->addElement('text', 'slot_teachers_required', get_string('slot_teachers_required', 'mod_booking'));
        $mform->setType('slot_teachers_required', PARAM_INT);
        $mform->hideIf('slot_teachers_required', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
        $mform->hideIf('slot_teachers_required', 'slot_add_examiners', 'eq', 0);
        $mform->hideIf('slot_teachers_required', 'slot_type', 'eq', 'userdefined');

        $mform->addElement(
            'advcheckbox',
            'slot_allow_self_rebooking',
            get_string('slot_allow_self_rebooking', 'mod_booking')
        );
        $mform->setType('slot_allow_self_rebooking', PARAM_INT);
        $mform->setDefault('slot_allow_self_rebooking', 0);
        $mform->addHelpButton('slot_allow_self_rebooking', 'slot_allow_self_rebooking', 'mod_booking');
        $mform->hideIf('slot_allow_self_rebooking', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        // Relative per-slot deadline (minutes, signed) governing both move and cancel.
        // '' = inherit the instance/plugin default. Applies to slot booking regardless of the
        // rebooking opt-in, because it also gates self-cancellation.
        $deadlineoptions = [
            '' => get_string('slot_change_deadline_inherit', 'mod_booking'),
            1440 => get_string('slot_change_deadline_1440', 'mod_booking'),
            720 => get_string('slot_change_deadline_720', 'mod_booking'),
            120 => get_string('slot_change_deadline_120', 'mod_booking'),
            60 => get_string('slot_change_deadline_60', 'mod_booking'),
            30 => get_string('slot_change_deadline_30', 'mod_booking'),
            0 => get_string('slot_change_deadline_0', 'mod_booking'),
            -30 => get_string('slot_change_deadline_m30', 'mod_booking'),
            -60 => get_string('slot_change_deadline_m60', 'mod_booking'),
        ];
        $mform->addElement(
            'select',
            'slot_change_deadline_minutes',
            get_string('slot_change_deadline_minutes', 'mod_booking'),
            $deadlineoptions
        );
        $mform->setDefault('slot_change_deadline_minutes', '');
        $mform->addHelpButton('slot_change_deadline_minutes', 'slot_change_deadline_minutes', 'mod_booking');
        $mform->hideIf('slot_change_deadline_minutes', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $currentoptionid = (int)($formdata['optionid'] ?? $formdata['id'] ?? 0);
        $cmid = (int)($formdata['cmid'] ?? 0);

        if ($cmid > 0 && $currentoptionid > 0) {
            $ruleeditorurl = new moodle_url('/mod/booking/slotrules.php', [
                'id' => $cmid,
                'optionid' => $currentoptionid,
            ]);
            $ruleeditorlink = html_writer::link($ruleeditorurl, get_string('slot_rule_editor_open', 'mod_booking'));
            $mform->addElement(
                'static',
                'slot_rule_editor_link',
                get_string('slot_rule_editor_label', 'mod_booking'),
                $ruleeditorlink
            );
            $mform->hideIf('slot_rule_editor_link', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
            $mform->hideIf('slot_rule_editor_link', 'slot_add_examiners', 'eq', 0);
        } else {
            $mform->addElement(
                'static',
                'slot_rule_editor_info',
                get_string('slot_rule_editor_label', 'mod_booking'),
                get_string('slot_rule_editor_savefirst', 'mod_booking')
            );
            $mform->hideIf('slot_rule_editor_info', 'optiontype', 'neq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);
            $mform->hideIf('slot_rule_editor_info', 'slot_add_examiners', 'eq', 0);
        }
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
        if (!in_array($slottype, ['fixed', 'rolling', 'session', 'userdefined'], true)) {
            $errors['slot_type'] = get_string('required');
        }

        if ($slottype === 'userdefined') {
            if ((int)($data['slot_custom_max_duration'] ?? 0) <= 0) {
                $errors['slot_custom_max_duration'] = get_string('slot_error_positive', 'mod_booking');
            }
            if ((int)($data['slot_custom_min_duration'] ?? 0) <= 0) {
                $errors['slot_custom_min_duration'] = get_string('slot_error_positive', 'mod_booking');
            }
            if ((int)($data['slot_custom_max_days'] ?? 0) < DAYSECS) {
                $errors['slot_custom_max_days'] = get_string('slot_error_positive', 'mod_booking');
            }
            if ((int)($data['slot_custom_start_interval_minutes'] ?? 0) <= 0) {
                $errors['slot_custom_start_interval_minutes'] = get_string('slot_error_positive', 'mod_booking');
            }
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
        if (!in_array($slottype, ['fixed', 'rolling', 'session', 'userdefined'], true)) {
            $slottype = 'fixed';
        }

        $record->slot_type = $slottype;
        $record->slot_duration_minutes = $record->slot_type === 'userdefined'
            ? max(1, (int)floor((int)($formdata->slot_custom_max_duration ?? (30 * MINSECS)) / MINSECS))
            : max(1, (int)($formdata->slot_duration_minutes ?? 30));
        $record->slot_interval_minutes = $record->slot_type === 'rolling'
            ? max(1, (int)($formdata->slot_interval_minutes ?? 15))
            : ($record->slot_type === 'userdefined'
                ? max(1, (int)floor((int)($formdata->slot_custom_min_duration ?? (60 * MINSECS)) / MINSECS))
                : max(1, (int)($formdata->slot_interval_minutes ?? $record->slot_duration_minutes)));
        $record->slot_start_interval_minutes = $record->slot_type === 'userdefined'
            ? max(1, (int)($formdata->slot_custom_start_interval_minutes ?? 30))
            : 0;
        $record->opening_time = $record->slot_type === 'session'
            ? '00:00'
            : (string)($formdata->slot_opening_time ?? '08:00');
        $record->closing_time = $record->slot_type === 'session'
            ? '23:59'
            : (string)($formdata->slot_closing_time ?? '18:00');
        $record->valid_from = $record->slot_type === 'session' ? 0 : (int)($formdata->slot_valid_from ?? 0);
        $record->valid_until = $record->slot_type === 'session' ? 0 : (int)($formdata->slot_valid_until ?? 0);
        $record->days_of_week = $record->slot_type === 'session' ? '1,2,3,4,5,6,7' : self::extract_days_of_week($formdata);
        $record->slot_max_days_per_slot = max(1, (int)ceil((int)($formdata->slot_custom_max_days ?? DAYSECS) / DAYSECS));
        $record->max_participants_per_slot = max(1, (int)($formdata->slot_max_participants_per_slot ?? 1));
        $record->max_slots_per_user = max(1, (int)($formdata->slot_max_slots_per_user ?? 1));
        $record->booking_interface = ($formdata->slot_booking_view_mode ?? 'calendar') === 'calendar' ? 'calendar' : 'list';
        $addexaminers = $record->slot_type !== 'userdefined' && !empty($formdata->slot_add_examiners);
        $record->teacher_pool = json_encode($addexaminers ? self::extract_teacher_pool_from_formdata($formdata) : []);
        $record->teachers_required = $addexaminers ? max(0, (int)($formdata->slot_teachers_required ?? 0)) : 0;
        $record->allow_self_rebooking = !empty($formdata->slot_allow_self_rebooking) ? 1 : 0;
        // Relative per-slot move/cancel deadline. '' (empty) means inherit instance/plugin default
        // and is stored as NULL.
        $deadlineraw = $formdata->slot_change_deadline_minutes ?? '';
        $record->change_deadline_minutes = ($deadlineraw === '' || $deadlineraw === null)
            ? null
            : (int)$deadlineraw;
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
        $isimporting = !empty($data->importing);

        $setifmissing = static function (string $key, $value) use (&$data): void {
            if (!property_exists($data, $key)) {
                $data->{$key} = $value;
            }
        };

        // Import flow: preserve explicitly provided values and only backfill missing keys.
        if ($isimporting) {
            $setifmissing('slot_enabled', 0);
            $setifmissing('slot_type', 'fixed');
            $setifmissing('slot_duration_minutes', 30);
            $setifmissing('slot_interval_minutes', 15);
            $setifmissing('slot_custom_max_duration', 30 * MINSECS);
            $setifmissing('slot_custom_min_duration', 60 * MINSECS);
            $setifmissing('slot_custom_max_days', DAYSECS);
            $setifmissing('slot_custom_start_interval_minutes', 30);
            $setifmissing('slot_opening_time', '08:00');
            $setifmissing('slot_closing_time', '18:00');
            $setifmissing('slot_valid_from', 0);
            $setifmissing('slot_valid_until', 0);
            $setifmissing('slot_max_participants_per_slot', 1);
            $setifmissing('slot_max_slots_per_user', 1);
            $setifmissing('slot_booking_view_mode', 'calendar');
            $setifmissing('slot_add_examiners', 0);
            $setifmissing('slot_teacher_pool', []);
            $setifmissing('slot_teachers_required', 0);
            $setifmissing('slot_allow_self_rebooking', 0);
            $setifmissing('slot_change_deadline_minutes', '');

            for ($i = 1; $i <= 7; $i++) {
                $field = 'slot_day_' . $i;
                $setifmissing($field, ($i <= 5) ? 1 : 0);
            }

            if (!empty($optionid)) {
                $config = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', IGNORE_MISSING);
                if (!empty($config)) {
                    $setifmissing('slot_enabled', 1);
                    $setifmissing('slot_type', $config->slot_type);
                    $setifmissing('slot_duration_minutes', (int)$config->slot_duration_minutes);
                    $setifmissing('slot_interval_minutes', (int)$config->slot_interval_minutes);
                    $setifmissing('slot_custom_max_duration', max(1, (int)$config->slot_duration_minutes) * MINSECS);
                    $setifmissing('slot_custom_min_duration', max(1, (int)$config->slot_interval_minutes) * MINSECS);
                    $setifmissing('slot_custom_max_days', max(1, (int)($config->slot_max_days_per_slot ?? 1)) * DAYSECS);
                    $setifmissing('slot_custom_start_interval_minutes', max(1, (int)($config->slot_start_interval_minutes ?? 30)));
                    $setifmissing('slot_opening_time', $config->opening_time);
                    $setifmissing('slot_closing_time', $config->closing_time);
                    $setifmissing('slot_valid_from', (int)$config->valid_from);
                    $setifmissing('slot_valid_until', (int)$config->valid_until);
                    $setifmissing('slot_max_participants_per_slot', (int)$config->max_participants_per_slot);
                    $setifmissing('slot_max_slots_per_user', (int)$config->max_slots_per_user);

                    $interface = (string)($config->booking_interface ?? 'calendar');
                    $setifmissing(
                        'slot_booking_view_mode',
                        in_array($interface, ['list', 'calendar'], true) ? $interface : 'calendar'
                    );
                    $setifmissing('slot_teachers_required', (int)$config->teachers_required);
                    $setifmissing('slot_allow_self_rebooking', (int)($config->allow_self_rebooking ?? 0));
                    // NULL change_deadline_minutes means inherit -> represent as '' in the form.
                    $setifmissing(
                        'slot_change_deadline_minutes',
                        $config->change_deadline_minutes === null ? '' : (int)$config->change_deadline_minutes
                    );
                    $setifmissing(
                        'slot_add_examiners',
                        !empty($config->teacher_pool) || !empty($config->teachers_required) ? 1 : 0
                    );

                    $pool = json_decode((string)$config->teacher_pool, true);
                    if (is_array($pool) && !property_exists($data, 'slot_teacher_pool')) {
                        $data->slot_teacher_pool = array_values(array_map('intval', $pool));
                    }
                    if (!empty($data->slot_teacher_pool) && is_array($data->slot_teacher_pool)) {
                        foreach ($data->slot_teacher_pool as $teacherid) {
                            $fieldname = 'slot_teacher_pool_' . (int)$teacherid;
                            $setifmissing($fieldname, 1);
                        }
                    }

                    $days = array_filter(array_map('intval', explode(',', (string)$config->days_of_week)));
                    foreach ($days as $day) {
                        if ($day >= 1 && $day <= 7) {
                            $field = 'slot_day_' . $day;
                            $setifmissing($field, 1);
                        }
                    }
                }
            }

            self::apply_semester_slot_defaults($data, $settings);
            type_resolver::normalize_formdata($data, (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT));
            return;
        }

        $data->slot_enabled = 0;
        $data->slot_type = 'fixed';
        $data->slot_duration_minutes = 30;
        $data->slot_interval_minutes = 15;
        $data->slot_custom_max_duration = 30 * MINSECS;
        $data->slot_custom_min_duration = 60 * MINSECS;
        $data->slot_custom_max_days = DAYSECS;
        $data->slot_custom_start_interval_minutes = 30;
        $data->slot_opening_time = '08:00';
        $data->slot_closing_time = '18:00';
        $data->slot_valid_from = 0;
        $data->slot_valid_until = 0;
        $data->slot_max_participants_per_slot = 1;
        $data->slot_max_slots_per_user = 1;
        $data->slot_booking_view_mode = 'calendar';
        $data->slot_add_examiners = 0;
        $data->slot_teacher_pool = [];
        $data->slot_teachers_required = 0;
        $data->slot_allow_self_rebooking = 0;
        $data->slot_change_deadline_minutes = '';
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
                $data->slot_custom_max_duration = max(1, (int)$config->slot_duration_minutes) * MINSECS;
                $data->slot_custom_min_duration = max(1, (int)$config->slot_interval_minutes) * MINSECS;
                $data->slot_custom_max_days = max(1, (int)($config->slot_max_days_per_slot ?? 1)) * DAYSECS;
                $data->slot_custom_start_interval_minutes = max(1, (int)($config->slot_start_interval_minutes ?? 30));
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
                $data->slot_allow_self_rebooking = (int)($config->allow_self_rebooking ?? 0);
                $data->slot_change_deadline_minutes = $config->change_deadline_minutes === null
                    ? ''
                    : (int)$config->change_deadline_minutes;
                $data->slot_add_examiners = !empty($config->teacher_pool) || !empty($config->teachers_required) ? 1 : 0;

                $pool = json_decode((string)$config->teacher_pool, true);
                if (is_array($pool)) {
                    $data->slot_teacher_pool = array_values(array_map('intval', $pool));
                    foreach ($data->slot_teacher_pool as $teacherid) {
                        $fieldname = 'slot_teacher_pool_' . (int)$teacherid;
                        $data->{$fieldname} = 1;
                    }
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

        self::apply_semester_slot_defaults($data, $settings);
        type_resolver::normalize_formdata($data, (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT));
    }

    /**
     * Default the slot validity window to the effective semester's date range.
     *
     * The semester is taken from the booking option when it has one set, otherwise
     * from the booking instance (the option-level semester takes precedence). Only
     * an empty (0) slot date is backfilled, so dates the user explicitly chose are
     * always preserved.
     *
     * @param stdClass $data Form data being prepared.
     * @param booking_option_settings $settings Option settings (carries the option + instance link).
     * @return void
     */
    private static function apply_semester_slot_defaults(stdClass $data, booking_option_settings $settings): void {
        // Nothing to do once both ends already carry a value.
        if (!empty($data->slot_valid_from) && !empty($data->slot_valid_until)) {
            return;
        }

        // Option semester wins; fall back to the booking instance semester.
        $semesterid = (int)($settings->semesterid ?? 0);
        if (empty($semesterid) && !empty($data->bookingid)) {
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid((int)$data->bookingid);
            $semesterid = (int)($bookingsettings->semesterid ?? 0);
        }
        if (empty($semesterid)) {
            return;
        }

        $semester = new semester($semesterid);
        if (empty($semester->startdate) || empty($semester->enddate)) {
            return;
        }

        if (empty($data->slot_valid_from)) {
            $data->slot_valid_from = (int)$semester->startdate;
        }
        if (empty($data->slot_valid_until)) {
            $data->slot_valid_until = (int)$semester->enddate;
        }
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

    /**
     * Extract selected teacher ids from checkbox fields (with autocomplete fallback).
     *
     * @param stdClass $formdata form data
     * @return array<int, int>
     */
    private static function extract_teacher_pool_from_formdata(stdClass $formdata): array {
        $selected = [];
        foreach ((array)$formdata as $key => $value) {
            if (strpos((string)$key, 'slot_teacher_pool_') !== 0 || empty($value)) {
                continue;
            }

            $teacherid = (int)substr((string)$key, strlen('slot_teacher_pool_'));
            if ($teacherid > 0) {
                $selected[] = $teacherid;
            }
        }

        if (empty($selected) && !empty($formdata->slot_teacher_pool)) {
            $selected = array_values(array_map('intval', (array)$formdata->slot_teacher_pool));
        }

        $selected = array_values(array_unique(array_filter($selected, function ($id) {
            return $id > 0;
        })));
        sort($selected);

        return $selected;
    }
}
