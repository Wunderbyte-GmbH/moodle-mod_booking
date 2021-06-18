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

defined('MOODLE_INTERNAL') || die();

use mod_booking\booking_option;use mod_booking\booking_utils;use mod_booking\utils\db;use renderer_base;
use renderable;
use templatable;

const BOOKINGLINKPARAM_NONE = 0;
const BOOKINGLINKPARAM_BOOK = 1;
const BOOKINGLINKPARAM_USER = 2;
const BOOKINGLINKPARAM_ICAL = 3;


/**
 * This class prepares data for displaying a booking option instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_description implements renderable, templatable {

    /** @var string $title the title (column text) as it is saved in db */
    public $title = null;

    /** @var int $modalcounter the title (column text) as it is saved in db */
    public $modalcounter = null;

    /** @var string $description from DB */
    public $description = null;

    /** @var string $statusdescription depending on booking status */
    public $statusdescription = null;

    /** @var string $location as saved in db */
    public $location = null;

    /** @var string $addresse as saved in db */
    public $addresse = null;

    /** @var string $institution as saved in db */
    public $institution = null;

    /** @var string $duration as saved in db in minutes */
    public $duration = null;

    /** @var array $dates as saved in db in minutes */
    public $dates = [];

    /**
     * @var null Bookingutilities to instantiate only once
     */
    private $bu = null;


    /**
     * In the constructur we prepare the following
     * Constructor
     *
     * @param \stdClass $data
     */
    public function __construct($booking,
            $bookingoption,
            $bookingevent = null,
            $bookinglinkparam = BOOKINGLINKPARAM_NONE,
            $withcustomfields = true) {

        global $DB, $CFG;

        $this->bu = new booking_utils();
        $bookingoption = new booking_option($booking->cm->id, $bookingoption->id);

        // These fields can be gathered directly from DB.
        $this->title = $bookingoption->option->text;
        $this->location = $bookingoption->option->location;
        $this->addresse = $bookingoption->option->address;
        $this->institution = $bookingoption->option->institution;

        // There can be more than one modal, therefor we use the id of this record
        $this->modalcounter = $bookingoption->option->id;

        
        // $this->duration = $bookingoption->option->duration;
        $this->description = format_text($bookingoption->option->description, FORMAT_HTML);

        // For these fields we do need some conversion.
        // For Description we need to know the booking status
        $this->statusdescription = $bookingoption->get_option_text();

        // Every date will be an array of datestring and customfields.
        // But customfields will only be shown if we show booking option information inline.

        $this->dates = $this->return_array_of_sessions($bookingoption, $bookingevent, $withcustomfields);

    }

    public function export_for_template(renderer_base $output) {
        return array(
                'title' => $this->title,
                'modalcounter' => $this->modalcounter,
                'description' => $this->description,
                'statusdescription' => $this->statusdescription,
                'location' => $this->location,
                'addresse' => $this->addresse,
                'institution' => $this->institution,
                'duration' => $this->duration,
                'dates' => $this->dates
        );
    }

    /**
     * Helper function for mustache template to return array with datestring and customfields
     * @param $bookingoption
     * @return array
     * @throws \dml_exception
     */
    private function  return_array_of_sessions($bookingoption, $bookingevent = null, $withcustomfields = false) {

        global $DB;

        // If we didn't set a $bookingevent (record from booking_optiondates) we retrieve all of them for this option.
        // Else, we only use the transmitted one.
        if (!$bookingevent) {
            $sessions = $bookingoption->sessions;
        } else {
            $sessions = [$bookingevent];
        }
        $return = [];

        if (count($sessions) > 0) {
            foreach ($sessions as $session) {


                // Filter the matchin customfields.
                $fields = $DB->get_records('booking_customfields', array(
                        'optionid' => $bookingoption->optionid,
                        'optiondateid' => $session->id
                ));

                if ($withcustomfields) {
                    $customfields = $this->bu->return_array_of_customfields($bookingoption, $fields, $session->id);
                } else {
                    $customfields = [];
                }

                $returnitem[] = [
                        'datestring' => $this->bu->return_string_from_dates($session->coursestarttime, $session->courseendtime),
                        'customfields' => $customfields
                ];
            }
        } else {
            $returnitem[] = [
                    'datesstring' => $this->bu->return_string_from_dates(
                            $bookingoption->option->coursestarttime,
                            $bookingoption->option->courseendtime)
            ];
        }
        return $returnitem;
    }
}