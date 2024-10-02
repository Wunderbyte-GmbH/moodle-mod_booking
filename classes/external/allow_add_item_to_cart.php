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
 * This class contains a webservice function related to the Booking Module by Wunderbyte.
 *
 * @package    mod_booking
 * @copyright  2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_shopping_cart\shopping_cart;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External service to check if adding an item to cart is allowed.
 *
 * @package    mod_booking
 * @copyright  2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allow_add_item_to_cart extends external_api {

    /**
     * Describes the parameters for bookit.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'item id'),
            'userid' => new external_value(PARAM_INT, 'user id'),
        ]);
    }

    /**
     * Webservice.
     *
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public static function execute(int $itemid, int $userid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'itemid' => $itemid,
            'userid' => $userid,
        ]);

        // First check is if this makes sense at all. If we have no price, we return success right away.
        $settings = singleton_service::get_instance_of_booking_option_settings($params['itemid']);
        if (empty($settings->useprice)) {
            return [
                'success' => 1, /* LOCAL_SHOPPING_CART_CARTPARAM_SUCCESS needs to be hardcoded here
                    as shopping cart might not be installed! */
                'itemname' => $settings->get_title_with_prefix(),
            ];
        }

        if (class_exists('local_shopping_cart\shopping_cart')) {
            return shopping_cart::allow_add_item_to_cart('mod_booking', 'option', $params['itemid'], $params['userid']);
        } else {
            // If shopping cart is not installed, we want to continue.
            return [
                'success' => 1, /* LOCAL_SHOPPING_CART_CARTPARAM_SUCCESS needs to be hardcoded here
                    as shopping cart might not be installed! */
                'itemname' => $settings->get_title_with_prefix(),
            ];
        }
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_INT, 'See CARTPARAMs in lib.php'),
            'itemname' => new external_value(PARAM_TEXT, 'item name'),
        ]);
    }
}
