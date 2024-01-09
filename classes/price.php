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
 * Price class.
 *
 * @package mod_booking
 * @copyright 2022 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache_helper;
use context_system;
use dml_exception;
use mod_booking\option\dates_handler;
use MoodleQuickForm;
use stdClass;
use lang_string;
use local_shopping_cart\shopping_cart;
use mod_booking\booking_option_settings;
use local_entities\entitiesrelation_handler;
use mod_booking\booking_campaigns\campaigns_info;
use mod_booking\booking_campaigns\booking_campaign;
use User;

define('MOD_BOOKING_FORM_PRICEGROUP', 'pricegroup_');
define('MOD_BOOKING_FORM_PRICE', 'bookingprice_');

/**
 * Class to handle prices.
 *
 * @package mod_booking
 * @copyright 2022 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price {

    /** @var array An array of all price categories. */
    public $pricecategories;

    /** @var string $area e.g. 'option' */
    public $area;

    /** @var int $itemid if area is 'option' then itemid will be the optionid */
    public $itemid;

    /**
     * Constructor.
     * @param string $area
     * @param int $itemid
     */
    public function __construct(string $area, int $itemid = 0) {
        global $DB;

        $this->pricecategories = $DB->get_records('booking_pricecategories', ['disabled' => 0]);
        $this->area = $area;
        $this->itemid = $itemid;
    }

    /**
     * Add form fields to passed on mform.
     *
     * @param MoodleQuickForm $mform reference to the Moodle form
     * @param bool $noformula can be used to turn price formula off (e.g. for subbookings)
     * @return void
     */
    public function add_price_to_mform(MoodleQuickForm &$mform, bool $noformula=false) {

        global $DB;

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we always show everything.
        $showpriceheader = true;
        $formmode = get_user_preferences('optionform_mode');
        if ($formmode !== 'expert') {
            $cfgpriceheader = $DB->get_field('booking_optionformconfig', 'active',
                ['elementname' => 'bookingoptionprice']);
            if ($cfgpriceheader === "0") {
                $showpriceheader = false;
            }
        }
        if ($showpriceheader) {
            $mform->addElement('header', 'bookingoptionprice',
                '<i class="fa fa-fw fa-money" aria-hidden="true"></i>&nbsp;' .
                get_string('bookingoptionprice', 'booking'));
        }

        // If there are no price categories yet, show an info text.
        if (empty($this->pricecategories)) {
            $mform->addElement('static', 'nopricecategoriesyet', get_string('nopricecategoriesyet', 'booking'));
        }

        $mform->addElement('advcheckbox', 'useprice', get_string('useprice', 'mod_booking'),
            null, null, [0, 1]);

        if (get_config('booking', 'priceisalwayson')) {
            $mform->hardFreeze('useprice');
        } else {
            $useprice = false;
        }

        $defaultexists = false;
        foreach ($this->pricecategories as $pricecategory) {
            $formgroup = [];

            $priceelement = $mform->createElement('float', MOD_BOOKING_FORM_PRICE . $pricecategory->identifier);
            $formgroup[] = $priceelement;

            $currencyelement = $mform->createElement('static', 'bookingpricecurrency', '', get_config('booking', 'globalcurrency'));
            $formgroup[] = $currencyelement;

            $mform->addGroup($formgroup, MOD_BOOKING_FORM_PRICEGROUP . $pricecategory->identifier, $pricecategory->name);
            $mform->disabledIf(MOD_BOOKING_FORM_PRICEGROUP . $pricecategory->identifier, 'useprice', 'neq', 1);
            // Determine the correct array identifier.
            $pricearrayidentifier = MOD_BOOKING_FORM_PRICEGROUP . $pricecategory->identifier .
                '[' . MOD_BOOKING_FORM_PRICE . $pricecategory->identifier . ']';
        }

        // Only when there is an actual price formula, we do apply it.
        $priceformula = get_config('booking', 'defaultpriceformula');
        if (!$noformula && !empty($priceformula) && is_json($priceformula)) {

            $mform->addElement('advcheckbox', 'priceformulaisactive', get_string('priceformulaisactive', 'mod_booking'),
            null, null, [0, 1]);
            $mform->setDefault('priceformulaisactive', 0);

            $mform->addElement('advcheckbox', 'priceformulaoff', get_string('priceformulaoff', 'mod_booking'),
            null, null, [0, 1]);
            $mform->addHelpButton('priceformulaoff', 'priceformulaoff', 'mod_booking');

            $formulaobj = new stdClass;
            $formulaobj->formula = $priceformula;

            $formulainfo = '<div class="alert alert-warning" role="alert">' .
                get_string('priceformulainfo', 'mod_booking', $formulaobj) . '</div>';

            $formulagroup = [];
            $formulagroup[] = $mform->createElement('static', 'priceformulainfo', '', $formulainfo);
            $mform->addGroup($formulagroup, 'priceformulagroup', '', ' ', false);
            $mform->hideIf('priceformulagroup', 'priceformulaisactive', 'noteq', 1);

            // Manual factor (multiplier).
            $mform->addElement('float', 'priceformulamultiply', get_string('priceformulamultiply', 'mod_booking'), null);
            $mform->addHelpButton('priceformulamultiply', 'priceformulamultiply', 'mod_booking');
            $mform->hideIf('priceformulamultiply', 'priceformulaisactive', 'noteq', 1);

            // Absolute value (summand).
            $mform->addElement('float', 'priceformulaadd', get_string('priceformulaadd', 'mod_booking'), null);
            $mform->addHelpButton('priceformulaadd', 'priceformulaadd', 'mod_booking');
            $mform->hideIf('priceformulaadd', 'priceformulaisactive', 'noteq', 1);
        }
    }

    /**
     * Set data function.
     * @param stdClass $data
     * @return void
     * @throws dml_exception
     */
    public function set_data(stdClass $data) {
        global $DB;
        foreach ($this->pricecategories as $pricecategory) {

            $pricearrayidentifier = MOD_BOOKING_FORM_PRICEGROUP . $pricecategory->identifier .
                '[' . MOD_BOOKING_FORM_PRICE . $pricecategory->identifier . ']';

            $pricegroup = MOD_BOOKING_FORM_PRICEGROUP . $pricecategory->identifier;
            $priceidentifier = MOD_BOOKING_FORM_PRICE . $pricecategory->identifier;

            // Get price for current option.
            $existingprice = $DB->get_field('booking_prices', 'price',
                ['area' => $this->area, 'itemid' => $this->itemid, 'pricecategoryidentifier' => $pricecategory->identifier]);

            $data->{$pricegroup}[$priceidentifier] = $existingprice ?? $pricecategory->default ?? 0;

            // If we have at least one price during import, we set useprice to 1.
            $data->useprice = 1;
        }

        // Only when there is an actual price formula, we do apply it.
        $priceformula = get_config('booking', 'defaultpriceformula');
        if (!empty($priceformula) && is_json($priceformula)) {
            // Get Settings.
            if (!empty($data->id) && ($data->id > 0)) {

                $optionid = $data->id;
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

                $data->priceformulaoff = $settings->priceformulaoff;
                $data->priceformulaadd = $settings->priceformulaadd;
                $data->priceformulamultiply = $settings->priceformulamultiply;
            } else {
                // Default values.
                $data->priceformulaoff = 0;
                $data->priceformulaadd = 0;
                $data->priceformulamultiply = 1;

            }
        }
    }

    /**
     * Calculate the price using the JSON formula
     * for a specific pricecategory within a booking option.
     *
     * @param stdClass $fromform data from form
     * @param string $priceformula the JSON string
     * @param string $pricecategoryidentifier identifier of the price category
     *
     * @return float the calculated price
     */
    public static function calculate_price_from_form(stdClass $fromform, string $priceformula, string $pricecategoryidentifier) {

        global $DB;

        if (!$pricecategory = $DB->get_record('booking_pricecategories', ['disabled' => 0,
            'identifier' => $pricecategoryidentifier,
            ])) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        if (!$jsonobject = json_decode($priceformula)) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        if (!empty($fromform->dayofweektime)) {
            // We need the dayofweektime split up.
            $dayinfo = dates_handler::prepare_day_info($fromform->dayofweektime);
        }

        // We start with the baseprice.
        $price = $pricecategory->defaultvalue;

        if ($price == 0) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        foreach ($jsonobject as $formulacomponent) {

            // For invalid JSON.
            if (is_string($formulacomponent)) {
                // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
                return 0;
            }

            $key = key((array) $formulacomponent); // For the PHP 8.1 compatibility.
            $value = $formulacomponent->$key;

            switch ($key) {
                // We apply each factor only once, after application we break the loop.
                case 'timeslot':
                    if (!empty($dayinfo)) {
                        self::apply_time_factor($value, $dayinfo, $price);
                    }
                    break;
                case 'customfield':
                    self::apply_customfield_factor_from_form($value, $fromform, $price);
                    break;
                case 'entity':
                    self::apply_entity_factor_from_form($fromform, $price);
                    break;
            }
        }

        /* Unit factor is not part of price formula but depends on the config setting educationalunitinminutes. */
        if (!empty($dayinfo) && get_config('booking', 'applyunitfactor')) {
            self::apply_unit_factor($dayinfo, $price);
        }

        // If setting to round prices is turned on, then round to integer.
        if (get_config('booking', 'roundpricesafterformula')) {
            $price = round($price);
        }

        return $price;
    }

    /**
     * Calculate the price using the JSON formula
     * for a specific pricecategory within a booking option.
     *
     * @param booking_option_settings $bookingoptionsettings data from bookingoption
     * @param string $priceformula the JSON string
     * @param string $pricecategoryidentifier identifier of the price category
     *
     * @return float the calculated price
     */
    public static function calculate_price_with_bookingoptionsettings($bookingoptionsettings, string $priceformula,
        string $pricecategoryidentifier) {

        global $DB;

        if (!$pricecategory = $DB->get_record('booking_pricecategories', ['disabled' => 0,
            'identifier' => $pricecategoryidentifier,
            ])) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        if (!$jsonobject = json_decode($priceformula)) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        if (!empty($bookingoptionsettings->dayofweektime)) {
            // We need the dayofweektime split up.
            $dayinfo = dates_handler::prepare_day_info($bookingoptionsettings->dayofweektime);
        }

        // We start with the baseprice.
        $price = $pricecategory->defaultvalue;

        if ($price == 0) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        foreach ($jsonobject as $formulacomponent) {

            // For invalid JSON.
            if (is_string($formulacomponent)) {
                // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
                return 0;
            }

            $key = key($formulacomponent);
            $value = $formulacomponent->$key;

            switch ($key) {
                // We apply each factor only once, after application we break the loop.
                case 'timeslot':
                    if (!empty($dayinfo)) {
                        self::apply_time_factor($value, $dayinfo, $price);
                    }
                    break;
                case 'customfield':
                    self::apply_customfield_factor_with_bookingoptionsettings($value, $bookingoptionsettings, $price);
                    break;
                case 'entity':
                    self::apply_entity_factor_with_bookingoptionsettings($bookingoptionsettings, $price);
                    break;
            }
        }

        /* Unit factor is not part of price formula but depends on the config setting educationalunitinminutes. */
        if (!empty($dayinfo) && get_config('booking', 'applyunitfactor')) {
            self::apply_unit_factor($dayinfo, $price);
        }

        // If setting to round prices is turned on, then round to integer.
        if (get_config('booking', 'roundpricesafterformula')) {
            $price = round($price);
        }

        return $price;
    }

    /**
     * Interprets the timepart of the jsonobject and applies the multiplier to the price, if necessary.
     *
     * @param array $timeobjects
     * @param array $dayinfo
     * @param float $price
     * @return void
     */
    private static function apply_time_factor(array $timeobjects, array $dayinfo, float &$price) {
        foreach ($timeobjects as $range) {
            if (self::is_in_time_scope($dayinfo, $range)) {
                $price = $price * $range->multiplier;
                break;
            }
        }
    }

    /**
     * Applies the unit length factor from settings to the price formula.
     *
     * Example: A booking option lasting 90 minutes will have a factor of 2,
     * if the educationalunitinminutes (config setting) ist set to 45 min.
     *
     * @param array $dayinfo
     * @param float $price
     * @return void
     */
    private static function apply_unit_factor(array $dayinfo, float &$price) {

        // Get unit length from config (should be something like 45, 50 or 60 minutes).
        if (!$unitlength = (int) get_config('booking', 'educationalunitinminutes')) {
            $unitlength = 60; // If it's not set, we use an hour as default.
        }

        sscanf($dayinfo['starttime'], "%d:%d", $hours, $minutes);
        $startminutes = $hours * 60 + $minutes;
        sscanf($dayinfo['endtime'], "%d:%d", $hours, $minutes);
        $endminutes = $hours * 60 + $minutes;

        $durationminutes = $endminutes - $startminutes;

        if ($durationminutes > 0 && !empty($unitlength)) {
            $multiplier = round($durationminutes / $unitlength, 1);
            $price = $price * $multiplier;
        }
    }

    /**
     * Interprets the customfield part of the jsonobject and applies the multiplier to the price, if necessary.
     *
     * @param array $customfieldobjects
     * @param stdClass $fromform
     * @param float $price
     * @return void
     */
    private static function apply_customfield_factor_from_form(array $customfieldobjects, stdClass $fromform, float &$price) {

        // First get all customfields from form.
        $customfields = [];
        foreach ($fromform as $formelementname => $formelementvalue) {
            if (substr($formelementname, 0, 12) === 'customfield_' && !empty($formelementvalue)) {
                if (is_array($formelementvalue)) {
                    // Could be an array.
                    foreach ($formelementvalue as $val) {
                        $customfields[substr($formelementname, 12)][] = strtolower($val);
                    }
                } else {
                    // Or a simple string which we also store in an array.
                    $customfields[substr($formelementname, 12)][] = strtolower($formelementvalue);
                }
            }
        }

        // Now get all customfields set in formula.
        foreach ($customfieldobjects as $object) {
            $key = $object->name;
            $value = strtolower($object->value);
            foreach ($customfields as $customfieldname => $customfieldvalues) {
                foreach ($customfieldvalues as $cfval) {
                    if ($key == $customfieldname && $value === strtolower($cfval)) {
                        $price = $price * $object->multiplier;
                    }
                }
            }
        }
    }

    /**
     * Interprets the entity part of the jsonobject and applies the multiplier to the price, if necessary.
     *
     * @param stdClass $fromform
     * @param float $price
     * @return void
     */
    private static function apply_entity_factor_from_form(stdClass $fromform, float &$price) {
        if (class_exists('local_entities\entitiesrelation_handler')) {
            if (!empty($fromform->local_entities_entityid)) {
                if ($entitiespricefactor = entitiesrelation_handler::get_pricefactor_by_entityid(
                    $fromform->local_entities_entityid)) {
                    $price = $price * $entitiespricefactor;
                }
            }
        }
    }

    /**
     * Interprets the customfield part of the jsonobject and applies the multiplier to the price, if necessary.
     *
     * @param array $customfieldobjects
     * @param booking_option_settings $bookingoptionsettings
     * @param float $price
     * @return void
     */
    private static function apply_customfield_factor_with_bookingoptionsettings(array $customfieldobjects,
        booking_option_settings $bookingoptionsettings, float &$price) {

        // First get all customfields from settings object.
        $customfields = [];
        foreach ($bookingoptionsettings->customfields as $fieldname => $fieldvalues) {

            // We only use the formular on customfields which are iterable.
            if (!is_array($fieldvalues)) {
                continue;
            }
            foreach ($fieldvalues as $fval) {
                if (!empty($fval)) {
                    $customfields[$fieldname][] = strtolower($fval);
                }
            }
        }

        // Now get all customfields set in formula.
        foreach ($customfieldobjects as $object) {
            $key = $object->name;
            $value = strtolower($object->value);
            foreach ($customfields as $customfieldname => $customfieldvalues) {
                foreach ($customfieldvalues as $cfval) {
                    if ($key == $customfieldname && $value === strtolower($cfval)) {
                        $price = $price * $object->multiplier;
                    }
                }
            }
        }
    }

    /**
     * Applies the entity multiplier to the price, if it exists.
     *
     * @param booking_option_settings $bookingoptionsettings
     * @param float $price
     * @return void
     */
    private static function apply_entity_factor_with_bookingoptionsettings(
        booking_option_settings $bookingoptionsettings, float &$price) {

        if (class_exists('local_entities\entitiesrelation_handler')) {
            if (!empty($bookingoptionsettings->entity)) {
                if ($entitiespricefactor = entitiesrelation_handler::get_pricefactor_by_entityid(
                    $bookingoptionsettings->entity['id'])) {
                    $price = $price * $entitiespricefactor;
                }
            }
        }
    }

    /**
     * Save from form
     *
     * @param stdClass $fromform
     *
     * @return void
     *
     */
    public function save_from_form(stdClass $fromform) {

        $currency = get_config('booking', 'globalcurrency');
        $formulastring = get_config('booking', 'defaultpriceformula');

        foreach ($this->pricecategories as $pricecategory) {
            if (!empty($fromform->priceformulaisactive) && $fromform->priceformulaisactive == "1") {
                // Price formula is active, so let's calculate the values.
                $price = self::calculate_price_from_form(
                    $fromform,
                    $formulastring,
                    $pricecategory->identifier
                );

                // Add absolute value and multiply with manual factor.
                $price *= $fromform->priceformulamultiply;
                $price += $fromform->priceformulaadd;

            } else {
                if (isset($fromform->{MOD_BOOKING_FORM_PRICEGROUP . $pricecategory->identifier})) {
                    // Price formula is not active, just save the values from form.
                    $pricegroup = $fromform->{MOD_BOOKING_FORM_PRICEGROUP . $pricecategory->identifier};
                    $price = $pricegroup[MOD_BOOKING_FORM_PRICE . $pricecategory->identifier];
                }
            }

            // If we don't want to use prices, we just set price to empty string.
            if (empty($fromform->useprice)) {
                $price = '';
            }

            self::add_price($this->area, $this->itemid, $pricecategory->identifier, $price, $currency);
        }
    }

    /**
     * Form validation
     *
     * @param array $data
     * @param array $errors
     *
     * @return void
     *
     */
    public function validation(array $data, array &$errors) {

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


    /**
     * Add or update price to DB.
     * This also deletes an entry, if the price === "".
     *
     * @param string $area
     * @param int $itemid
     * @param string $categoryidentifier
     * @param string $price
     * @param string $currency
     * @return void
     */
    public static function add_price(string $area, int $itemid, string $categoryidentifier,
        string $price, string $currency = null) {

        global $DB;

        if ($currency === null) {
            $currency = get_config('booking', 'globalcurrency');
        }

        // If we retrieve a price record for this entry, we update if necessary.
        if ($data = $DB->get_record('booking_prices', ['area' => $area, 'itemid' => $itemid,
            'pricecategoryidentifier' => $categoryidentifier,
            ])) {
            // Check if it's necessary to update.
            if ($data->price != $price
            || $data->pricecategoryidentifier != $categoryidentifier
            || $data->currency != $currency) {

                // If there is a change and the new price is "", we delete the entry.
                if ($price === "") {
                    $DB->delete_records('booking_prices', ['id' => $data->id, 'area' => $area]);
                } else {
                    $data->price = $price;
                    $data->pricecategoryidentifier = $categoryidentifier;
                    $data->currency = $currency;
                    $DB->update_record('booking_prices', $data);
                }
            }
        } else if ($price !== "") { // If there is a price but no price entry, we insert a new one.
            $data = new stdClass();
            $data->area = $area;
            $data->itemid = $itemid;
            $data->pricecategoryidentifier = $categoryidentifier;
            $data->price = $price;
            $data->currency = $currency;
            $DB->insert_record('booking_prices', $data);
        }

        // In any case, invalidate the cache after updating the booking option.
        // If performance is an issue, one could update only the cache of a this single option by key.
        // But right now, it seems reasonable to invalidate the cache from time to time.
        cache_helper::purge_by_event('setbackprices');
    }

    /**
     * Price class caches once determined prices and returns them quickly.
     * If no category identifier has been set, it will return the default price.
     *
     * @param string $area
     * @param int $itemid
     * @param object|null $user
     *
     * @return array
     */
    public static function get_price(string $area, int $itemid, $user = null): array {

        global $USER;

        if (empty($user)) {
            $user = $USER;
        }

        $categoryidentifier = singleton_service::get_pricecategory_for_user($user);

        $prices = self::get_prices_from_cache_or_db($area, $itemid);

        if (empty($prices)) {
            return [];
        }

        foreach ($prices as $pricerecord) {
            // We want to support string matching like category student for student@univie.ac.at.
            if (strpos($categoryidentifier, $pricerecord->pricecategoryidentifier) !== false) {
                return [
                    "price" => $pricerecord->price,
                    "currency" => $pricerecord->currency,
                    "pricecategoryidentifier" => $pricerecord->pricecategoryidentifier,
                    "pricecategoryname" =>
                        self::get_active_pricecategory_from_cache_or_db($pricerecord->pricecategoryidentifier)->name,
                ];
            }
        }

        return [];
    }


    /**
     * Return right user from userid.
     * If there is no userid provided, we look in shopping cart cache, there might be a userid stored.
     * If not, we use USER.
     * @param int $userid
     * @return stdClass
     */
    public static function return_user_to_buy_for(int $userid = 0) {

        global $USER;

        if ($userid === 0) {

            if (class_exists('local_shopping_cart\shopping_cart')) {
                $context = context_system::instance();
                if (has_capability('local/shopping_cart:cashier', $context)) {
                    $userid = shopping_cart::return_buy_for_userid();
                }
            }
        }
        if ($userid) {
            $user = singleton_service::get_instance_of_user($userid);
        } else {
            $user = $USER;
        }
        return $user;
    }


    /**
     * Function to determine price category for user and return shortname of category.
     * This function is optimized for speed and can be called often (as in a large table).
     *
     * @param stdClass $user
     * @return string
     */
    public static function get_pricecategory_for_user(stdClass $user) {

        global $CFG;

        // If a user profile field to story the price category identifiers for each user has been set,
        // then retrieve it from config and set the correct category identifier for the current user.
        $fieldshortname = get_config('booking', 'pricecategoryfield');

        if (!isset($user->profile) ||
            !isset($user->profile[$fieldshortname])) {

                require_once("$CFG->dirroot/user/profile/lib.php");
                profile_load_custom_fields($user);
        }

        if (!isset($user->profile[$fieldshortname])
            || empty($user->profile[$fieldshortname])) {
            $categoryidentifier = 'default'; // Default.
        } else {
            $categoryidentifier = $user->profile[$fieldshortname];
        }

        return $categoryidentifier;
    }

    /**
     * Return the cache or DB records of all prices for the option.
     *
     * @param string $area
     * @param int $itemid
     * @return array
     */
    public static function get_prices_from_cache_or_db(string $area, int $itemid):array {
        global $DB;

        $cache = \cache::make('mod_booking', 'cachedprices');
        // We need to combine area with itemid for uniqueness!
        $cachedprices = $cache->get($area . $itemid);

        // If we don't have the cache, we need to retrieve the value from db.
        if (!$cachedprices) {

            if (!$prices = $DB->get_records('booking_prices', ['area' => $area, 'itemid' => $itemid])) {
                $cache->set($area . $itemid, true);
                return [];
            }

            // Currently, we only have campaigns for booking options.
            if ($area === 'option') {
                // Check if there are active campaigns.
                // If yes, we need to apply the price factor.
                $campaigns = campaigns_info::get_all_campaigns();
                $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
                foreach ($campaigns as $camp) {
                    /** @var booking_campaign $campaign */
                    $campaign = $camp;
                    if ($campaign->campaign_is_active($itemid, $settings)) {
                        foreach ($prices as &$price) {
                            $price->price = $campaign->get_campaign_price($price->price);
                            // Render all prices to 2 fixed decimals.
                            $price->price = number_format(round((float) $price->price , 2), 2, '.', '');
                            // Campaign price factor has been applied.
                        }
                    }
                }
            }

            $data = json_encode($prices);
            $cache->set($area . $itemid, $data);
        } else if ($cachedprices === true) {
            return [];
        } else {
            $prices = json_decode($cachedprices);
        }
        return (array) $prices;
    }

    /**
     * Return price category for the category identifier from cache or DB.
     * Only active price categories will be returned.
     *
     * @param string $identifier
     * @return null|stdClass
     */
    public static function get_active_pricecategory_from_cache_or_db(string $identifier) {
        global $DB;

        if ($pricecategory = singleton_service::get_price_category($identifier)) {
            return $pricecategory;
        }

        $cache = \cache::make('mod_booking', 'cachedpricecategories');
        $cachedpricecategory = $cache->get($identifier);

        // If we don't have the cache, we need to retrieve the value from db.
        if (!$cachedpricecategory) {

            if (!$pricecategory = $DB->get_record('booking_pricecategories', ['identifier' => $identifier, 'disabled' => 0])) {
                $cache->set($identifier, true);
                return null;
            }

            $data = json_encode($pricecategory);
            $cache->set($identifier, $data);
        } else if ($cachedpricecategory === true) {
            return null;
        } else {
            $pricecategory = json_decode($cachedpricecategory);
        }

        singleton_service::set_price_category($identifier, $pricecategory);
        return (object) $pricecategory;
    }

    /**
     * Returns the list of currencies that the payment subsystem supports and therefore we can work with.
     *
     * @return array[currencycode => currencyname]
     */
    public static function get_possible_currencies(): array {
        // Fix bug with Moodle versions older than 3.11.
        $currencies = [];
        if (class_exists('core_payment\helper')) {
            $codes = \core_payment\helper::get_supported_currencies();

            foreach ($codes as $c) {
                $currencies[$c] = new lang_string($c, 'core_currencies');
            }

            uasort($currencies, function($a, $b) {
                return strcmp($a, $b);
            });
        } else {
            $currencies['EUR'] = 'Euro';
            $currencies['USD'] = 'US Dollar';
        }

        return $currencies;
    }

    /**
     * Check for weekdays & time to be in certain range
     * @param array $dayinfo
     * @param object $rangeinfo
     * @return bool
     */
    public static function is_in_time_scope(array $dayinfo, object $rangeinfo) {

        // Get the localized day name.
        $dayname = new lang_string($dayinfo['day'], 'mod_booking', null, current_language());

        // For German, we have two letter abbreviations (Mo, Di, Mi...).
        // For English, we have three letter abbrevitions (Mon, Tue, Wed,...).
        switch(current_language()) {
            case 'de':
                $wdlength = 2;
                break;
            case 'en':
            default:
                $wdlength = 3;
                break;
        }

        // Only if a weekday is specified in the range, we check for it.
        if (isset($rangeinfo->weekdays)) {
            $needle = substr($dayname, 0, $wdlength);
            $needle = strtolower($needle);
            $weekdays = strtolower($rangeinfo->weekdays);
            $haystack = explode(',', strtolower($weekdays));

            if (!in_array($needle, $haystack)) {
                return false;
            }
        }

        sscanf($dayinfo['starttime'], "%d:%d", $hours, $minutes);
        $optionstartseconds = ($hours * 60 * 60) + ($minutes * 60);
        sscanf($dayinfo['endtime'], "%d:%d", $hours, $minutes);
        $optionendseconds = $hours * 60 * 60 + $minutes * 60;

        sscanf($rangeinfo->starttime, "%d:%d", $hours, $minutes);
        $rangestartseconds = $hours * 60 * 60 + $minutes * 60;

        sscanf($rangeinfo->endtime, "%d:%d", $hours, $minutes);
        $rangeendseconds = $hours * 60 * 60 + $minutes * 60;

        if ($rangestartseconds <= $optionstartseconds
            && $rangeendseconds >= $optionendseconds) {
                // It's in the time scope!
                return true;
        }
        // It's not in the time scope.
        return false;
    }
}
