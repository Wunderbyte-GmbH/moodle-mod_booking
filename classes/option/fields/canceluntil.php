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

use context_system;
use core\notification;
use local_shopping_cart\shopping_cart_history;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\option\time_handler;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class canceluntil extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_CANCELUNTIL;

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
    public static $header = MOD_BOOKING_HEADER_ADVANCEDOPTIONS;

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
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        // We store the information until when a booking option can be cancelled in the JSON.
        // So this has to happen BEFORE JSON is saved!
        if (empty($formdata->canceluntilcheckbox)) {
            // This will store the correct JSON to $optionvalues->json.
            booking_option::remove_key_from_json($newoption, "canceluntil");
        } else {
            booking_option::add_data_to_json($newoption, "canceluntil", $formdata->canceluntil);
        }

        $instance = new canceluntil();
        $changes = $instance->check_for_changes($formdata, $instance);

        return $changes;
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

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $mform->addElement('advcheckbox', 'canceluntilcheckbox', get_string('canceluntil', 'mod_booking'));
        if ($mform->elementExists('disablecancel')) {
            $mform->disabledIf('canceluntilcheckbox', 'disablecancel', 'checked');
        }
        $mform->addElement('date_time_selector', 'canceluntil', '', time_handler::set_timeintervall());
        $mform->setDefault('canceluntil', time_handler::prettytime(time()));
        $mform->disabledIf('canceluntil', 'canceluntilcheckbox');
        $mform->setType('canceluntil', PARAM_INT);

        // Purchases via shopping cart keep the deadline they were bought with. A cashier can pass
        // the deadline of the option on to them, see apply_to_existing_purchases().
        $purchases = self::count_purchases_with_deadline((int)$optionid);
        if ($purchases > 0 && self::can_apply_to_purchases((int)$optionid)) {
            $mform->addElement(
                'advcheckbox',
                'canceluntilapplytopurchases',
                get_string('canceluntilapplytopurchases', 'mod_booking'),
                '',
                // The number is shown in the confirmation dialog (mod_booking/dynamiceditoptionform).
                ['data-purchases' => $purchases]
            );
            $mform->setDefault('canceluntilapplytopurchases', 0);
            $mform->addHelpButton('canceluntilapplytopurchases', 'canceluntilapplytopurchases', 'mod_booking');
            if ($mform->elementExists('disablecancel')) {
                $mform->disabledIf('canceluntilapplytopurchases', 'disablecancel', 'checked');
            }
            // Set by the confirmation dialog only, validation() insists on it.
            $mform->addElement('hidden', 'canceluntilapplyconfirmed', 0);
            $mform->setType('canceluntilapplyconfirmed', PARAM_INT);
        }
    }

    /**
     * The deadline is only passed on to existing purchases after an explicit confirmation by a cashier.
     *
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {

        if (empty($data['canceluntilapplytopurchases']) || !empty($data['importing'])) {
            return;
        }

        if (!self::can_apply_to_purchases((int)($data['id'] ?? 0))) {
            $errors['canceluntilapplytopurchases'] = get_string('canceluntilapplytopurchasesnopermission', 'mod_booking');
        } else if (empty($data['canceluntilapplyconfirmed'])) {
            $errors['canceluntilapplytopurchases'] = get_string('canceluntilapplytopurchasesnotconfirmed', 'mod_booking');
        }
    }

    /**
     * Whether the current user may pass the cancellation deadline of an option on to its existing purchases.
     *
     * The purchases belong to local_shopping_cart, so its cashier capability decides (a cashier may cancel
     * any purchase regardless of the deadline anyway). The right to edit the option is not enough.
     *
     * @param int $optionid
     * @return bool
     */
    public static function can_apply_to_purchases(int $optionid): bool {
        return !empty($optionid)
            && class_exists(shopping_cart_history::class)
            // Older versions of local_shopping_cart do not offer this.
            && method_exists(shopping_cart_history::class, 'extend_canceluntil_for_item')
            && has_capability('local/shopping_cart:cashier', context_system::instance());
    }

    /**
     * Number of paid or pending purchases of the option that have a cancellation deadline.
     *
     * @param int $optionid
     * @return int
     */
    private static function count_purchases_with_deadline(int $optionid): int {
        if (
            empty($optionid)
            || !class_exists(shopping_cart_history::class)
            || !method_exists(shopping_cart_history::class, 'get_purchases_with_earlier_canceluntil')
        ) {
            return 0;
        }
        // No deadline (0) is later than every deadline, so this returns all purchases that have one.
        return count(shopping_cart_history::get_purchases_with_earlier_canceluntil('mod_booking', 'option', $optionid, 0));
    }

    /**
     * Passes the cancellation deadline of the option on to its existing purchases, if this was requested.
     *
     * Shopping cart stores the deadline with every purchase at checkout and only checks that stored date,
     * so a deadline changed afterwards does not reach users who already bought. Has to be called after the
     * option is saved and its cache is purged, because the deadline that is applied is the one that is in
     * effect now: the date of the option or, without one, the date calculated from the booking instance.
     * Deadlines are only extended, never shortened.
     *
     * @param stdClass $formdata data of the option form
     * @param int $optionid
     * @return int number of changed purchases
     */
    public static function apply_to_existing_purchases(stdClass $formdata, int $optionid): int {

        // Only for a confirmed request from the option form, never for imports and webservices.
        if (
            empty($formdata->canceluntilapplytopurchases)
            || empty($formdata->canceluntilapplyconfirmed)
            || !empty($formdata->importing)
            || !self::can_apply_to_purchases($optionid)
        ) {
            return 0;
        }

        $canceluntil = (int)booking_option::return_cancel_until_date($optionid);

        // Shopping cart checks the capability again and logs every changed purchase.
        $changed = shopping_cart_history::extend_canceluntil_for_item('mod_booking', 'option', $optionid, $canceluntil);

        if ($changed > 0) {
            // The cancel button depends on the date stored with the purchase.
            booking_option::purge_cache_for_option($optionid);
        }

        $a = (object)[
            'count' => $changed,
            'date' => empty($canceluntil)
                ? get_string('canceluntilapplytopurchasesnodeadline', 'mod_booking')
                : userdate($canceluntil, get_string('strftimedatetime', 'langconfig')),
        ];
        if ($changed > 0) {
            notification::success(get_string('canceluntilapplytopurchasesdone', 'mod_booking', $a));
        } else {
            notification::info(get_string('canceluntilapplytopurchasesnone', 'mod_booking', $a));
        }

        return $changed;
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        if (!empty($data->importing)) {
            // IMPORTING.
            if (!is_numeric($data->canceluntil)) {
                $data->canceluntil = strtotime($data->canceluntil);
            }

            $data->canceluntil = $data->canceluntil ?? booking_option::get_value_of_json_by_key($data->id, "canceluntil") ?? 0;
            if (!empty($data->canceluntil)) {
                $data->canceluntilcheckbox = 1;
            }
        } else {
            $canceluntil = booking_option::get_value_of_json_by_key($data->id, "canceluntil");
            if (!empty($canceluntil)) {
                $data->canceluntilcheckbox = 1;
                $data->canceluntil = $canceluntil;
            }
            // A one-time action, not a setting: the form reloaded after saving must not offer it ticked again.
            $data->canceluntilapplytopurchases = 0;
            $data->canceluntilapplyconfirmed = 0;
        }
    }

    /**
     * Check if there is a difference between the former and the new values of the formdata.
     *
     * @param stdClass $formdata
     * @param field_base $self
     * @param mixed $mockdata // Only needed if there the object needs params for the save_data function.
     * @param string|null $key
     * @param mixed $value
     *
     * @return array
     *
     */
    public function check_for_changes(
        stdClass $formdata,
        field_base $self,
        $mockdata = '',
        string|null $key = null,
        $value = ''
    ): array {

        if (!isset($self)) {
            return [];
        }

        $excludeclassesfromtrackingchanges = MOD_BOOKING_CLASSES_EXCLUDED_FROM_CHANGES_TRACKING;

        $classname = 'canceluntil';
        if (in_array($classname, $excludeclassesfromtrackingchanges)) {
            return [];
        }

        $changes = [];

        if (!empty($formdata->canceluntilcheckbox)) {
            $newvalue = $formdata->canceluntil;
        } else {
            $newvalue = "";
        }

        // Check if there were changes and return these.
        if (!empty($formdata->id) && isset($value)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);
            $mockdata = new stdClass();
            $mockdata->id = $formdata->id;
            $self::set_data($mockdata, $settings);

            if (!empty($mockdata->canceluntilcheckbox)) {
                $oldvalue = $settings->canceluntil;
            } else {
                $oldvalue = "";
            }

            if (
                $oldvalue != $newvalue
                && !(empty($oldvalue) && empty($newvalue))
            ) {
                $changes = [
                    'changes' => [
                        'fieldname' => $classname,
                        'oldvalue' => $oldvalue,
                        'newvalue' => $newvalue,
                        'formkey' => 'canceluntil',
                    ],
                ];
            }
        }
        return $changes;
    }
}
