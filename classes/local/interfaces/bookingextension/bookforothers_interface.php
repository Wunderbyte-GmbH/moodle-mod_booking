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
 * Class bookforothers_interface
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface bookforothers_interface {
    /**
     * Checks if the agent has the capability to book for the user.
     * @param int $optionid
     * @param int $agentid
     * @param int $userid
     * @return array [$allowed (bool), $message (string), $reload (bool)]
     */
    public static function has_capability_to_book_for_others(int $optionid, int $agentid, int $userid): array;

    /**
     * Returns the ids of the users this agent is allowed to book for (eg. their team),
     * used to filter user pickers. Implementations not bound by a fixed team can return [].
     *
     * @param int $agentid
     * @return int[]
     */
    public static function get_my_team_user_ids(int $agentid): array;
}
