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
use mod_booking\booking_option_settings;
use mod_booking\dates_handler;
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
     */
    public function __construct(booking_option_settings $settings) {

        $data = [];

        $days = [];
        $slots = [];
        $locations = [];
        $slotcounter = 1;

        // There might be more than one relevant subbooking to handle.
        foreach ($settings->subbookings as $subbooking) {

            // We only treat our kind of subbokings here.
            if ($subbooking->type === 'subbooking_timeslot') {

                // Get the name from the entities handler.

                // This is to save entity relation data.
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    $erhandler = new entitiesrelation_handler('mod_booking', 'subbooking');
                    $entitiy = $erhandler->get_instance_data($subbooking->id);
                    $location = ['name' => $entitiy->name];
                } else {
                    $location = ['name' => ''];
                }

                // We need to get start & endtime for every date of this option.

                foreach ($settings->sessions as $session) {

                    $date = dates_handler::prettify_datetime($session->coursestarttime, $session->courseendtime);

                    $data['days'][] = [
                        "day" => $date->startdate,
                    ];

                    $slots = dates_handler::create_slots($session->coursestarttime,
                        $session->courseendtime,
                        $subbooking->duration);

                    foreach ($slots as $slot) {

                        if (!isset($data['slots'])) {
                            $tempslots[] = [
                                "slot" => $slot->datestring,
                            ];
                        }

                        $location['timeslots'][] = [
                            "free" => true,
                            "slot" => $slot->datestring,
                            "price" => 30,
                            "currency" => "€",
                            "area" => "subbooking-optionid",
                            "component" => "mod_booking",
                            "itemid" => $slotcounter,
                        ];
                        $slotcounter++;
                    }

                    // We only do this once.
                    if (!isset($data['slots'])) {
                        $data['slots'] = $tempslots;
                    }
                }
                $data['locations'][] = $location;
            }
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
