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

defined('MOODLE_INTERNAL') || die();

/**
 * Price class.
 *
 * @package mod_booking
 * @copyright 2022 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price {

    public function __construct() {

    }

    /**
     * Add form fields to passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @param integer $instanceid
     * @return void
     */
    public function instance_form_definition(MoodleQuickForm &$mform, int $instanceid = 0) {

        global $DB;

        // TODO: Use pricecategories in the future.
        $templates = $DB->get_records('booking_prices', ['optionid' => 0]);

        $mform->addElement('header', 'bookingoptionprice',
                get_string('bookingoptionprice', 'booking'));

        $formgroup = array();
        $formgroup[] =&
                $mform->createElement('select', 'bookingpricecategory',
                        get_string('pricecategory', 'mod_booking'), ['1' => 'students', '2' => 'external', '3' => 'internal']);
                $priceelement = $mform->createElement('text', 'bookingprice',
                        get_string('bookingoptionprice', 'mod_booking'));
                $priceelement->setType('bookingprice', PARAM_INT);
        $formgroup[] =& $priceelement;
        $currencyelement = $mform->createElement('text', 'bookingpricecurrency',
                        get_string('pricecurrency', 'mod_booking'), 'size="5"');
                $currencyelement->setType('bookingpricecurrency', PARAM_TEXT);
        $formgroup[] =& $currencyelement;

        $mform->addGroup($formgroup, 'bookingpricegroup', get_string('pricegroup', 'mod_booking'));
    }

    /**
     * Set the prices of this option to display in mform.
     *
     * @param stdClass $defaultvalues
     * @return void
     */
    public function instance_form_before_set_data(&$defaultvalues) {
        $prices = self::getpricesrecords($defaultvalues->optionid);

        // Todo: We will have more than one category in the future.

        if (isset($prices) && count($prices) > 0) {
            $price = reset($prices);

            $defaultvalues->bookingpricegroup['bookingpricecategory'] = $price->pricecategoryid;
            $defaultvalues->bookingpricegroup['bookingprice'] = $price->price;
            $defaultvalues->bookingpricegroup['bookingpricecurrency'] = $price->currency;
        }
    }

    /**
     * Add Admin settings to settings.php
     *
     * @param [type] $settings
     * @return void
     */
    public function system_form_definition(&$settings) {
        $i = 0;
        while ( ++$i < 5) {
            $j = $i + 1;
            $settings->add(
                new admin_setting_configtext("booking/bookingpricecategory_$i",
                    get_string('bookingpricecategory', 'mod_booking'),
                    get_string('bookingpricecategory_info', 'booking'), ''));

            $settings->add(
                new admin_setting_configtext("booking/bookingpricecategory_$i",
                    get_string('bookingpricecategory', 'mod_booking'),
                    get_string('bookingpricecategory_info', 'booking'), ''));

            $settings->add(
                new admin_setting_configcheckbox("booking/addcategory_$j",
                    get_string('addpricecategory', 'mod_booking'),
                    get_string('addpricecategory_info', 'booking'), 0));
        }
    }


    public function save_from_form(stdClass $fromform) {
        global $DB;

        if (isset($fromform->bookingpricegroup)) {

            $price = $fromform->bookingpricegroup['bookingprice'];
            $categoryid = $fromform->bookingpricegroup['bookingpricecategory'];
            $currency = $fromform->bookingpricegroup['bookingpricecurrency'];

            // If we retrieve a price record for this entry, we update if necessary.
            if ($data = $DB->get_record('booking_prices', ['optionid' => $fromform->optionid, 'pricecategoryid' => $categoryid])) {
                if ($data->price != $price
                || $data->pricecategoryid != $categoryid
                || $data->currency != $currency) {

                    $data->price = $price;
                    $data->pricecategoryid = $categoryid;
                    $data->currency = $currency;
                    $DB->update_record('booking_prices', $data);
                }
            } else { // If there is no price entry, we insert.
                $data = new stdClass();
                $data->optionid = $fromform->optionid;
                $data->pricecategoryid = $categoryid;
                $data->name = 'noname';
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

    /**
     * Price class caches once determined prices and returns them quickly by just the optionid.
     * But you can also retrieve the price for a different category than the one of the actual user.
     *
     * @param int $optionid
     * @param int $categoryid
     * @return void
     */
    public static function getprice(int $optionid, int $categoryid = null):array {
        global $DB;

        $prices = self::getpricesrecords($optionid);

        if (!isset($prices)) {
            return [];
        }

        $price = reset($prices);

        // TODO: Determine category. At the moment, we just take the first price we find.

        return [$price->price, $price->currency];
    }

    /**
     * Return the cache or DB records of the prices for the option.
     *
     * @param int $optionid
     * @return array|null
     */
    private static function getpricesrecords(int $optionid) {
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
        return (array)$prices;
    }
}
