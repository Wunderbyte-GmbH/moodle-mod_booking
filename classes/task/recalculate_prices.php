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
 * Adhoc Task to clean caches at campaign start and at campaign end.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use cache_helper;
use mod_booking\price;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle adhoc Task to clean caches at campaign start and at campaign end.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recalculate_prices extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskrecalculateprices', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @throws \coding_exception
     * @throws \dml_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {

        $taskdata = $this->get_custom_data();

        $cmid = $taskdata->cmid;

        $price = new price('option');
        $currency = get_config('booking', 'globalcurrency');
        $formulastring = get_config('booking', 'defaultpriceformula');

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $alloptionids = \mod_booking\booking::get_all_optionids($bookingsettings->id);
        foreach ($alloptionids as $optionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

            // If priceformulaoff is set to 1, we're not doing anything!
            if (isset($settings->priceformulaoff) && $settings->priceformulaoff == 1) {
                continue;
            }

            foreach ($price->pricecategories as $pricecategory) {
                $newprice = price::calculate_price_with_bookingoptionsettings(
                    $settings,
                    $formulastring,
                    $pricecategory->identifier
                );

                price::add_price(
                    'option',
                    $optionid,
                    $pricecategory->identifier,
                    $newprice,
                    $currency
                );

                mtrace("Price calculated for option $settings->id and pricecategory $pricecategory->identifier - " .
                    "New price is: $newprice");
            }
        }
    }
}
