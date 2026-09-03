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
 * Option type field.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use dml_exception;
use mod_booking\booking_option_settings;
use mod_booking\option\field_base;
use mod_booking\option\fields_info;
use mod_booking\option\type_resolver;
use mod_booking\local\selflearning\selflearning_feature;
use mod_booking\local\slotbooking\slot_feature;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use MoodleQuickForm;
use stdClass;

/**
 * Class for top-level option type selector.
 */
class optiontype extends field_base {
    /** @var int execution order id */
    public static $id = MOD_BOOKING_OPTION_FIELD_OPTIONTYPE;

    /** @var int execution timing */
    public static $save = MOD_BOOKING_EXECUTION_NORMAL;

    /** @var string header section */
    public static $header = MOD_BOOKING_HEADER_GENERAL;

    /** @var array categories */
    public static $fieldcategories = [
        MOD_BOOKING_OPTION_FIELD_NECESSARY,
        MOD_BOOKING_OPTION_FIELD_STANDARD,
    ];

    /** @var array alternative import identifiers */
    public static $alternativeimportidentifiers = [];

    /** @var array incompatible fields */
    public static $incompatiblefields = [];

    /**
     * Save resolved type in booking_options.type.
     *
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param mixed $returnvalue
     * @return array
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        $newoption->type = type_resolver::normalize_formdata($formdata, (int)($newoption->type ?? 0));
        return [];
    }

    /**
     * Render option type select on top of option form.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
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

        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $optionid = (int)($formdata['id'] ?? $formdata['optionid'] ?? 0);
        $hasslotanswers = 0;
        $currenttype = MOD_BOOKING_OPTIONTYPE_DEFAULT;
        if ($optionid > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $currenttype = (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT);
            $isslotoption = $currenttype === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;

            if ($isslotoption) {
                $hasslotanswers = $DB->record_exists_select(
                    'booking_answers',
                    'optionid = :optionid
                        AND waitinglist NOT IN (:statusnotbooked, :statusdeleted)',
                    [
                        'optionid' => $optionid,
                        'statusnotbooked' => MOD_BOOKING_STATUSPARAM_NOTBOOKED,
                        'statusdeleted' => MOD_BOOKING_STATUSPARAM_DELETED,
                    ]
                ) ? 1 : 0;
            }
        }

        $selflearningcourselabel = get_string('selflearningcourse', 'mod_booking');
        if (!empty(get_config('booking', 'selflearningcourselabel'))) {
            $selflearningcourselabel = get_config('booking', 'selflearningcourselabel');
        }

        $options = [
            MOD_BOOKING_OPTIONTYPE_DEFAULT => get_string('optiontype_withdates', 'mod_booking'),
        ];

        // Self-learning courses need PRO and the admin toggle. An option that already is of this
        // type keeps it in the list even if the feature was switched off later on, so that editing
        // such an option does not silently reset its type.
        if (
            selflearning_feature::is_enabled()
            || $currenttype === MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE
        ) {
            $options[MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE] = $selflearningcourselabel;
        }

        // Same for slot booking: an option that already is a slot option keeps the type in the
        // list, so an expired licence or a disabled toggle does not reset it behind the scenes.
        if (
            slot_feature::is_enabled()
            || $currenttype === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING
        ) {
            $options[MOD_BOOKING_OPTIONTYPE_SLOTBOOKING] = get_string('optiontype_slotbooking', 'mod_booking');
        }

        // Only render the select when there actually is something to choose from. With neither
        // self-learning courses nor slot booking available, the default type is the only one
        // possible, so it is stored silently via a hidden field.
        $showselect = count($options) > 1;

        if ($showselect) {
            $mform->addElement('select', 'optiontype', get_string('type', 'mod_booking'), $options);
        } else {
            $mform->addElement('hidden', 'optiontype', MOD_BOOKING_OPTIONTYPE_DEFAULT);
        }
        $mform->setType('optiontype', PARAM_INT);
        $mform->setDefault('optiontype', MOD_BOOKING_OPTIONTYPE_DEFAULT);

        if (!wb_payment::pro_version_is_activated()) {
            $mform->addElement(
                'static',
                'optiontypeslotbookinghint',
                '',
                '<i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;' .
                get_string(
                    'optiontypeslotbookinghint',
                    'mod_booking',
                    'https://showroom.wunderbyte.at/course/view.php?id=62'
                )
            );
        }

        $mform->addElement('hidden', 'slot_type_change_has_answers', $hasslotanswers);
        $mform->setType('slot_type_change_has_answers', PARAM_INT);

        $mform->addElement(
            'static',
            'slot_type_change_warning_text',
            '',
            \html_writer::div(get_string('slot_type_change_warning', 'mod_booking'), 'alert alert-warning mb-2')
        );
        $mform->hideIf('slot_type_change_warning_text', 'slot_type_change_has_answers', 'eq', 0);
        $mform->hideIf('slot_type_change_warning_text', 'optiontype', 'eq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        $mform->addElement(
            'advcheckbox',
            'slot_type_change_confirm',
            '',
            get_string('slot_type_change_confirm', 'mod_booking')
        );
        $mform->setType('slot_type_change_confirm', PARAM_INT);
        $mform->hideIf('slot_type_change_confirm', 'slot_type_change_has_answers', 'eq', 0);
        $mform->hideIf('slot_type_change_confirm', 'optiontype', 'eq', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING);

        if ($showselect) {
            // The hidden no-submit button is only triggered by a change of the select.
            $mform->registerNoSubmitButton('btn_optiontype');
            $mform->addElement(
                'submit',
                'btn_optiontype',
                get_string('optiontype', 'mod_booking'),
                [
                    'class' => 'd-none',
                    'data-action' => 'btn_optiontype',
                ]
            );
        }
    }

    /**
     * Set option type and synchronized flags for form defaults.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        if (!empty($data->importing)) {
            $data->selflearningcourse = $data->selflearningcourse
                ?? $settings->selflearningcourse ?? 0;
        } else if (!isset($data->selflearningcourse) && !empty($settings->selflearningcourse)) {
            $data->selflearningcourse = $settings->selflearningcourse;
        }

        if (!empty($data->selflearningcourse)) {
            $data->optiontype = MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE;
        }

        if (!isset($data->optiontype)) {
            $data->optiontype = in_array((int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT), [
                MOD_BOOKING_OPTIONTYPE_DEFAULT,
                MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE,
                MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            ], true) ? (int)$settings->type : MOD_BOOKING_OPTIONTYPE_DEFAULT;
        } else if ($data->optiontype == MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE) {
            $data->selflearningcourse = 1;
        }

        // The resolver drops a slot type that may not be chosen, but keeps it for options that
        // already are stored as slot options.
        type_resolver::normalize_formdata($data, (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT));
    }

    /**
     * Validate selected option type.
     *
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        global $DB;

        $type = (int)($data['optiontype'] ?? MOD_BOOKING_OPTIONTYPE_DEFAULT);

        $optionid = (int)($data['id'] ?? $data['optionid'] ?? 0);
        $currenttype = MOD_BOOKING_OPTIONTYPE_DEFAULT;
        if ($optionid > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $currenttype = (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT);
        }

        // Switching an option to slot booking needs the feature. Options that already are of this
        // type may keep it, so they stay editable after the licence expired or the toggle went off.
        if ($type === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING && $currenttype !== MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
            if (!wb_payment::pro_version_is_activated()) {
                $errors['optiontype'] = get_string('proversiononly', 'mod_booking');
                return $errors;
            }
            if (!slot_feature::is_enabled()) {
                // PRO is active but the admin toggle (booking/slotbookingactive) is off.
                $errors['optiontype'] = get_string('turnthisoninsettings', 'mod_booking');
                return $errors;
            }
        }

        // Switching an option to self-learning needs the feature. Options that already are of this
        // type may keep it, so they stay editable after the feature has been switched off.
        if (
            $type === MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE
            && $currenttype !== MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE
            && !selflearning_feature::is_enabled()
        ) {
            $errors['optiontype'] = get_string('turnthisoninsettings', 'mod_booking');
        }

        if ($optionid <= 0) {
            return $errors;
        }

        if ($currenttype !== MOD_BOOKING_OPTIONTYPE_SLOTBOOKING || $type === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
            return $errors;
        }

        $hasslotanswers = $DB->record_exists_select(
            'booking_answers',
            'optionid = :optionid
                AND waitinglist NOT IN (:statusnotbooked, :statusdeleted)',
            [
                'optionid' => $optionid,
                'statusnotbooked' => MOD_BOOKING_STATUSPARAM_NOTBOOKED,
                'statusdeleted' => MOD_BOOKING_STATUSPARAM_DELETED,
            ]
        );

        if (!$hasslotanswers) {
            return $errors;
        }

        $confirmed = !empty($data['slot_type_change_confirm']);
        if (!$confirmed) {
            $errors['slot_type_change_confirm'] = get_string('slot_type_change_warning', 'mod_booking');
        }

        return $errors;
    }
}
