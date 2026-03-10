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
        if ($optionid > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $isslotoption = (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT) === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;

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
            MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE => $selflearningcourselabel,
            MOD_BOOKING_OPTIONTYPE_SLOTBOOKING => get_string('optiontype_slotbooking', 'mod_booking'),
        ];

        $mform->addElement('select', 'optiontype', get_string('type', 'mod_booking'), $options);
        $mform->setType('optiontype', PARAM_INT);
        $mform->setDefault('optiontype', MOD_BOOKING_OPTIONTYPE_DEFAULT);

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

        $mform->registerNoSubmitButton('btn_optiontype');
        $mform->addElement(
            'submit',
            'btn_optiontype',
            'xxx',
            [
                'class' => 'd-none',
                'data-action' => 'btn_optiontype',
            ]
        );
    }

    /**
     * Set option type and synchronized flags for form defaults.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        if (!isset($data->optiontype)) {
            $data->optiontype = in_array((int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT), [
                MOD_BOOKING_OPTIONTYPE_DEFAULT,
                MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE,
                MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            ], true) ? (int)$settings->type : MOD_BOOKING_OPTIONTYPE_DEFAULT;
        }

        type_resolver::normalize_formdata($data, (int)$data->optiontype);
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
        if ($type === MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE) {
            $selflearningactive = wb_payment::pro_version_is_activated()
                ? (int)get_config('booking', 'selflearningcourseactive')
                : 0;

            if ($selflearningactive !== 1) {
                $errors['optiontype'] = get_string('turnthisoninsettings', 'mod_booking');
            }
        }

        $optionid = (int)($data['id'] ?? $data['optionid'] ?? 0);
        if ($optionid <= 0) {
            return $errors;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $currenttype = (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT);

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
