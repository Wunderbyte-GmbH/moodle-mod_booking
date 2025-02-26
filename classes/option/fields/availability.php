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
 * Control and manage booking dates.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_AVAILABILITY;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_AVAILABILITY;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [
        'boavenrolledincourse',
        'boavenrolledincohorts',
        'bo_cond_customform_restrict',
    ];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [
        MOD_BOOKING_OPTION_FIELD_EASY_BOOKINGCLOSINGTIME,
        MOD_BOOKING_OPTION_FIELD_EASY_BOOKINGOPENINGTIME,
        MOD_BOOKING_OPTION_FIELD_EASY_AVAILABILITY_PREVIOUSLYBOOKED,
        MOD_BOOKING_OPTION_FIELD_EASY_AVAILABILITY_SELECTUSERS,
    ];

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param ?mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        // Save the additional JSON conditions (the ones which have been added to the mform).
        bo_info::save_json_conditions_from_form($formdata);
        // As availability class can be used without also calling bookingopening and -closing time, we need to call them here.
        bookingopeningtime::prepare_save_field($formdata, $newoption, $updateparam);
        bookingclosingtime::prepare_save_field($formdata, $newoption, $updateparam);

        $newoption->availability = $formdata->availability;
        if (empty($newoption->sqlfilter)) {
            $newoption->sqlfilter = $formdata->sqlfilter;
        }

        $instance = new availability();
        return $instance->check_for_changes($formdata, $instance);
    }

    /**
     * Instance form definition
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

        $optionid = $formdata['id'];

        // Todo: expert/simple mode needs to work with this too!
        // Add availability conditions.
        bo_info::add_conditions_to_mform($mform, $optionid);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        global $DB;

        // Availability normally comes from settings, but it might come from the importer as well.
        if (!empty($data->importing)) {
            if (!empty($data->availability)) {
                $availability = $data->availability;
            } else {
                $availability = $settings->availability ?? "{}";
                $data->availability = $availability;
            }

            // On importing, we support the boavenrolledincourse key.
            if (!empty($data->boavenrolledincourse)) {
                $items = explode(',', $data->boavenrolledincourse);

                [$inorequal, $params] = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
                $sql = "SELECT id
                        FROM {course}
                        WHERE shortname $inorequal";
                $courses = $DB->get_records_sql($sql, $params);

                $data->bo_cond_enrolledincourse_courseids = array_keys($courses);
                $data->bo_cond_enrolledincourse_restrict = 1;
                // The operator defaults to "OR", but can be set via the boavenrolledincourseoperator column.
                $data->bo_cond_enrolledincourse_courseids_operator
                    = $data->boavenrolledincourseoperator ?? 'OR';
                unset($data->boavenrolledincourse);
                unset($data->boavenrolledincourseoperator);
            }
            // We do the some for the boavenrolledincohorts key.
            if (!empty($data->boavenrolledincohorts)) {
                $items = explode(',', $data->boavenrolledincohorts);
                [$inorequal, $params] = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
                $sql = "SELECT id
                        FROM {cohort}
                        WHERE idnumber $inorequal";
                $cohorts = $DB->get_records_sql($sql, $params);

                $data->bo_cond_enrolledincohorts_cohortids = array_keys($cohorts);
                $data->bo_cond_enrolledincohorts_restrict = 1;
                // The operator defaults to "OR", but can be set via the boavenrolledincourseoperator column.
                $data->bo_cond_enrolledincohorts_cohortids_operator = $data->boavenrolledincohortsoperator ?? 'OR';
                unset($data->boavenrolledincohorts);
                unset($data->boavenrolledincohortsoperator);
            }
        } else {
            $availability = $settings->availability ?? "{}";
            bookingopeningtime::set_data($data, $settings);
            bookingclosingtime::set_data($data, $settings);
        }

        if (!empty($availability)) {
            $jsonobject = json_decode($availability);
            bo_info::set_defaults($data, $jsonobject);
        }
    }

    /**
     * Check if there is a difference between the former and the new values of the formdata.
     *
     * @param stdClass $formdata
     * @param field_base $self
     * @param mixed $mockdata // Only needed if there the object needs params for the save_data function.
     * @param string $key
     * @param mixed $value
     *
     * @return array
     *
     */
    public function check_for_changes(
        stdClass $formdata,
        field_base $self,
        $mockdata = '',
        string $key = '',
        $value = ''
    ): array {

        $changes = [];

        $excludeclassesfromtrackingchanges = MOD_BOOKING_CLASSES_EXCLUDED_FROM_CHANGES_TRACKING;

        $classname = fields_info::get_class_name(static::class);
        if (in_array($classname, $excludeclassesfromtrackingchanges)) {
            return $changes;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings(
            $formdata->optionid ?? $formdata->id
        );

        if ($settings->availability != $formdata->availability) {
            $changes = [
                'changes' => [
                    'fieldname' => 'availability',
                    'formkey' => 'availability',
                ],
            ];
        }
        return $changes;
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        bo_info::validation($data, $files, $errors);
        return $errors;
    }
}
