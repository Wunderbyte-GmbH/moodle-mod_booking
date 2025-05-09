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
 * The templaterule class handles the interactions with template rules.
 *
 * @package mod_booking
 * @author Magdalena Holczik
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use completion_info;
use mod_booking\booking;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class bookingoption completion
 *
 * @author Magdalena Holczik
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_completion {
    /**
     * Id of the booking option.
     *
     * @var int
     */
    public int $optionid = 0;

    /**
     * Id of the booking option.
     *
     * @var booking
     */
    public booking $booking;

    /**
     * Constructor of the class.
     *
     * @param int $optionid
     *
     */
    public function __construct(int $optionid) {
        $this->optionid = $optionid;
        $this->booking = singleton_service::get_instance_of_booking_by_optionid($optionid);
    }

    public function toggle_completion() {

    }

    public function set_status_completed() {

    }

    public function set_status_uncompleted() {

    }

    public function toggle_activity_completion() {

        $completion = new completion_info($this->booking->course);
        if ($completion->is_enabled($cm) && $booking->enablecompletion > $countcomplete) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $selecteduser);
        }
    }

}
