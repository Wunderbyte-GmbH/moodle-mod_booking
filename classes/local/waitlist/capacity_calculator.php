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
 * Free-capacity calculator (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §5, K2): free capacity
 * = maxanswers - booked - open offers. Reuses the existing, mature
 * \mod_booking\booking_answers\booking_answers class for the "booked" count (correctly weights
 * the `places` field, treats RESERVED as occupying a seat, excludes DELETED) rather than
 * re-deriving that logic here.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\booking_answers\booking_answers;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Computes an option's free capacity, counting open waitlist offers against it too.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capacity_calculator {
    /** @var waitlist_offer_repository */
    private $repository;

    /**
     * Constructs the calculator with its repository dependency.
     * @param waitlist_offer_repository $repository
     */
    public function __construct(waitlist_offer_repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Free capacity = maxanswers - booked - open offers, never negative.
     *
     * @param int $optionid
     * @return int
     */
    public function free_capacity(int $optionid): int {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings)) {
            return 0;
        }

        $answers = new booking_answers($settings);
        $booked = booking_answers::count_places($answers->get_usersonlist());
        $openoffers = count($this->repository->get_open_offers($optionid));

        return max(0, (int) $settings->maxanswers - $booked - $openoffers);
    }
}
