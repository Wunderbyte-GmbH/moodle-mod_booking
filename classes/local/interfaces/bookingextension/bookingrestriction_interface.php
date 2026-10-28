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
 * A booking extension that can stop users from booking an option themselves.
 *
 * The extension implements it in the class \bookingextension_<name>\local\bookingrestriction. Booking asks every
 * enabled extension before a user books, and shows the reason of the first one that says no.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface bookingrestriction_interface {
    /**
     * Why the user may not book the option, or null if this extension does not stop it.
     *
     * @param int $optionid
     * @param int $userid
     * @return string|null the reason shown to the user, null if booking is not restricted
     */
    public static function get_booking_restriction(int $optionid, int $userid): ?string;
}
