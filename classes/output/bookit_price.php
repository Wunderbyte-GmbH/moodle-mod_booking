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
 * This file contains the definition for the renderable classes for column 'price'.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context;
use context_module;
use local_shopping_cart\local\entities\cartitem;
use mod_booking\booking_option;
use mod_booking\price;
use mod_booking\singleton_service;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the column 'action'.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookit_price implements renderable, templatable {
    /** @var array $cartitem array of cartitem */
    public array $cartitem = [];

    /** @var array $priceitems array of priceitems */
    public array $priceitems = [];

    /** @var array $priceitem a price item */
    public array $priceitem = [];

    /** @var context $context */
    private $context = null;

    /** @var bool $nojs flag for not adding js to button */
    private $nojs = false;

    /**
     * Only when the user is not booked, we store a price during construction.
     */
    /**
     * Undocumented function
     * @param array $data
     */
    public function __construct(array $data) {

        global $USER;

        $this->nojs = (!isset($data['nojs']) || !$data['nojs']) ? true : false;

        if ($data['area'] == 'option') {
            $settings = singleton_service::get_instance_of_booking_option_settings($data['itemid']);

            $context = context_module::instance($settings->cmid);
        }

        // Make sure we have a valid user.
        $userid = $data['userid'] ?? $USER->id;
        if (!$buyforuser = singleton_service::get_instance_of_user($userid)) {
            $buyforuser = $USER;
        }

        // First, we see if we deal with a guest. Guests get all prices.
        if ($context && !isloggedin()) {
            $this->context = $context;
            $this->priceitems = price::get_prices_from_cache_or_db('option', $settings->id, $userid);
            // When we render for guest, we don't need the rest.
            return;
        }

        // Because of the caching logic, we have to create the booking_answers object here again.
        if ($settings->id) {
            $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);

            // We only show the price when we can actually buy.
            // That is only possible when not booked.
            // When reserved, the item is at the moment in the cart, this shows the inactive cart.
            // When deleted, we can book again.

            $userstatus = $bookinganswers->user_status($buyforuser->id);

            switch ($userstatus) {
                case MOD_BOOKING_STATUSPARAM_RESERVED:
                case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                case MOD_BOOKING_STATUSPARAM_DELETED:
                case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                    if ($this->priceitem = price::get_price('option', $settings->id, $buyforuser)) {
                        $cartitem = new cartitem(
                            $settings->id,
                            $settings->get_title_with_prefix(),
                            $this->priceitem['price'],
                            $this->priceitem['currency'],
                            'mod_booking',
                            'option',
                            $settings->description,
                            $settings->imageurl ?? '',
                            booking_option::return_cancel_until_date($settings->id),
                            $settings->coursestarttime,
                            $settings->courseendtime
                        );

                        $this->cartitem = $cartitem->as_array();
                    }
                    break;
            }
        }
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        $returnarray = [];

        if ($this->context && is_guest($this->context)) {
            foreach ($this->priceitems as $priceitem) {
                $pricecategory = price::get_active_pricecategory_from_cache_or_db($priceitem->pricecategoryidentifier);

                $priceitemarray = (array)$priceitem;
                $priceitemarray['pricecategoryname'] = $pricecategory->name;

                $returnarray['priceitems'][] = $priceitemarray;
            }

            return $returnarray;
        } else if (!$this->cartitem) {
            return [];
        }
        $returnarray = [
            'itemid' => $this->cartitem['itemid'],
            'itemname' => $this->cartitem['itemname'],
            'price' => number_format($this->cartitem['price'], 2),
            'currency' => $this->cartitem['currency'],
            'componentname' => $this->cartitem['componentname'],
            'area' => $this->cartitem['area'],
            'description' => $this->cartitem['description'],
            'imageurl' => $this->cartitem['imageurl'],
            'priceitems' => $this->priceitem,
        ];

        if ($this->nojs) {
            $returnarray['nojs'] = 1;
        }

        return $returnarray;
    }
}
