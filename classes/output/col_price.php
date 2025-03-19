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
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context;
use local_shopping_cart\local\entities\cartitem;
use mod_booking\booking_answers;
use mod_booking\booking_option_settings;
use mod_booking\price;
use mod_booking\singleton_service;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * This class prepares data for displaying the column 'action'.
 *
 * @package     mod_booking
 * @copyright   2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_price implements renderable, templatable {
    /** @var array $cartitem array of cartitem */
    public $cartitem = [];

    /** @var array $priceitem array of priceitem */
    public $priceitems = [];

    /** @var array $priceitem */
    public $priceitem = [];

    /**
     * $context
     *
     * @var object
     */
    private $context = null;

    /**
     * Only when the user is not booked, we store a price during construction.
     *
     * @param stdClass $values
     * @param booking_option_settings $settings
     * @param object|null $buyforuser
     * @param context|null $context
     *
     */
    public function __construct(
        stdClass $values,
        booking_option_settings $settings,
        ?object $buyforuser = null,
        ?context $context = null
    ) {

        global $USER;

        // First, we see if we deal with a guest. Guests get all prices.
        if ($context && !isloggedin()) {
            $this->context = $context;
            $this->priceitems = price::get_prices_from_cache_or_db('option', $values->id);
            // When we render for guest, we don't need the rest.
            return;
        }

        if (empty($buyforuser)) {
            $buyforuser = $USER;
        }

        // Because of the caching logic, we have to create the booking_answers object here again.
        if ($values->id) {
            $settings = singleton_service::get_instance_of_booking_option_settings($values->id);
            $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);

            // We only show the price when we can actually buy.
            // That is only possible when not booked.
            // When reserved, the item is at the moment in the cart, this shows the inactive cart.
            // When deleted, we can book again.

            switch ($bookinganswers->user_status($buyforuser->id)) {
                case MOD_BOOKING_STATUSPARAM_RESERVED:
                case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                case MOD_BOOKING_STATUSPARAM_DELETED:
                case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                    if ($this->priceitem = price::get_price('option', $values->id, $buyforuser)) {
                        $cartitem = new cartitem(
                            $values->id,
                            $settings->get_title_with_prefix(),
                            $this->priceitem['price'],
                            $this->priceitem['currency'],
                            'mod_booking',
                            $values->description
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
    public function export_for_template(renderer_base $output): array {

        $returnarray = [];

        if ($this->context && is_guest($this->context)) {
            $sortedpriceitems = [];
            foreach ($this->priceitems as $priceitem) {
                $pricecategory = price::get_active_pricecategory_from_cache_or_db($priceitem->pricecategoryidentifier);

                $priceitemarray = (array)$priceitem;
                $priceitemarray['pricecategoryname'] = $pricecategory->name;

                // Actually not yet sorted.
                $sortedpriceitems[$pricecategory->pricecatsortorder] = $priceitemarray;
            }

            // Now we sort the array according to the sort order defined in price categories.
            ksort($sortedpriceitems);
            // The mustache template cannot handle keys, so we remove them now.
            $sortedpriceitems = array_values($sortedpriceitems);

            // And add them to the returned array.
            $returnarray['priceitems'] = $sortedpriceitems;

            return $returnarray;
        } else if (!$this->cartitem) {
            return [];
        }
        return [
            'itemid' => $this->cartitem['itemid'],
            'itemname' => $this->cartitem['itemname'],
            'price' => number_format($this->cartitem['price'], 2),
            'currency' => $this->cartitem['currency'],
            'componentname' => $this->cartitem['componentname'],
            'area' => 'option',
            'description' => $this->cartitem['description'],
            'imageurl' => $this->cartitem['imageurl'],
            'priceitems' => $this->priceitem,
        ];
    }
}
