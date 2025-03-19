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

use local_entities\entitiesrelation_handler;
use mod_booking\bo_availability\conditions\subbooking;
use mod_booking\booking_option_settings;
use mod_booking\option\dates_handler;
use mod_booking\price;
use mod_booking\subbookings\sb_types\subbooking_timeslot;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * This class prepares data for displaying the column 'action'.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbooking_timeslot_output implements renderable, templatable {
    /** @var array $cartitem array of cartitem */
    public $data = [];


    /**
     * Constructur for render timeslot class.
     *
     * @param booking_option_settings $settings
     * @param bool $includebookinginformation
     * @param int $userid
     */
    public function __construct(
        booking_option_settings $settings,
        bool $includebookinginformation,
        int $userid = 0
    ) {

        $sbdata = [];

        // There might be more than one relevant subbooking to handle.
        foreach ($settings->subbookings as $subbooking) {
            // We only treat our kind of subbookings here.
            if ($subbooking->type === 'subbooking_timeslot') {
                // Get the name from the entities handler.
                $object = json_decode($subbooking->json);
                if (!$data = json_decode($object->data->slots, true)) {
                    $this->data = [];
                    return;
                }

                // We need to add the booking information to the timeslots.
                if ($includebookinginformation) {
                    $data['locations']['timeslots'] =
                        $subbooking->add_booking_information_to_slots($data['locations']['timeslots']);
                }

                $sbdata['locations'][] = [
                    'name' => $data['locations']['name'],
                    'timeslots' => $data['locations']['timeslots'],
                ];
                $sbdata['slots'] = $data['slots'];
                $sbdata['days'] = $data['days'];
            }
        }

        $this->data = $sbdata;
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
