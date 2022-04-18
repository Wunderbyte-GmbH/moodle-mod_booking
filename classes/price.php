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

        $mform->addElement('header', 'bookingoptionprice',
                get_string('bookingoptionprice', 'booking'));

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
            if (has_capability('local/shopping_cart:cachier', $context)) {
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
}
