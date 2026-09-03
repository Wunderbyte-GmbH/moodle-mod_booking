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
 * Shared renderer for slot-event booking-rule placeholders.
 *
 * The slot booking events (bookinganswer_slotbooked / _slotcancelled / _slotmoved) carry slot
 * fragments in their "other" payload under different keys (bookedslots, oldslots, newslots).
 * The individual placeholders delegate here so the parsing and formatting live in one place.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use mod_booking\option\dates_handler;

/**
 * Renders a slot list from a booking rule's triggering-event payload.
 */
class slot_event_placeholders {
    /**
     * Render the first non-empty slot list found under the given event payload keys.
     *
     * @param string $rulejson the rule JSON (carries datafromevent->other)
     * @param array $keys ordered list of "other" keys to read (e.g. ['oldslots'])
     * @return string formatted "start - end; start - end" list, or '' when none
     */
    public static function render(string $rulejson, array $keys): string {
        $decoded = json_decode($rulejson);
        if (empty($decoded) || empty($decoded->datafromevent) || empty($decoded->datafromevent->other)) {
            return '';
        }

        $other = $decoded->datafromevent->other;
        $slots = [];
        foreach ($keys as $key) {
            if (!empty($other->{$key}) && is_array($other->{$key})) {
                $slots = $other->{$key};
                break;
            }
        }
        if (empty($slots)) {
            return '';
        }

        $rows = [];
        foreach ($slots as $slot) {
            $start = (int)(is_object($slot) ? ($slot->start ?? 0) : ($slot['start'] ?? 0));
            $end = (int)(is_object($slot) ? ($slot->end ?? 0) : ($slot['end'] ?? 0));
            if ($start <= 0 || $end <= $start) {
                continue;
            }
            // Same-day-collapsed range ("Wed, 24 June 2026, 10:00 AM - 11:00 AM").
            $rows[] = dates_handler::prettify_optiondates_start_end($start, $end, current_language());
        }

        return implode('; ', $rows);
    }
}
