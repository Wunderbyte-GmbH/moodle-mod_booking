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
 * Base class for booking subbookings information.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\subbokings;

use mod_booking\output\subbookingslist;

/**
 * Class to handle display and management of subbookings.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_subbokings {

    /** @var array $subbookings */
    public $subbookings = [];

    /**
     * Constructor of this class.
     */
    public function __construct() {

    }

    /**
     * Returns the rendered html for a list of subbookings.
     *
     * @return void
     */
    public function return_rendered_list_of_saved_subbookings($bookingid = 0) {
        global $PAGE;

        $subbookings = $this->get_list_of_saved_subbookings($bookingid);

        $data = new subbookingslist($subbookings);
        $output = $PAGE->get_renderer('booking');
        return $output->render_subbookingslist($data);
    }

    /**
     * Returns the rendered html for a list of subbookings for an instance or globally.
     *
     * @param int $bookingid
     * @return array
     */
    public function get_list_of_saved_subbookings($bookingid = 0):array {
        global $DB;

        // If the bookingid is 0, we are dealing with global subbookings.
        $params = ['bookingid' => $bookingid];

        if (!$subbookings = $DB->get_records('booking_subbookings', $params)) {
            $subbookings = [];
        }

        return $subbookings;
    }
}
