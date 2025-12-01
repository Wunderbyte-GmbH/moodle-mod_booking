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

namespace mod_booking\filters;

use local_wunderbyte_table\filters\types\customfieldfilter;

/**
 * This class returns the available_places filter, which is a common filter of the type customfieldfilter.
 * Since it has a slightly more complex implementation, we create it here once,
 * so it can be reused in every instance of mod_booking\table\bookingoptions_wbtable.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class available_places {
    /**
     * This method returns an instance of customfieldfilter configured for available_places.
     * It can be reused in every instance of mod_booking\table\bookingoptions_wbtable.
     * @return customfieldfilter
     */
    public static function get(): customfieldfilter {
        // Booking availability filter.
        $customfieldfilter = new customfieldfilter(
            'availableplaces',
            get_string('filterbookingavailability', 'mod_booking')
        );
        $customfieldfilter->bypass_cache();
        $customfieldfilter->dont_count_keys();
        $customfieldfilter->use_operator_equal();
        // In the following query, we calculate available places (alias: availableplaces)
        // and export the booking option ID and available places for each option as availableplacestbl.
        // Then, we select the booking option IDs to pass them to the IN operator.
        $subsql = "id IN (
                SELECT id FROM (
                    SELECT sbo.id,
                    CASE WHEN
                        sbo.maxanswers=0
                        OR (sbo.maxanswers - COUNT(CASE WHEN sba.waitinglist = 0 THEN 1 END)) > 0
                    THEN '1'
                    ELSE '0' END AS availableplaces
                    FROM {booking_options} sbo
                    LEFT JOIN {booking_answers} sba ON sba.optionid = sbo.id
                    GROUP BY sbo.id, sbo.maxanswers, sba.optionid
                ) availableplacestbl
                WHERE :where
        )";

        $customfieldfilter->set_sql($subsql, "availableplaces");
        $customfieldfilter->add_options([
            '0' => get_string('filterfullybooked', 'mod_booking'),
            '1' => get_string('filteravailalbetobook', 'mod_booking'),
        ]);

        return $customfieldfilter;
    }
}
