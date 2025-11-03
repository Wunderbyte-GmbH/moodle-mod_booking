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

namespace mod_booking\local\interfaces\bookingextension;

/**
 * Interface for a single booking extension.
 *
 * All booking extensions must extend this class.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface confirmbooking_interface {
    /**
     * Function to return the name of the workflow.
     *
     * @return string
     *
     */
    public function get_name(): string;

    /**
     * Function to return a detailed description of the workflow.
     *
     * @return string
     *
     */
    public function get_description(): string;

    /**
     * Returns the number of required confirmations based on the booking option settings.
     *
     * @param int $optionid
     * @return int Number of confirmations needed (e.g., 1 or 2)
     */
    public static function get_required_confirmation_count(int $optionid): int;
}
