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
 * Who may get the files of the plugin.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use context;

/**
 * Who may get the files of the plugin.
 *
 * Every file area has an explicit audience. An area without a rule is not delivered.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_access {
    /** @var string[] Images and logos: shown on catalogue pages that work without login and on documents. */
    private const PUBLIC_AREAS = [
        'bookingoptionimage',
        'bookingimages',
        'mod_booking_signinlogo',
        'signinlogoheader',
        'signinlogofooter',
    ];

    /**
     * Whether the current user may get the files of a file area.
     *
     * @param string $filearea
     * @param context $context the context the files are stored in
     * @return bool
     */
    public static function can_access_area(string $filearea, context $context): bool {
        if (in_array($filearea, self::PUBLIC_AREAS, true)) {
            return true;
        }

        switch ($filearea) {
            case 'tickets':
                // Tickets are personal documents, the callback checks them per ticket.
                return true;
            case 'myfilemanager':
                // Attachments of the booking instance.
                return self::can_view($context);
            case 'myfilemanageroption':
                // Attachments of a booking option. A site decides whether they are public material
                // of the catalogue. A site that has not decided yet keeps delivering them.
                if (get_config('booking', 'optionattachmentspublic') !== '0') {
                    return true;
                }
                return self::can_view($context);
            default:
                return false;
        }
    }

    /**
     * Whether the current user is logged in and may view the booking instance.
     *
     * @param context $context
     * @return bool
     */
    private static function can_view(context $context): bool {
        return isloggedin() && !isguestuser() && has_capability('mod/booking:view', $context);
    }
}
