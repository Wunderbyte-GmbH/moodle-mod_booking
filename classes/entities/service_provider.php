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

namespace mod_booking\entities;

use local_entities\local\callback\service_provider as CallbackService_provider;
use mod_booking\booking;

/**
 * entity subsystem callback implementation for local_entity.
 *
 * @package mod_booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_provider implements CallbackService_provider {

    /**
     * Callback function that returns an array of dates.
     *
     * @param array $areas
     * @return array
     */
    public static function return_array_of_entity_dates(array $areas): array {

        // We just call the right class to return the array of dates.
        $itemsarray = booking::return_array_of_entity_dates($areas);

        return $itemsarray;
    }
}
