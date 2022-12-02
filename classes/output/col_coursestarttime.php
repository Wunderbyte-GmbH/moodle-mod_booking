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

use mod_booking\booking_option;
use moodle_exception;
use renderer_base;
use renderable;
use templatable;
use mod_booking\dates_handler;
use mod_booking\singleton_service;

/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_coursestarttime implements renderable, templatable {

    /** @var array $datestrings */
    public $datestrings = null;

    /**
     * Constructor.
     *
     * @param mod_booking\booking booking instance
     * @param int $optionid
     * @param int $cmid course module id of the booking instance
     * @param bool $collapsed set to true, if dates should be collapsed
     */
    public function __construct($booking=null, $optionid, $cmid = null, $collapsed = true) {

        if (empty($booking) && empty($cmid)) {
            throw new moodle_exception('Error: either booking instance or cmid have to be provided.');
        } else if (!empty($booking) && empty($cmid)) {
            $cmid = $booking->cm->id;
        }

        $this->optionid = $optionid;
        $this->datestrings = dates_handler::return_array_of_sessions_simple($optionid);

        // Show a collapse button for the dates.
        if (!empty($this->datestrings) && $collapsed == true) {
            $this->showcollapsebtn = true;
        }
    }

    public function export_for_template(renderer_base $output) {
        if (!$this->datestrings) {
            return [];
        }

        $returnarr = [
            'optionid' => $this->optionid,
            'datestrings' => $this->datestrings
        ];

        if (!empty($this->showcollapsebtn)) {
            $returnarr['showcollapsebtn'] = $this->showcollapsebtn;
        }

        return $returnarr;
    }
}
