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
 * Provider for booking-specific AI readiness statistics.
 *
 * Discovered duck-typed by bookingextension_agent\local\wizard\aiready so the engine
 * never has a compile-time dependency on mod_booking internals.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wizard\booking;

use mod_booking\singleton_service;

/**
 * Supplies booking instance statistics to the agent readiness panel.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_readiness_provider {
    /**
     * Return option + booked-user counts for the given booking instance.
     *
     * @param int $cmid      Course-module id (unused here, kept for signature symmetry).
     * @param int $bookingid Booking instance id.
     * @return array{num_options:int,num_booked:int}
     */
    public static function get_booking_statistics(int $cmid, int $bookingid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $numoptions = 0;
        $numbooked = 0;

        try {
            $bookinginstance = singleton_service::get_instance_of_booking_by_bookingid($bookingid);
            if (!$bookinginstance) {
                return ['num_options' => 0, 'num_booked' => 0];
            }

            $numoptions = $bookinginstance->get_all_options_count();

            // One aggregate query instead of instantiating the full settings and
            // answers singletons for every single option of the instance - the
            // panel is rendered on every view.php hit and the loop scaled linearly
            // with the number of options (issue #2208). Count the distinct
            // (option, user) pairs on the confirmed list, i.e. booked or reserved:
            // the same population booking_answers::get_usersonlist() holds.
            $sql = "SELECT COUNT(*)
                      FROM (SELECT ba.optionid, ba.userid
                              FROM {booking_answers} ba
                              JOIN {booking_options} bo ON bo.id = ba.optionid
                             WHERE bo.bookingid = :bookingid
                               AND ba.waitinglist IN (:statusbooked, :statusreserved)
                          GROUP BY ba.optionid, ba.userid) bookedpairs";
            $numbooked = (int)$DB->count_records_sql($sql, [
                'bookingid' => $bookingid,
                'statusbooked' => MOD_BOOKING_STATUSPARAM_BOOKED,
                'statusreserved' => MOD_BOOKING_STATUSPARAM_RESERVED,
            ]);
        } catch (\Exception $e) {
            $numoptions = 0;
            $numbooked = 0;
        }

        return ['num_options' => $numoptions, 'num_booked' => $numbooked];
    }
}
