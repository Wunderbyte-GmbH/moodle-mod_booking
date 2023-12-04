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

use mod_booking\price as Mod_bookingPrice;
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
    public static $save = MOD_BOOKING_EXECUTION_NORMAL;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_PRICE;

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = 0): string {

        return parent::prepare_save_field($formdata, $newoption, $updateparam, '');
    }

    /**
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array $optionformconfig) {

        // Add price.
        $price = new Mod_bookingPrice('option', $formdata['optionid']);
        $price->add_price_to_mform($mform);
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {

        global $DB;

        // Price validation.
        if ($data["useprice"] == 1) {
            $pricecategories = $DB->get_records_sql("SELECT * FROM {booking_pricecategories} WHERE disabled = 0");
            foreach ($pricecategories as $pricecategory) {
                // Check for negative prices, they are not allowed.
                if (isset($data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"]) &&
                    $data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"] < 0) {
                    $errors["pricegroup_$pricecategory->identifier"] =
                        get_string('error:negativevaluenotallowed', 'mod_booking');
                }
                // If checkbox to use prices is turned on, we do not allow empty strings as prices!
                if (isset($data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"]) &&
                    $data["pricegroup_$pricecategory->identifier"]["bookingprice_$pricecategory->identifier"] === "") {
                    $errors["pricegroup_$pricecategory->identifier"] =
                        get_string('error:pricemissing', 'mod_booking');
                }
            }
        }

    }
}
