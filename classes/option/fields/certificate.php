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
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\dates_handler;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use tool_certificate\certificate as toolCertificate;
use MoodleQuickForm;
use stdClass;
use tool_certificate\template;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer‚‚
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificate extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_CERTIFICATE;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_NORMAL;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_CERTIFICATE;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [];

    /**
     * List the expiration fields to be stored in json files.
     *
     * @var array
     */
    public static $certificatedatekeys = ['expirydateabsolute', 'expirydaterelative', 'expirydatetype'];


    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param ?mixed $returnvalue
     * @return array
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        if (!class_exists('tool_certificate\certificate')) {
            return [];
        }

        $instance = new certificate();
        $changes = [];
        $key = fields_info::get_class_name(static::class);
        $value = $formdata->{$key} ?? null;
        $mockdata = new stdClass();
        $mockdata->id = $formdata->id;

        if (!empty($value)) {
            booking_option::add_data_to_json($newoption, $key, $formdata->{$key});
        } else {
            booking_option::remove_key_from_json($newoption, $key);
        }

        $certificatechanges = $instance->check_for_changes($formdata, $instance, $mockdata, $key, $value);
        if (!empty($certificatechanges)) {
            $changes[$key] = $certificatechanges;
        };

        // Add expiration key to json.
        $keys = self::$certificatedatekeys;
        // Process each field and save it to json ONLY if certificate has been selected.
        foreach ($keys as $key) {
            $valueexpirydate = $formdata->{$key} ?? null;

            if (!empty($valueexpirydate) && !empty($value)) {
                booking_option::add_data_to_json($newoption, $key, $formdata->{$key});
            } else {
                booking_option::remove_key_from_json($newoption, $key);
            }

            $certificatechanges = $instance->check_for_changes($formdata, $instance, $mockdata, $key, $value);
            if (!empty($certificatechanges) && !empty($value)) {
                $changes[$key] = $certificatechanges;
            };
        }

        // We can return an warning message here.
        return ['changes' => $changes];
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
     * @return void
     *
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {

        if (!class_exists('tool_certificate\certificate')) {
            return;
        }

        // Check if PRO version is activated.
        if (wb_payment::pro_version_is_activated()) {
            global $DB;

            // Standardfunctionality to add a header to the mform (only if its not yet there).
            if ($applyheader) {
                fields_info::add_header_to_mform($mform, self::$header);
            }

            $records = $DB->get_records('tool_certificate_templates', []);
            $selection = [0 => 'no certificate selected'];
            foreach ($records as $record) {
                $selection[$record->id] = $record->name;
            }

            $mform->addElement('autocomplete', 'certificate', get_string('certificate', 'mod_booking'), $selection, []);
            $mform->setType('certificate', PARAM_INT);

            toolCertificate::add_expirydate_to_form($mform);
        } else {
            // If PRO version is not activated, we don't show the certificate field.
            // We can add a static text to inform the user.
            if ($applyheader) {
                fields_info::add_header_to_mform($mform, self::$header);
            }
            $mform->addElement(
                'static',
                'nolicenseforcertificate',
                get_string('licensekeycfg', 'mod_booking'),
                get_string('licensekeycfgdesc', 'mod_booking')
            );
        }
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        return $errors;
    }


    /**
     * Function to set the Data for the form.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     *
     * @return void
     *
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        if (!class_exists('tool_certificate\certificate')) {
            return;
        }
        // Add expiration key to set_data.
        $keys = self::$certificatedatekeys;
        // Process each field and save it to set_data.
        foreach ($keys as $key) {
            $valueexpirydate = $data->{$key} ?? null;

            if (!empty($valueexpirydate) && !empty($data->importing)) {
                $data->{$key} = $data->{$key} ?? booking_option::get_value_of_json_by_key((int) $data->id, $key) ?? 0;
            } else {
                $data->{$key} = booking_option::get_value_of_json_by_key((int) $data->id, $key) ?? 0;
            }
        }
        $key = fields_info::get_class_name(static::class);
        // Normally, we don't call set data after the first time loading.
        if (!empty($data->importing)) {
            $data->{$key} = $data->{$key} ?? booking_option::get_value_of_json_by_key((int) $data->id, $key) ?? 0;
        } else {
            $data->{$key} = booking_option::get_value_of_json_by_key((int) $data->id, $key) ?? 0;
        }
    }

    /**
     * Issue certificate.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return int
     *
     */
    public static function issue_certificate(int $optionid, int $userid) {
        $id = 0;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (!class_exists('tool_certificate\certificate')) {
            return $id;
        }
        // Get certificate id.
        $certificateid = booking_option::get_value_of_json_by_key($optionid, 'certificate') ?? 0;

        if (empty($certificateid)) {
            return $id;
        }

        $template = template::instance($certificateid);

        // Certificate expiry date key.
        $expirydatetype = booking_option::get_value_of_json_by_key($optionid, 'expirydatetype') ?? 0;
        $expirydateabsolute = booking_option::get_value_of_json_by_key($optionid, 'expirydateabsolute') ?? 0;
        $expirydaterelative = booking_option::get_value_of_json_by_key($optionid, 'expirydaterelative') ?? 0;
        $certificateexpirydate = toolCertificate::calculate_expirydate($expirydatetype, $expirydateabsolute, $expirydaterelative);
        if (!empty($expirydatetype) && $certificateexpirydate < time()) {
            return $id;
        }
        // Create Certificate.
        if ($template->can_issue($userid)) {
            $id = $template->issue_certificate(
                $userid,
                $certificateexpirydate,
                [
                    'bookingoptionid' => $settings->id,
                    'bookingoptionname' => $settings->get_title_with_prefix(),
                    'bookingoptiondescription' => strip_tags($settings->description),
                    'location' => $settings->location,
                    'institution' => $settings->institution,
                    'teachers' => self::return_teachers_for_certificate($settings->teachers),
                    'sessions' => self::return_sessions_for_certificate($settings->sessions),
                    'duration' => self::return_duration_for_certificate($settings),
                ]
            );
        }
        return $id;
    }

    /**
     * Helper function to return Teachers for certificate.
     *
     * @param array $teachers
     *
     * @return string
     *
     */
    private static function return_teachers_for_certificate(array $teachers) {
        $certificateteachers = [];
        foreach ($teachers as $teacher) {
            $certificateteachers[] = "$teacher->firstname $teacher->lastname";
        }
        return implode("<br />", $certificateteachers);
    }

    /**
     * Helper function to return Duration for certificate.
     *
     * @param object $settings
     *
     * @return string
     *
     */
    private static function return_duration_for_certificate(object $settings) {
        if (!empty($settings->sessions)) {
            $duration = 0;
            foreach ($settings->sessions as $session) {
                $duration += ($session->courseendtime - $session->coursestarttime);
            }
        } else if (
            !empty($settings->courseendtime)
            && !empty($settings->coursestarttime)
            && $settings->courseendtime > $settings->coursestarttime
        ) {
            $duration = $settings->courseendtime - $settings->coursestarttime;
        } else {
            return '';
        }
        $hours = (string)floor($duration / 3600);
        $minutes = (string)floor(($duration % 3600) / 60);
        $a = new stdClass();
        $a->hours = $hours;
        $a->minutes = $minutes;
        return get_string('durationforcertificate', 'mod_booking', $a);
    }

    /**
     * Helper function to return Sessions for certificate.
     *
     * @param array $sessions
     *
     * @return string
     *
     */
    private static function return_sessions_for_certificate(array $sessions) {
        $dates = "";
        foreach ($sessions as $session) {
            $dates .= dates_handler::prettify_optiondates_start_end(
                $session->coursestarttime,
                $session->courseendtime,
                current_language(),
                false
            ) . "<br />";
        }
        return $dates;
    }

    /**
     * Return values for bookingoption_updated event.
     *
     * @param array $changes
     *
     * @return array
     *
     */
    public function get_changes_description(array $changes): array {
        if (!class_exists('tool_certificate\certificate')) {
            return[];
        }

        global $DB;

        $oldvalue = (int) $changes['oldvalue'] ?? 0;
        $newvalue = (int) $changes['newvalue'] ?? 0;

        $oldvaluestr = '';
        $newvaluestr = '';
        $fieldnamestring = get_string($changes['fieldname'], 'mod_booking');

        // Actual value changes has been displayed for certificate only.
        // For changes in expiration dates or its type - only general message will be shown.
        switch ($changes['formkey']) {
            case 'certificate':
                if (!empty($oldvalue)) {
                    $certname = $DB->get_field('tool_certificate_templates', 'name', ['id' => $oldvalue]);
                    $oldvaluestr = get_string(
                        'changesinentity',
                        'mod_booking',
                        (object) ['id' => $oldvalue, 'name' => ($certname ?? '')]
                    );
                }
                if (!empty($newvalue)) {
                    $certname = $DB->get_field('tool_certificate_templates', 'name', ['id' => $newvalue]);
                    $newvaluestr = get_string(
                        'changesinentity',
                        'mod_booking',
                        (object) ['id' => $newvalue, 'name' => ($certname ?? '')]
                    );
                }
                break;
            case 'expirydateabsolute':
                $fieldnamestring = get_string('changesinexpirydateabsolute', 'mod_booking');
                break;
            case 'expirydaterelative':
                $fieldnamestring = get_string('changesinexpirydaterelative', 'mod_booking');
                break;
            case 'expirydatetype':
                $fieldnamestring = get_string('changesinexpirydatetype', 'mod_booking');
                break;
        }

        $infotext = get_string('changeinfochanged', 'mod_booking', $fieldnamestring);

        if (empty($oldvaluestr) && empty($newvaluestr)) {
            $returnarray['info'] = $infotext;
        } else {
            $returnarray = [
                'oldvalue' => $oldvaluestr,
                'newvalue' => $newvaluestr,
                'fieldname' => get_string($changes['fieldname'], 'mod_booking'),
            ];
        }

        return $returnarray;
    }
}
