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
 * Header links for the option scope of the bookings tracker (report2.php).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bookingstracker;

use context_module;
use mod_booking\singleton_service;
use moodle_url;

/**
 * Header links for the option scope of the bookings tracker (report2.php).
 *
 * Kept separate from report2.php so the link building (incl. the capability
 * gating) is unit-testable.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report2_header_links {
    /**
     * Links to the slot management pages for a slot booking option.
     *
     * Mirrors the gating of the old report.php header (report.php:1058-1107):
     * the teacher unavailability page is restricted to teachers of the option,
     * site admins and users with manageslotunavailability or updatebooking;
     * the assignments page and the slot calendar are open to everyone who can
     * access the report. For non-slot options an empty array is returned.
     *
     * @param int $cmid
     * @param int $optionid
     * @return array[] each entry: ['url' => moodle_url, 'label' => string, 'iconclass' => string]
     */
    public static function slot_management_links(int $cmid, int $optionid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $isslotoption = (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT) === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;
        if (!$isslotoption) {
            return [];
        }

        $context = context_module::instance($cmid);
        $links = [];

        if (
            booking_check_if_teacher($optionid)
            || is_siteadmin()
            || has_capability('mod/booking:manageslotunavailability', $context)
            || has_capability('mod/booking:updatebooking', $context)
        ) {
            $links[] = [
                'url' => new moodle_url('/mod/booking/teacherunavailability.php', [
                    'id' => $cmid,
                    'optionid' => $optionid,
                    'scopeoptionid' => 0,
                ]),
                'label' => get_string('slot_teacher_unavailability', 'mod_booking'),
                'iconclass' => 'fa fa-calendar-times-o fa-fw',
            ];
        }

        $links[] = [
            'url' => new moodle_url('/mod/booking/slotteacherassignments.php', [
                'id' => $cmid,
                'optionid' => $optionid,
            ]),
            'label' => get_string('slot_student_teacher_assignments', 'mod_booking'),
            'iconclass' => 'fa fa-users fa-fw',
        ];

        $links[] = [
            'url' => new moodle_url('/mod/booking/slotcalendar.php', [
                'id' => $cmid,
                'optionid' => $optionid,
            ]),
            'label' => get_string('slot_calendar_title', 'mod_booking'),
            'iconclass' => 'fa fa-calendar fa-fw',
        ];

        return $links;
    }
}
