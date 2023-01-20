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

use mod_booking\booking_option_settings;
use mod_booking\price;
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
class subbooking_additionalitem_output implements renderable, templatable {

    /** @var array $cartitem array of cartitem */
    public $data = [];


    /**
     * Constructur for render timeslot class.
     *
     * @param booking_option_settings $settings
     */
    public function __construct(booking_option_settings $settings) {

        $data = [];

        // There might be more than one relevant subbooking to handle.
        foreach ($settings->subbookings as $subbooking) {

            // We only treat our kind of subbokings here.
            if ($subbooking->type !== 'subbooking_additionalitem') {
                continue;
            }

            $price = price::get_price('subbooking', $subbooking->id);

            $data['items'][] = [
                'price' => $price['price'],
                'currency' => $price['currency'],
                'name' => $subbooking->name,
                'description' => $subbooking->description,
            ];

        }

        $this->data = $data;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return $this->data;
    }
}
