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
 * Slot change/cancel deadline policy.
 *
 * Single source of truth for the relative-to-slot-start deadline that gates both moving and
 * cancelling a booked slot. The deadline is a signed offset in minutes to each slot's start:
 * positive = N minutes before start, 0 = until slot start, negative = N minutes after start.
 * A slot is "actionable" (movable/cancellable) while now < slotstart - offset; otherwise it is
 * "locked" and stays booked. The deadline is resolved option -> instance -> plugin default and is
 * evaluated per individual slot (not for the earliest slot of a multi-slot booking).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use mod_booking\booking;
use mod_booking\singleton_service;
use stdClass;

/**
 * Resolves and evaluates the relative per-slot move/cancel deadline.
 */
class slot_change_policy {
    /**
     * Resolve the deadline offset (minutes, signed) for an option: option -> instance -> plugin.
     *
     * The option (booking_slot_config) and instance (booking) values are nullable; NULL means
     * "inherit". The plugin admin default is the ultimate fallback (0 = until slot start).
     *
     * @param int $optionid booking option id
     * @return int signed offset in minutes
     */
    public static function resolve_deadline_minutes(int $optionid): int {
        global $DB;

        $optionval = $DB->get_field(
            'booking_slot_config',
            'change_deadline_minutes',
            ['optionid' => $optionid],
            IGNORE_MISSING
        );
        if ($optionval !== false && $optionval !== null) {
            return (int)$optionval;
        }

        // Instance default lives in the booking instance JSON (consistent with other cancel settings).
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $instanceval = booking::get_value_of_json_by_key((int)$settings->bookingid, 'slot_change_deadline_minutes');
        if ($instanceval !== null && $instanceval !== '') {
            return (int)$instanceval;
        }

        return (int)get_config('booking', 'slot_change_deadline_minutes');
    }

    /**
     * Whether a single slot is still actionable (movable/cancellable) for a given offset.
     *
     * @param int $slotstart slot start timestamp
     * @param int $offsetminutes signed offset in minutes
     * @param int|null $now reference time (defaults to time())
     * @return bool
     */
    public static function slot_actionable(int $slotstart, int $offsetminutes, ?int $now = null): bool {
        $now = $now ?? time();
        return $now < ($slotstart - $offsetminutes * 60);
    }

    /**
     * Split the answer's booked slots into actionable and locked sets per the resolved deadline.
     *
     * @param stdClass $answer booking answer record (must carry optionid)
     * @return array{actionable: array, locked: array} slot lists (each ['start'=>int,'end'=>int,...])
     */
    public static function partition_slots(stdClass $answer): array {
        $slotdata = slot_answer::get_slot_data($answer);
        $slots = is_array($slotdata['slots'] ?? null) ? $slotdata['slots'] : [];

        $offset = self::resolve_deadline_minutes((int)$answer->optionid);
        $now = time();

        $result = ['actionable' => [], 'locked' => []];
        foreach ($slots as $slot) {
            $start = (int)($slot['start'] ?? 0);
            $bucket = self::slot_actionable($start, $offset, $now) ? 'actionable' : 'locked';
            $result[$bucket][] = $slot;
        }

        return $result;
    }

    /**
     * Whether at least one booked slot is still actionable (gates whether move/cancel is offered).
     *
     * @param stdClass $answer booking answer record
     * @return bool
     */
    public static function answer_has_actionable_slot(stdClass $answer): bool {
        return !empty(self::partition_slots($answer)['actionable']);
    }

    /**
     * Whether every booked slot is still actionable (gates a full-answer cancellation).
     *
     * Returns false for an answer with no slots.
     *
     * @param stdClass $answer booking answer record
     * @return bool
     */
    public static function answer_all_slots_actionable(stdClass $answer): bool {
        $partition = self::partition_slots($answer);
        return empty($partition['locked']) && !empty($partition['actionable']);
    }
}
