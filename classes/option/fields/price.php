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

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\field_base;
use mod_booking\price as Mod_bookingPrice;
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
class price extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_PRICE;

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
    public static $header = MOD_BOOKING_HEADER_PRICE;

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
        'useprice',
    ];

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
     * @param ?mixed $returnvalue
     * @return array // Changes are reported via the event in price class.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        // We store the information if we use a price in the JSON.
        // So this has to happen BEFORE JSON is saved!
        if (empty($formdata->useprice)) {
            // This will store the correct JSON to $optionvalues->json.
            booking_option::add_data_to_json($newoption, "useprice", 0); // 0 means no price.
        } else {
            booking_option::add_data_to_json($newoption, "useprice", 1); // 11 means we have a price.
        }

        parent::prepare_save_field($formdata, $newoption, $updateparam, '');

        // For changes in price fields, the bookingoption_updated event is triggered separately...
        // ...  in price class (price::add_price()). Hence no changes to report here.
        return [];
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

        // Add price.
        $price = new Mod_bookingPrice('option', $formdata['id']);
        $price->add_price_to_mform($mform);
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {

        $price = new Mod_bookingPrice('option', $data['id']);
        $price->validation($data, $errors);
    }

    /**
     * The save data function is very specific only for those values that should be saved...
     * ... after saving the option. This is so, when we need an option id for saving (because of other table).
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     * @throws dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option) {

        // Save the prices.
        $price = new Mod_bookingPrice('option', $option->id);

        $price->save_from_form($formdata);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        global $USER;

        // While importing, we need to set the imported prices.
        // Therefore, we first get the pricecategories.

        // Right now, the price handler still sets prices via default in the definition, NOT via set data.
        // This has to be fixed.

        $pricehandler = new Mod_bookingPrice('option', $data->id);
        $priceitems = Mod_bookingPrice::get_prices_from_cache_or_db('option', $data->id);

        if (!empty($data->importing)) {
            // This is for IMPORTING!

            if (!is_array($pricehandler->pricecategories)) {
                return;
            }

            foreach ($pricehandler->pricecategories as $category) {
                // If we have an imported value, we use it here.
                // To do this, we look in data for the price category identifier.
                if (isset($data->{$category->identifier}) && is_numeric($data->{$category->identifier})) {
                    $price = $data->{$category->identifier};
                    // We don't want this value to be used elsewhere.
                } else {
                    // Make sure that if prices exist, we do not lose them.
                    $items = array_filter($priceitems, fn($a) => $a->pricecategoryidentifier == $category->identifier);
                    $item = reset($items);
                    $price = $item->price ?? $category->defaultvalue ?? null;
                }

                if ($price !== null) {
                    $encodedkey = bin2hex($category->identifier);
                    $pricegroup = MOD_BOOKING_FORM_PRICEGROUP . $encodedkey;
                    $priceidentifier = MOD_BOOKING_FORM_PRICE . $encodedkey;
                    $data->{$pricegroup}[$priceidentifier] = $price;
                }
            }

            // Make sure that we want to use prices.
            // When there is at least one price set in data, we turn on the useprice flag.
            foreach ($pricehandler->pricecategories as $category) {
                if (!empty($data->{$category->identifier}) && is_numeric($data->{$category->identifier})) {
                    $data->useprice = 1;
                    break;
                }
            }
            // If price is always on, we also turn on the useprice flag.
            if (get_config('booking', 'priceisalwayson')) {
                $data->useprice = 1;
            }
            // If it is still not set, we use the original flag from settings.
            if (!isset($data->useprice)) {
                $data->useprice = $settings->useprice ?? 0;
            }
        } else {
            $useprice = booking_option::get_value_of_json_by_key($data->id, "useprice");

            // If the value is not set in JSON, we activate useprice if a price was found for the option.
            if ($useprice === null) {
                if (booking_option::has_price_set($data->id, $USER->id)) {
                    $data->useprice = 1;
                } else {
                    $data->useprice = 0;
                }
            } else if ($useprice == 0) {
                $data->useprice = 0;
            } else if ($useprice == 1) {
                $data->useprice = 1;
            }

            // If price is always on, we also turn on the useprice flag.
            if (get_config('booking', 'priceisalwayson')) {
                $data->useprice = 1;
            }

            $pricehandler->set_data($data);
        }
    }
}
