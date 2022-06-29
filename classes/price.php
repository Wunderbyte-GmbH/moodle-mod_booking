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

namespace mod_booking;

use cache_helper;
use context_system;
use core_user;
use MoodleQuickForm;
use stdClass;
use lang_string;
use local_shopping_cart\shopping_cart;

/**
 * Price class.
 *
 * @package mod_booking
 * @copyright 2022 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price {

    /** @var array An array of all price categories. */
    public $pricecategories;

    /** @var int $optionid */
    private $optionid;

    /**
     * Constructor.
     * @param int $optionid
     */
    public function __construct(int $optionid = 0) {
        global $DB;

        $this->pricecategories = $DB->get_records('booking_pricecategories', ['disabled' => 0]);
        $this->optionid = $optionid;
    }

    /**
     * Add form fields to passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_price_to_mform(MoodleQuickForm &$mform) {

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
                get_string('bookingoptionprice', 'booking'));
        }

        // If there are no price categories yet, show an info text.
        if (empty($this->pricecategories)) {
            $mform->addElement('static', 'nopricecategoriesyet', get_string('nopricecategoriesyet', 'booking'));
        }

        $defaultexists = false;
        foreach ($this->pricecategories as $pricecategory) {
            $formgroup = array();

            $priceelement = $mform->createElement('float', 'bookingprice_' . $pricecategory->identifier);
            $formgroup[] = $priceelement;

            $currencyelement = $mform->createElement('static', 'bookingpricecurrency', '', get_config('booking', 'globalcurrency'));
            $formgroup[] = $currencyelement;

            $mform->addGroup($formgroup, 'pricegroup_' . $pricecategory->identifier, $pricecategory->name);

            // Determine the correct array identifier.
            $pricearrayidentifier = 'pricegroup_' . $pricecategory->identifier .
                '[' . 'bookingprice_' . $pricecategory->identifier . ']';

            if (!empty($this->optionid) && $existingprice = $DB->get_field('booking_prices', 'price',
                ['optionid' => $this->optionid, 'pricecategoryidentifier' => $pricecategory->identifier])) {
                // If there already are saved prices, we use them.
                $mform->setDefault($pricearrayidentifier, $existingprice);

                // If there is at least one price in DB, we don't use the defaults anymore.
                // This is to prevent unvolonteraly overriding empty price fields with default prices.
                $defaultexists = true;
            } else if (!$defaultexists) {
                // Else we use the price category default values.
                $mform->setDefault($pricearrayidentifier, $pricecategory->defaultvalue);
            }
        }

        // Only when there is an actual price formula, we do apply it.
        $priceformula = get_config('booking', 'defaultpriceformula');
        if (!empty($priceformula) && is_json($priceformula)) {

            // Then we show statically, what the formula will do.
            $formulastring = self::return_formula_as_string($priceformula);
            // TODO: show pre-calculated prices.
            $newprice = self::calculate_price($priceformula, 'default');

            // Elements to apply price formula.
            $mform->addElement('advcheckbox', 'priceformulaisactive', get_string('priceformulaisactive', 'mod_booking'),
            null, null, [0, 1]);
            $mform->setDefault('priceformulaisactive', 1);

            $formulaobj = new stdClass;
            $formulaobj->formula = $formulastring;

            $formulainfo = '<div class="alert alert-warning" role="alert">' .
                get_string('priceformulainfo', 'mod_booking', $formulaobj) . '</div>';

            $formulagroup = [];
            $formulagroup[] = $mform->createElement('static', 'priceformulainfo', '', $formulainfo);
            $mform->addGroup($formulagroup, 'priceformulagroup', '', ' ', false);
            $mform->hideIf('priceformulagroup', 'priceformulaisactive', 'noteq', 1);

            // Manual factor (multiplier).
            $mform->addElement('float', 'manualfactor', get_string('manualfactor', 'mod_booking'), null);
            $mform->setDefault('manualfactor', 1);
            $mform->addHelpButton('manualfactor', 'manualfactor', 'mod_booking');
            $mform->hideIf('manualfactor', 'priceformulaisactive', 'noteq', 1);

            // Absolute value (summand).
            $mform->addElement('float', 'absolutevalue', get_string('absolutevalue', 'mod_booking'), null);
            $mform->setDefault('absolutevalue', 0);
            $mform->addHelpButton('absolutevalue', 'absolutevalue', 'mod_booking');
            $mform->hideIf('absolutevalue', 'priceformulaisactive', 'noteq', 1);
        }
    }

    public static function calculate_price(string $priceformula, $pricecategoryidentifier, $bookingoption = null) {

        global $DB;

        // For testing.
        if (!$bookingoption) {
            $bookingoption = new stdClass();
            $bookingoption->dayofweektime = "Mo, 10:00 - 20:00";
        }

        if (!$pricecategory = $DB->get_record('booking_pricecategories', ['disabled' => 0,
            'identifier' => $pricecategoryidentifier])) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        if (!$jsonobject = json_decode($priceformula)) {
            // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
            return 0;
        }

        // We need the dayofweektime split up.
        $dayinfo = optiondates_handler::prepare_day_info($bookingoption->dayofweektime);

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
                case 'starttime':
                    // We apply the time identifier, but only once, after application we break the loop.
                    self::apply_time_factor($value, $dayinfo, $price);
                break;
                case 'customfield':
                    // We apply the time identifier, but only once, after application we break the loop.
                    self::apply_customfield_factor($value, null, $price);
                break;
                case 'mod_entities':
                    // We apply the time identifier, but only once, after application we break the loop.
                    self::apply_entities_factor($value, null, $price);
                break;
            }
        }

        return $price;
    }

    /**
     * Interprets the timepart of the jsonobject and applies the multiplier to the price, if necessary.
     *
     * @param array $timeobject
     * @param array $dayinfo
     * @param float $price
     * @return void
     */
    private static function apply_time_factor($timeobject, $dayinfo, &$price) {
        foreach ($timeobject as $range) {
            if (self::is_in_time_scope($dayinfo, $range)) {
                $price = $price * $range->multiplier;
                break;
            }
        }
    }

    /**
     * Interprets the timepart of the jsonobject and applies the multiplier to the price, if necessary.
     *
     * @param array $timeobject
     * @param array $dayinfo
     * @param float $price
     * @return void
     */
    private static function apply_customfield_factor($customfieldobject, $bookingoption, &$price) {

        // For testing.
        if (!$bookingoption) {
            $bookingoption = new stdClass();
            $bookingoption->customfield_sport = "Basketball";
        }

        foreach ($customfieldobject as $object) {
            $key = "customfield_" . $object->name;
            $value = strtolower($object->value);
            if (isset($bookingoption->$key)) {
                $fieldvalue = strtolower($bookingoption->$key);
                if ($fieldvalue == $value) {
                    $price = $price * $object->multiplier;
                    break;
                }
            }
        }
    }

    /**
     * Interprets the timepart of the jsonobject and applies the multiplier to the price, if necessary.
     *
     * @param array $timeobject
     * @param array $dayinfo
     * @param float $price
     * @return void
     */
    private static function apply_entities_factor($customfieldobject, $bookingoption, &$price) {

        // For testing.
        if (!$bookingoption) {
            $bookingoption = new stdClass();
            $bookingoption->customfield_sport = "Basketball";
        }
    }

    public function save_from_form(stdClass $fromform) {

        foreach ($this->pricecategories as $pricecategory) {
            if (isset($fromform->{'pricegroup_' . $pricecategory->identifier})) {

                $pricegroup = $fromform->{'pricegroup_' . $pricecategory->identifier};

                $price = $pricegroup['bookingprice_' . $pricecategory->identifier];
                $categoryidentifier = $pricecategory->identifier;
                $currency = get_config('booking', 'globalcurrency');

                self::add_price($fromform->optionid, $categoryidentifier, $price, $currency);
            }
        }
    }


    /**
     * Add or update price to DB.
     * This also deletes an entry, if the price === "".
     *
     * @param int $optionid
     * @param string $categoryidentifier
     * @param string $price
     * @param string $currency
     * @return void
     */
    public static function add_price($optionid, $categoryidentifier, $price, $currency = null) {
        global $DB;

        if ($currency === null) {
            $currency = get_config('booking', 'globalcurrency');
        }

        // If we retrieve a price record for this entry, we update if necessary.
        if ($data = $DB->get_record('booking_prices', ['optionid' => $optionid,
        'pricecategoryidentifier' => $categoryidentifier])) {
            // Check if it's necessary to update.
            if ($data->price != $price
            || $data->pricecategoryidentifier != $categoryidentifier
            || $data->currency != $currency) {

                // If there is a change and the new price is "", we delete the entry.
                if ($price === "") {
                    $DB->delete_records('booking_prices', ['id' => $data->id]);
                } else {
                    $data->price = $price;
                    $data->pricecategoryidentifier = $categoryidentifier;
                    $data->currency = $currency;
                    $DB->update_record('booking_prices', $data);
                }
            }
        } else if ($price !== "") { // If there is a price but no price entry, we insert a new one.
            $data = new stdClass();
            $data->optionid = $optionid;
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
     * @param int $optionid
     * @return array
     */
    public static function get_price(int $optionid, $user = null): array {

        global $USER;

        if (!$user) {
            $user = $USER;
        }

        $categoryidentifier = self::get_pricecategory_for_user($user);

        $prices = self::get_prices_from_cache_or_db($optionid);

        if (empty($prices)) {
            return [];
        }

        foreach ($prices as $pricerecord) {
            if ($pricerecord->pricecategoryidentifier == $categoryidentifier) {
                return [
                    "price" => $pricerecord->price,
                    "currency" => $pricerecord->currency,
                    "pricecategoryidentifier" => $pricerecord->pricecategoryidentifier,
                    "pricecategoryname" =>
                        self::get_active_pricecategory_from_cache_or_db($pricerecord->pricecategoryidentifier)->name
                ];
            }
        }

        return [];
    }


    /**
     * Return right user from userid.
     * If there is no userid provided, we look in shopping cart cache, there might be a userid stored.
     * If not, we use USER.
     * @param integer $userid
     * @return user
     */
    public static function return_user_to_buy_for(int $userid = 0) {

        global $USER;

        if ($userid === 0) {

            $context = context_system::instance();
            if (has_capability('local/shopping_cart:cashier', $context)) {
                $userid = shopping_cart::return_buy_for_userid();
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
     * @param user $user
     * @return void
     */
    private static function get_pricecategory_for_user($user) {

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
     * @param int $optionid
     * @return array
     */
    public static function get_prices_from_cache_or_db(int $optionid):array {
        global $DB;

        $cache = \cache::make('mod_booking', 'cachedprices');
        $cachedprices = $cache->get($optionid);

        // If we don't have the cache, we need to retrieve the value from db.
        if (!$cachedprices) {

            if (!$prices = $DB->get_records('booking_prices', ['optionid' => $optionid])) {
                $cache->set($optionid, true);
                return [];
            }

            $data = json_encode($prices);
            $cache->set($optionid, $data);
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
        $codes = \core_payment\helper::get_supported_currencies();

        $currencies = [];
        foreach ($codes as $c) {
            $currencies[$c] = new lang_string($c, 'core_currencies');
        }

        uasort($currencies, function($a, $b) {
            return strcmp($a, $b);
        });

        return $currencies;
    }

    /**
     * Translates the priceformula to a readable string.
     *
     * @param string $priceformula
     * @return void
     */
    private static function return_formula_as_string(string $priceformula) {

        $jsonobject = json_decode($priceformula);
        $returnstring = '';

        foreach ($jsonobject as $formulacomponent) {

            // For invalid JSON.
            if (is_string($formulacomponent)) {
                // We return the 0 price. This will cause the form not to validate, if we try to apply the formula.
                continue;
            }

            $key = key($formulacomponent);
            $value = $formulacomponent->$key;

            switch ($key) {
                case 'starttime':
                    $key = get_string($key, 'booking');
                    $returnstring .= $key . "<br>";
                    foreach ($value as $item) {

                        $returnstring .= "    " . $item->starttime
                                      . " - " . $item->endtime
                                      . ", " . $item->weekdays
                                      . ": times "
                                      . $item->multiplier . "<br>";
                    }
                    break;
                case 'customfield':
                case 'mod_entities':
                    $key = get_string($key, 'booking');
                default:
                    $returnstring .= $key;
                    $returnstring .= "<br>";
                    break;
            }
        }
        return $returnstring;
    }

    /**
     * Check for weekdays & time to be in certain range
     * @param array $dayinfo
     * @param object $rangeinfo
     * @return boolean
     */
    public static function is_in_time_scope(array $dayinfo, object $rangeinfo) {

        // Only if a weekday is specified in the range, we check for it.
        if (isset($rangeinfo->weekdays)) {
            $needle = substr($dayinfo['day'], 0, 2);
            $needle = strtolower($needle);
            $weekdays = strtolower($rangeinfo->weekdays);
            $haystack = explode(',', strtolower($weekdays));

            if (!in_array($needle, $haystack)) {
                return false;
            }
        }

        sscanf($dayinfo['starttime'], "%d:%d", $hours, $minutes);
        $framestartseconds = ($hours * 60 * 60) + ($minutes * 60);
        sscanf($dayinfo['endtime'], "%d:%d", $hours, $minutes);
        $frameendseconds = $hours * 60 * 60 + $minutes * 60;

        sscanf($rangeinfo->starttime, "%d:%d", $hours, $minutes);
        $startseconds = $hours * 60 * 60 + $minutes * 60;

        sscanf($rangeinfo->endtime, "%d:%d", $hours, $minutes);
        $endseconds = $hours * 60 * 60 + $minutes * 60;

        if ($framestartseconds < $startseconds
            && $frameendseconds > $startseconds) {

                return true;
        }

        return false;
    }
}
