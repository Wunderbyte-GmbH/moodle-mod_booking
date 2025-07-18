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
 * The cartstore class handles the in and out of the cache.
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\mobile;

use cache;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\singleton_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class cartstore
 *
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customformstore {

    /** @var int */
    protected $userid = 0;

    /** @var int */
    protected $itemid = 0;

    /** @var object */
    protected $cache = null;

    /** @var string */
    protected $cachekey = '';

    /**
     * Cartstore constructor.
     * @param int $userid
     * @param int $itemid
     * @return void
     */
    public function __construct(int $userid, int $itemid) {
        $this->userid = $userid;
        $this->itemid = $itemid;
        $this->cache = cache::make('mod_booking', 'customformuserdata');
        $this->cachekey = $userid . "_" . $itemid . '_customform';
    }
    /**
     * Validates each submission entry.
     * @return object
     */
    public function get_customform_data() {
        return $this->cache->get($this->cachekey);
    }

    /**
     * Validates each submission entry.
     * @param object $data
     * @return void
     */
    public function set_customform_data($data) {
        $this->cache->set($this->cachekey, $data);
    }

    /**
     * Validates each submission entry.
     * @return void
     */
    public function delete_customform_data() {
        $this->cache->delete($this->cachekey);
    }

    /**
     * Server-side form validation.
     * @param object $customform
     * @param array $data
     * @return array $errors
     */
    public function validation($customform, $data): array {
        $errors = [];
        foreach ($customform as $key => $formelement) {
            $identifier = 'customform_' . $formelement->formtype . "_" . $key;
            if (
              $formelement->formtype == 'url' &&
              !self::isvalidhttpurl($data[$identifier], FILTER_VALIDATE_EMAIL)
            ) {
                $errors[$identifier] = get_string('bocondcustomformurlerror', 'mod_booking');
            } else if (
              $formelement->formtype == 'mail' &&
              !filter_var($data[$identifier], FILTER_VALIDATE_EMAIL)
            ) {
                $errors[$identifier] = get_string('bocondcustomformmailerror', 'mod_booking');
            } else if (
                $formelement->formtype == 'select'
            ) {
                $lines = explode(PHP_EOL, $formelement->value);
                foreach ($lines as $line) {
                    $linearray = explode(' => ', $line);
                    if (isset($linearray[2]) && $linearray[0] == $data[$identifier]) {
                        $settings = singleton_service::get_instance_of_booking_option_settings($data['id']);
                        $ba = singleton_service::get_instance_of_booking_answers($settings);
                        $expectedvalue = $linearray[0];
                        $filteredba = array_filter($ba->usersonlist, function($userbookings) use ($identifier, $expectedvalue) {
                            return isset($userbookings->$identifier) && $userbookings->$identifier === $expectedvalue;
                        });
                        if (count($filteredba) >= $linearray[2] && !empty($linearray[2])) {
                            $errors[$identifier] = get_string(
                                'bocondcustomformfullybooked',
                                'mod_booking',
                                $linearray[1]
                            );
                        }
                        break;
                    }
                }
            } else if (
                $formelement->formtype == 'enrolusersaction'
            ) {
                if (!(int) $data[$identifier]) {
                    $errors[$identifier] = get_string('error:chooseint', 'mod_booking');
                }
            }
            if (!empty($formelement->notempty)) {
                if (empty($data[$identifier])) {
                    $errors[$identifier] = get_string('error:mustnotbeempty', 'mod_booking');
                }
            }
        }
        return $errors;
    }

    /**
     * Validates each submission entry.
     * @param string $url
     * @return bool
     */
    public function isvalidhttpurl($url) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return preg_match('/^https?:\/\//', $url);
    }

    /**
     * Validates each submission entry.
     * @param object $customform
     * @param array $errors
     * @return object
     */
    public function translate_errors($customform, $errors) {
        foreach ($customform as $key => &$customitem) {
            $keyerroritem = 'customform_' . $customitem->formtype . '_' . $key;
            if (isset($errors[$keyerroritem])) {
                $customitem->error = $errors[$keyerroritem];
            } else {
                $customitem->error = false;
            }
        }
        return $customform;
    }

    /**
     * This will return the value a user has submitted for a given form.
     * If two forms have the same label, the first vlaue will be returned.
     *
     * @param string $key
     *
     * @return string
     *
     */
    public function return_value_for_label(string $key) {

        $settings = singleton_service::get_instance_of_booking_option_settings($this->itemid);
        $formsarray = customform::return_formelements($settings);

        if (!$data = $this->get_customform_data()) {
            return false;
        }

        if (!$element = $formsarray->{$key} ?? false) {
            return false;
        }

        $identifier = 'customform_' . $element->formtype . "_$key";

        return $data->{$identifier} ?? '';
    }

    /**
     * Modifiy price according to data in customform.
     *
     * @param float $price
     * @param string $priceidentifier
     *
     * @return string
     *
     */
    public function modify_price(float $price, string $priceidentifier): string {
        $settings = singleton_service::get_instance_of_booking_option_settings($this->itemid);
        $formdata = customform::return_formelements($settings);
        $data = (array) $this->get_customform_data(); // One of the values here indicates the right key for formdata.
        $additionalprice = 0;

        foreach ($formdata as $formdatakey => $formelement) {
            if (
                !isset($formelement->formtype) ||
                !isset($formelement->value)
            ) {
                continue;
            }
            switch ($formelement->formtype) {
                case "select":
                    $key = 'customform_select_' . $formdatakey;
                    $lines = explode(PHP_EOL, $formelement->value);
                    foreach ($lines as $line) {
                        $linearray = explode(' => ', $line);
                        if (isset($linearray[3]) && isset($data[$key]) && $data[$key] == $linearray[0]) {
                            $additionalprice = $this->get_price_for_user($linearray[3]);
                        }
                    }
                    $price += $additionalprice;
                    break;
                case "enrolusersaction":
                    $key = 'customform_enrolusersaction_' . $formdatakey;
                    if (isset($data[$key])) {
                        $factor = (int) $data[$key];
                        $price = $price * $factor;
                    }
                    break;
                default:
                    break;
            }
        }
        return number_format(round((float) $price, 2), 2, '.', '');
    }

    /**
     * Get price and currency for user.
     *
     * @param string $pricedata
     *
     * @return string
     *
     */
    public function get_price_and_currency_for_user(string $pricedata): string {

        if (empty($pricedata)) {
            return "";
        }
        if (is_numeric($pricedata)) {
            $price = (float) $pricedata;
        } else {
            $price = $this->get_price_for_user($pricedata);
        }

        return number_format($price, 2, '.', '') . ' ' . get_config('booking', 'globalcurrency');
    }

    /**
     * Get price for user.
     *
     * @param string $pricedata
     * @param string $priceidentifier
     *
     * @return float
     *
     */
    private function get_price_for_user(string $pricedata, string $priceidentifier = ""): float {

        $pairs = explode(',', $pricedata);
        $categoryprices = [];
        foreach ($pairs as $pair) {
            if (strpos($pair, ':') === false) {
                continue;
            }
            list($key, $value) = explode(':', $pair);
            $categoryprices[$key] = (float)$value;
        }

        if (empty($priceidentifier)) {
            $user = singleton_service::get_instance_of_user($this->userid);
            $priceidentifier = singleton_service::get_pricecategory_for_user($user);
        }

        if (in_array($priceidentifier, array_keys($categoryprices))) {
            return $categoryprices[$priceidentifier];
        } else if (isset($categoryprices['default'])) {
            return $categoryprices['default'];
        }
        return 0;
    }
}
