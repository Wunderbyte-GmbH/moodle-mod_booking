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

namespace bookingextension_confirmation_supervisor\local;

use context_module;
use mod_booking\local\interfaces\bookingextension\bookforothers_interface;
use mod_booking\singleton_service;

/**
 * Class bookforothers
 *
 * @package    bookingextension_confirmation_supervisor
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookforothers implements bookforothers_interface {
    use supervisor_relation_trait;

    /**
     * Checks if the agent (eg. a supervisor) has the capability to book for the given user.
     *
     * @param int $optionid
     * @param int $agentid
     * @param int $userid
     * @return array [$allowed (bool), $message (string), $reload (bool)]
     */
    public static function has_capability_to_book_for_others(int $optionid, int $agentid, int $userid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);

        if (!has_capability('mod/booking:bookmyteam', $context, $agentid)) {
            return [false, get_string('notallowedtobookforothers', 'mod_booking'), false];
        }

        if (
            self::is_supervisor($agentid, $userid)
            || self::is_deputy($agentid, $userid)
        ) {
            return [true, '', false];
        }

        return [false, get_string('notallowedtobookforothers', 'mod_booking'), false];
    }
}
