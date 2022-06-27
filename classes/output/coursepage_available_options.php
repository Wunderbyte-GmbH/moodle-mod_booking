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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking;
use mod_booking\booking_utils;
use mod_booking\booking_option;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursepage_available_options implements renderable, templatable {

    /** @var string $bookinginstancename  */
    public $bookinginstancename = '';

    /** @var array */
    public $bookingoptions = [];

    /** @var null booking_utils instance*/
    public $bu = null;

    /**
     * Constructor to prepare the data for courspage booking options list
     *
     * @param \stdClass $data
     */
    public function __construct($cm) {

        global $DB, $USER, $CFG;

        $booking = new booking($cm->id);
        $this->bookinginstancename = $booking->settings->name;
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return array(
                'bookinginstancename' => $this->bookinginstancename,
                'bookingoptions' => $this->bookingoptions
        );
    }
}
