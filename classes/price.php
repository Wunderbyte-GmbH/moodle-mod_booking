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
                $mform->createElement('select', 'bookingpricecategory', get_string('pricecategory', 'mod_booking'), ['category 1', 'category 2', 'category 3']);
                $priceelement = $mform->createElement('text', 'bookingprice', get_string('bookingoptionprice', 'mod_booking'));
                $priceelement->setType('bookingprice', PARAM_INT);
        $formgroup[] =& $priceelement;
        $currencyelement = $mform->createElement('text', 'bookingpricecurrency', get_string('pricecurrency', 'mod_booking'), 'size="5"');
                $currencyelement->setType('bookingpricecurrency', PARAM_TEXT);
        $formgroup[] =& $currencyelement;

        $mform->addGroup($formgroup, 'bookingpricegroup', get_string('pricegroup', 'mod_booking'));
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

            $bookingpricecategoryid = 1; // TODO: use real category.
            $bookingpricecurrency = 'EUR'; // TODO: use real category.

            // If we retrieve a price record for this entry, we update if necessary.
            if ($data = $DB->get_record('booking_prices', ['optionid' => $fromform->optionid, 'pricecategoryid' => $bookingpricecategoryid])) {
                if ($data->price != $fromform->bookingpricegroup['bookingprice']
                || $data->pricecategoryid != $bookingpricecategoryid
                || $data->currency != $fromform->bookingpricegroup['bookingpricecurrency']) {

                    $data->price = $fromform->bookingpricegroup['bookingprice'];
                    $data->pricecategoryid = $bookingpricecategoryid;
                    $data->currency = $fromform->bookingpricegroup['bookingpricecurrency'];
                    $DB->update_record('booking_prices', $data);
                }
            } else { // If there is no price entry, we insert.
                $data = new stdClass();
                $data->optionid = $fromform->optionid;
                $data->pricecategoryid = $bookingpricecategoryid;
                $data->name = 'noname';
                $data->price = $fromform->bookingpricegroup['bookingprice'];
                $data->currency = $fromform->bookingpricegroup['bookingpricecurrency'];

                $DB->insert_record('booking_prices', $data);
            }
        }
    }

    /**
     * Price class caches once determined prices and returns them quickly by just the optionid.
     * But you can also retrieve the price for a different category than the one of the actual user.
     *
     * @param [type] $optionid
     * @return void
     */
    public static function getprice($optionid, $categoryid = null):array {
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

        // TODO: Determine category. At the moment, we just take the first price we find.

        return [$prices[0]->price, $prices[0]->currency];
    }
}
