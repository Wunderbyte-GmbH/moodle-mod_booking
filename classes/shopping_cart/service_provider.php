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
 * Shopping_cart subsystem callback implementation for mod_booking.
 *
 * @package    mod_booking
 * @category   booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\shopping_cart;

use context_module;
use context_system;
use local_shopping_cart\local\entities\cartitem;
use mod_booking\booking_option_settings;
use mod_booking\price;
use stdClass;

/**
 * Shopping_cart subsystem callback implementation for mod_booking.
 *
 * @copyright  22022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_provider implements \local_shopping_cart\local\callback\service_provider {

    /**
     * Callback function that returns the costs and the accountid
     * for the course that $userid of the buying user.
     *
     * @param int $optionid
     * @return \shopping_cart\cartitem
     */
    public static function get_cartitem(int $optionid): cartitem {
        global $DB;

        $bookingoption = new booking_option_settings($optionid);

        // Make sure we don't return the identifier in the booking option name (text).
        booking_option_settings::transform_unique_bookingoption_name_to_display_name($bookingoption);

        // Make sure that we only buy from instance the user has access to.
        // This is just fraud prevention and can not happen ordinarily.
        $cm = get_coursemodule_from_instance('booking', $bookingoption->bookingid);

        // Find out if the executing user has the right to access this instance.
        $context = context_module::instance($cm->id);
        if (!has_capability('mod/quiz:view', $context)) {
            return null;
        }

        // In booking, we always buy a booking option. Therefore, we have to first find out its price.
        if (!$price = price::get_price($optionid)) {
            return null;
        }

        return new cartitem($optionid,
                            $bookingoption->text,
                            $price['price'],
                            $price['currency'],
                            'mod_booking',
                            $bookingoption->description);
    }

    /**
     * Callback function that handles inscripiton after fee was paid.
     * @param integer $itemid
     * @param integer $paymentid
     * @param integer $userid
     * @return boolean
     */
    public static function successful_checkout(int $itemid, int $paymentid, int $userid):bool {
        global $DB;

        // TODO: Set booking_answer to 1.

        return true;
    }
}
