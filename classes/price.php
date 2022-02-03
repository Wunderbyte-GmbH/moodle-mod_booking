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

use admin_setting_configcheckbox;
use admin_setting_configtext;
use cache_helper;
use MoodleQuickForm;
use stdClass;
use lang_string;

defined('MOODLE_INTERNAL') || die();

/**
 * Price class.
 *
 * @package mod_booking
 * @copyright 2022 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price {

    /** @var array An array of all price categories. */
    private $pricecategories;

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
            } else {
                // Else we use the price category default values.
                $mform->setDefault($pricearrayidentifier, $pricecategory->defaultvalue);
            }
        }
    }


    public function save_from_form(stdClass $fromform) {
        global $DB;

        foreach ($this->pricecategories as $pricecategory) {
            if (isset($fromform->{'pricegroup_' . $pricecategory->identifier})) {

                $pricegroup = $fromform->{'pricegroup_' . $pricecategory->identifier};

                $price = $pricegroup['bookingprice_' . $pricecategory->identifier];
                $categoryidentifier = $pricecategory->identifier;
                $currency = get_config('booking', 'globalcurrency');

                // If we retrieve a price record for this entry, we update if necessary.
                if ($data = $DB->get_record('booking_prices', ['optionid' => $fromform->optionid,
                    'pricecategoryidentifier' => $categoryidentifier])) {

                    if ($data->price != $price
                    || $data->pricecategoryidentifier != $categoryidentifier
                    || $data->currency != $currency) {

                        $data->price = $price;
                        $data->pricecategoryidentifier = $categoryidentifier;
                        $data->currency = $currency;
                        $DB->update_record('booking_prices', $data);
                    }
                } else { // If there is no price entry, we insert a new one.
                    $data = new stdClass();
                    $data->optionid = $fromform->optionid;
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
        }
    }

    /**
     * Price class caches once determined prices and returns them quickly.
     * If no category identifier has been set, it will return the default price.
     *
     * @param int $optionid
     * @return void
     */
    public static function get_price(int $optionid): array {

        global $DB, $USER;

        $categoryidentifier = 'default'; // Default.

        // If a user profile field to story the price category identifiers for each user has been set,
        // then retrieve it from config and set the correct category identifier for the current user.
        $fieldid = get_config('booking', 'pricecategoryfield');
        if (!empty($fieldid)) {
            $categoryidentifier = $DB->get_field('user_info_data', 'data', ['fieldid' => $fieldid, 'userid' => $USER->id]);

            // Make sure that the identifier exists and is active.
            if (!$DB->get_record('booking_pricecategories', ['identifier' => $categoryidentifier, 'disabled' => 0])) {
                // Fallback to 'default' if identifier not found or inactive.
                $categoryidentifier = 'default';
            }
        }

        $prices = self::get_prices_from_cache_or_db($optionid);

        if (empty($prices)) {
            return null;
        }

        foreach ($prices as $pricerecord) {
            if ($pricerecord->pricecategoryidentifier == $categoryidentifier) {
                return [
                    "price" => $pricerecord->price,
                    "currency" => $pricerecord->currency,
                    "pricecategoryidentifier" => $pricerecord->pricecategoryidentifier
                ];
            }
        }

        return null;
    }

    /**
     * Return the cache or DB records of all prices for the option.
     *
     * @param int $optionid
     * @return array|null
     */
    private static function get_prices_from_cache_or_db(int $optionid): array {
        global $DB;

        $cache = \cache::make('mod_booking', 'cachedprices');
        $cachedprices = $cache->get($optionid);

        // If we don't have the cache, we need to retrieve the value from db.
        if (!$cachedprices) {

            if (!$prices = $DB->get_records('booking_prices', ['optionid' => $optionid])) {
                return null;
            }

            $data = json_encode($prices);
            $cache->set($optionid, $data);
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
     * @return stdClass
     */
    public static function get_active_pricecategory_from_cache_or_db(string $identifier): stdClass {
        global $DB;

        $cache = \cache::make('mod_booking', 'cachedpricecategories');
        $cachedpricecategory = $cache->get($identifier);

        // If we don't have the cache, we need to retrieve the value from db.
        if (!$cachedpricecategory) {

            if (!$pricecategory = $DB->get_record('booking_pricecategories', ['identifier' => $identifier, 'disabled' => 0])) {
                return null;
            }

            $data = json_encode($pricecategory);
            $cache->set($identifier, $data);
        } else {
            $pricecategory = json_decode($cachedpricecategory);
        }
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
