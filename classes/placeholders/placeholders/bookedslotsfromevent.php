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
 * Placeholder for booked slots from event payload.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Return booked slots from event payload as formatted text.
 */
class bookedslotsfromevent extends \mod_booking\placeholders\placeholder_base {
    /**
     * Return placeholder value.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $installmentnr
     * @param int $duedate
     * @param float $price
     * @param string $text
     * @param array $params
     * @param int $descriptionparam
     * @param string $rulejson
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        string &$text = '',
        array &$params = [],
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
        string $rulejson = ''
    ) {
        $rulejson = json_decode($rulejson);
        if (empty($rulejson) || empty($rulejson->datafromevent) || empty($rulejson->datafromevent->other)) {
            return '';
        }

        $other = $rulejson->datafromevent->other;
        $slots = $other->bookedslots ?? [];

        if (empty($slots) && !empty($other->newslots)) {
            $slots = $other->newslots;
        }

        if (empty($slots) && !empty($other->oldslots)) {
            $slots = $other->oldslots;
        }

        if (!is_array($slots)) {
            return '';
        }

        $rows = [];
        foreach ($slots as $slot) {
            if (!is_object($slot) && !is_array($slot)) {
                continue;
            }

            if (is_object($slot)) {
                $start = (int)($slot->start ?? 0);
                $end = (int)($slot->end ?? 0);
            } else {
                $start = (int)($slot['start'] ?? 0);
                $end = (int)($slot['end'] ?? 0);
            }
            if ($start <= 0 || $end <= $start) {
                continue;
            }

            $rows[] = userdate($start, get_string('strftimedatetime', 'langconfig'))
                . ' - '
                . userdate($end, get_string('strftimedatetime', 'langconfig'));
        }

        return implode('; ', $rows);
    }

    /**
     * Placeholder is always applicable.
     *
     * @return bool
     */
    public static function is_applicable(): bool {
        return true;
    }
}
