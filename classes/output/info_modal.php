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

use mod_booking\booking_utils;use mod_booking\utils\db;use renderer_base;
use renderable;
use templatable;


/**
 * This class prepares data for displaying a booking option instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class info_modal implements renderable, templatable {

    /** @var array from  */
    public $bookingoption_description = null;

    /** @var string $description from DB */
    public $body = null;

    /** @var string $statusdescription depending on booking status */
    public $statusdescription = null;

    /** @var string $location as saved in db */
    public $location = null;

    /** @var string $address as saved in db */
    public $address = null;

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
    public function __construct($booking, $bookingoption, $bookingevent = null, $descriptionparam = \DESCRIPTION_WEBSITE, $withcustomfields = false) {

        global $DB, $CFG;

        $this->bu = new booking_utils();

        // These fields can be gathered directly from DB.
        $this->title = $bookingoption->text;
        $this->location = $bookingoption->location;
        $this->address = $bookingoption->address;
        $this->institution = $bookingoption->institution;
        $this->duration = $bookingoption->duration;
        $this->description = format_text($bookingoption->description, FORMAT_HTML);

        // For these fields we do need some conversion.
        // For Description we need to know the booking status

        $this->statusdescription = $this->return_status_description($bookingoption);

        // Every date will be an array of datestring and customfields.
        // But customfields will only be shown if we show booking option information inline.

        $this->dates = $this->return_array_of_sessions($bookingoption, $bookingevent, $descriptionparam, $withcustomfields);

    }

    public function export_for_template(renderer_base $output) {
        return array(
                'title' => $this->title,
                'description' => $this->description,
                'statusdescription' => $this->statusdescription,
                'location' => $this->location,
                'address' => $this->address,
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
    private function return_array_of_sessions($bookingoption, $bookingevent = null, $descriptionparam, $withcustomfields = false) {

        global $DB;

        // If we didn't set a $bookingevent (record from booking_optiondates) we retrieve all of them for this option.
        // Else, we only use the transmitted one.
        if (!$bookingevent) {
            $sessions = $DB->get_records('booking_optiondates', array('optionid' => $bookingoption->id));
        } else {
            $sessions = [$bookingevent];
        }
        $return = [];

        if (count($sessions) > 0) {
            $customfields = $DB->get_records('booking_customfields', array('optionid' => $bookingoption->id));
            foreach ($sessions as $session) {

                // Filter the matching customfields.
                $fields = array_filter($customfields, function($x) { $x->optiondateid == $session->id; });

                if ($withcustomfields) {
                    $customfields = $this->bu->return_array_of_customfields($bookingoption, $fields, $session->id, $descriptionparam);
                } else {
                    $customfields = false;
                }

                $returnitem = [
                        'datesstring' => $this->bu->return_string_from_dates($session->coursestarttime, $session->coureendtime),
                        'costumfields' => $customfields
                ];
            }
        } else {
            $returnitem = [
                    'datesstring' => $this->bu->return_string_from_dates($bookingoption->coursestarttime, $bookingoption->coureendtime)
            ];
        }
        return $returnitem;
    }

    private function return_status_description($option) {

        global $DB, $USER;

        $sql = "
            SELECT bo.id, ba.userid, ba.status, ba.completed, ba.waitinglist
            FROM {booking_options} bo
            INNER JOIN {booking_answers} ba
            ON bo.id = ba.optionid
            WHERE ba.userid = :userid
            AND bo.id = :optionid
        ";
        $params = ['userid' => $USER->id,
                'optionid' => $option->id];

        if (!$record = $DB->get_record_sql($sql, $params)) {
            return $option->beforebookedtext;
        } else if (!$record->completed) {
            return $option->beforecompletedtext;
        } else {
            return $option->aftercompletedtext;
        }
    }

}