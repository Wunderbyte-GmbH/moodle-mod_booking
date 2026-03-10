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
 * Slot answer helpers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

/**
 * Helper class to read and write slot data in booking_answers.json.
 */
class slot_answer {
    /**
     * Read slot data from booking answer JSON.
     *
     * @param object $answer booking_answers record
     * @return ?array Returns the slot array if present, null otherwise
     */
    public static function get_slot_data(object $answer): ?array {
        if (empty($answer->json) || !is_string($answer->json)) {
            return null;
        }

        $payload = json_decode($answer->json, true);
        if (!is_array($payload) || !isset($payload['slot']) || !is_array($payload['slot'])) {
            return null;
        }

        return $payload['slot'];
    }

    /**
     * Merge and set slot data under top-level slot key, preserving other keys.
     *
     * @param object $answer booking_answers record (by reference via object semantics)
     * @param array $slotdata slot payload to merge
     * @return void
     */
    public static function set_slot_data(object $answer, array $slotdata): void {
        $payload = [];

        if (!empty($answer->json) && is_string($answer->json)) {
            $decoded = json_decode($answer->json, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $existingslot = [];
        if (isset($payload['slot']) && is_array($payload['slot'])) {
            $existingslot = $payload['slot'];
        }

        $payload['slot'] = array_replace_recursive($existingslot, $slotdata);
        $answer->json = json_encode($payload);
    }
}
