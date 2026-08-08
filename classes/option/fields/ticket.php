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
 * Ticketing configuration of a booking option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle the ticketing configuration of a booking option.
 *
 * The site administration only switches ticketing on or off. Everything that describes a
 * concrete ticket — which tool_certificate template is used as design, whether the ticket is
 * bound to its holder, and whether entry staff has to confirm the holder's identity — belongs
 * to the individual booking option and is stored in its json column.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_TICKET;

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
    public static $header = MOD_BOOKING_HEADER_TICKET;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = ['tickettemplate'];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [];

    /**
     * All keys of this field which are stored in the json column of the booking option.
     *
     * The first key is the class name itself and holds the template id.
     *
     * @var array
     */
    public static $ticketkeys = [
        ticket_manager::JSON_PERSONALIZED,
        ticket_manager::JSON_CONFIRMIDENTITY,
        ticket_manager::JSON_EXTRAINFO,
    ];

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

        if (!class_exists('tool_certificate\\template')) {
            return [];
        }

        $key = fields_info::get_class_name(static::class);
        // If the ticket field was not part of the submitted form at all (feature off, import),
        // keep the stored configuration untouched. Only a submitted empty value clears it.
        if (!isset($formdata->{$key})) {
            return [];
        }

        $instance = new ticket();
        $changes = [];
        $templateid = (int) ($formdata->{$key} ?? 0);
        $mockdata = new stdClass();
        $mockdata->id = $formdata->id;

        if (!empty($templateid)) {
            booking_option::add_data_to_json($newoption, $key, $templateid);
        } else {
            booking_option::remove_key_from_json($newoption, $key);
        }

        $ticketchanges = $instance->check_for_changes($formdata, $instance, $mockdata, $key, $templateid);
        if (!empty($ticketchanges)) {
            $changes[$key] = $ticketchanges;
        }

        // The remaining settings only make sense together with a template.
        foreach (self::$ticketkeys as $ticketkey) {
            $value = $formdata->{$ticketkey} ?? null;

            if (!empty($templateid) && !empty($value)) {
                booking_option::add_data_to_json($newoption, $ticketkey, $value);
            } else {
                booking_option::remove_key_from_json($newoption, $ticketkey);
            }

            $ticketchanges = $instance->check_for_changes($formdata, $instance, $mockdata, $ticketkey, $value);
            if (!empty($ticketchanges) && !empty($templateid)) {
                $changes[$ticketkey] = $ticketchanges;
            }
        }

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
        global $DB;

        if (
            !class_exists('tool_certificate\\template')
            || empty(get_config('booking', 'bookingticketon'))
        ) {
            return;
        }

        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $records = $DB->get_records('tool_certificate_templates', [], 'name ASC', 'id, name');
        $selection = [0 => get_string('noticketselected', 'mod_booking')];
        foreach ($records as $record) {
            $selection[$record->id] = format_string($record->name);
        }

        $mform->addElement('autocomplete', 'ticket', get_string('ticket', 'mod_booking'), $selection, []);
        $mform->addHelpButton('ticket', 'ticket', 'mod_booking');
        $mform->setType('ticket', PARAM_INT);

        $mform->addElement(
            'advcheckbox',
            ticket_manager::JSON_PERSONALIZED,
            get_string('ticketpersonalized', 'mod_booking'),
            get_string('ticketpersonalizedlabel', 'mod_booking'),
            [],
            [0, 1]
        );
        $mform->addHelpButton(ticket_manager::JSON_PERSONALIZED, 'ticketpersonalized', 'mod_booking');
        $mform->setDefault(ticket_manager::JSON_PERSONALIZED, 1);
        $mform->hideIf(ticket_manager::JSON_PERSONALIZED, 'ticket', 'eq', 0);

        $mform->addElement(
            'advcheckbox',
            ticket_manager::JSON_CONFIRMIDENTITY,
            get_string('ticketconfirmidentity', 'mod_booking'),
            get_string('ticketconfirmidentitylabel', 'mod_booking'),
            [],
            [0, 1]
        );
        $mform->addHelpButton(ticket_manager::JSON_CONFIRMIDENTITY, 'ticketconfirmidentity', 'mod_booking');
        $mform->setDefault(ticket_manager::JSON_CONFIRMIDENTITY, 0);
        $mform->hideIf(ticket_manager::JSON_CONFIRMIDENTITY, 'ticket', 'eq', 0);

        $mform->addElement(
            'textarea',
            ticket_manager::JSON_EXTRAINFO,
            get_string('ticketextrainfo', 'mod_booking'),
            ['rows' => 4, 'cols' => 60]
        );
        $mform->addHelpButton(ticket_manager::JSON_EXTRAINFO, 'ticketextrainfo', 'mod_booking');
        $mform->setType(ticket_manager::JSON_EXTRAINFO, PARAM_TEXT);
        $mform->hideIf(ticket_manager::JSON_EXTRAINFO, 'ticket', 'eq', 0);

        // Tickets are delivered by a booking rule, not by this form.
        $mform->addElement(
            'static',
            'ticketconfiguredviarule',
            '',
            get_string('ticketconfiguredviarule', 'mod_booking')
        );
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        global $DB;

        if (!class_exists('tool_certificate\\template')) {
            return $errors;
        }
        $templateid = (int) ($data['ticket'] ?? 0);
        if (!empty($templateid) && !$DB->record_exists('tool_certificate_templates', ['id' => $templateid])) {
            $errors['ticket'] = get_string('tickettemplatemissing', 'mod_booking');
        }
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

        if (!class_exists('tool_certificate\\template')) {
            return;
        }

        $keys = array_merge([fields_info::get_class_name(static::class)], self::$ticketkeys);
        foreach ($keys as $key) {
            // The free text field defaults to an empty string, the others to 0.
            $default = $key === ticket_manager::JSON_EXTRAINFO ? '' : 0;
            // On import the value coming from the file wins, otherwise we always load from json.
            if (!empty($data->importing)) {
                $data->{$key} = $data->{$key} ?? booking_option::get_value_of_json_by_key((int) $data->id, $key) ?? $default;
            } else {
                $data->{$key} = booking_option::get_value_of_json_by_key((int) $data->id, $key) ?? $default;
            }
        }
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
        global $DB;

        if (!class_exists('tool_certificate\\template')) {
            return [];
        }

        $oldvalue = $changes['oldvalue'] ?? '';
        $newvalue = $changes['newvalue'] ?? '';
        $fieldnamestring = get_string($changes['fieldname'], 'mod_booking');
        $oldvaluestr = '';
        $newvaluestr = '';

        // Only the template change carries a readable value. For the flags a generic message is enough.
        if (($changes['formkey'] ?? '') === 'ticket') {
            if (!empty($oldvalue)) {
                $name = $DB->get_field('tool_certificate_templates', 'name', ['id' => (int) $oldvalue]);
                $oldvaluestr = get_string(
                    'changesinentity',
                    'mod_booking',
                    (object) ['id' => (int) $oldvalue, 'name' => ($name ?: '')]
                );
            }
            if (!empty($newvalue)) {
                $name = $DB->get_field('tool_certificate_templates', 'name', ['id' => (int) $newvalue]);
                $newvaluestr = get_string(
                    'changesinentity',
                    'mod_booking',
                    (object) ['id' => (int) $newvalue, 'name' => ($name ?: '')]
                );
            }
        }

        if (empty($oldvaluestr) && empty($newvaluestr)) {
            return ['info' => get_string('changeinfochanged', 'mod_booking', $fieldnamestring)];
        }

        return [
            'fieldname' => $fieldnamestring,
            'oldvalue' => $oldvaluestr,
            'newvalue' => $newvaluestr,
        ];
    }
}
