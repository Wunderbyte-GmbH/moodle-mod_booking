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

use cache;
use cache_helper;
use MoodleQuickForm;
use stdClass;

/**
 * Semester class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class semester {

    /** @var int $id the semester id */
    private $id = 0;

    /** @var string $identifier a short identifier of the semester */
    private $identifier = '';

    /** @var string $name the full name of the semester */
    private $name = '';

    /** @var int $start start date as unix timestamp */
    private $start = 0;

    /** @var int $end end date as unix timestamp */
    private $end = 0;

    /**
     * Constructor for the semester class.
     *
     * @param int $id id of the semester.
     * @throws dml_exception
     */
    public function __construct(int $id) {

        $cache = cache::make('mod_booking', 'cachedsemesters');
        $cachedsemester = $cache->get($id);

        if (!$cachedsemester) {
            $cachedsemester = null;
        }

        // If we have no object to pass to set values, the function will retrieve the values from db.
        if ($data = $this->set_values($id, $cachedsemester)) {
            // Only if we didn't pass anything to cachedsemester, we set the cache now.
            if (!$cachedsemester) {
                $cache->set($id, $data);
            }
        }
    }

    /**
     * Set all the values from DB if necessary.
     * If we have passed on the cached object, we use this one.
     *
     * @param int $id the semester id
     * @return stdClass|null
     */
    private function set_values(int $id, stdClass $dbrecord = null) {
        global $DB;

        // If we don't get the cached object, we have to fetch it here.
        if (empty($dbrecord)) {
            $dbrecord = $DB->get_record("booking_semesters", array("id" => $id));
        }

        if ($dbrecord) {
            // Fields in DB.
            $this->id = $id;
            $this->identifier = $dbrecord->identifier;
            $this->name = $dbrecord->name;
            $this->start = $dbrecord->start;
            $this->end = $dbrecord->end;

            return $dbrecord;
        } else {
            debugging('Could not create semester for id: ' . $id);
            return null;
        }
    }

    /**
     * Add form fields to be passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_price_to_mform(MoodleQuickForm &$mform) {

        global $DB;

        // TODO.
        /*
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
        */
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
     * @return array
     */
    public static function get_price(int $optionid):array {

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
     * Return the cache or DB records of all prices for the option.
     *
     * @param int $optionid
     * @return array
     */
    private static function get_prices_from_cache_or_db(int $optionid):array {
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




}
