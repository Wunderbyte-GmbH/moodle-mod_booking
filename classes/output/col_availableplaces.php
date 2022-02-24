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
 * This file contains the definition for the renderable classes for column 'action'.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking_answers;
use mod_booking\booking_option_settings;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the column 'availableplaces'.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_availableplaces implements renderable, templatable {

    /** @var booking_answers $bookinganswers instance of class */
    private $bookinganswers = null;

    /**
     * The constructor takes the values from db.
     * @param stdClass $values
     * @param booking_option_settings $settings
     */
    public function __construct($values, booking_option_settings $settings) {

        $this->bookinganswers = new booking_answers($settings);
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        // We got the array of all the booking information.
        $bookinginformation = $this->bookinganswers->return_all_booking_information();

        return $bookinginformation;
    }
}
