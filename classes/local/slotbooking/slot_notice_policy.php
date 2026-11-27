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
 * Minimum notice before slot start for new slot bookings.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use mod_booking\booking;
use mod_booking\singleton_service;

/**
 * Resolves and evaluates the minimum notice for booking a slot.
 *
 * A slot can only be booked if it starts at least N minutes from now. The notice is resolved
 * option -> booking instance -> site default (0 = no minimum notice) and is evaluated per slot.
 *
 * It only applies when users book for themselves - also for the new slot when they move a
 * booking. Someone who books for another user (cashier, teacher, manager) is not limited.
 * A slot the user already holds (e.g. reserved in the shopping cart) is not a new booking and
 * is never blocked, so a checkout after the payment does not lose it.
 */
class slot_notice_policy {
    /** @var string Key of the instance default (booking JSON) and of the site setting. */
    public const SETTING = 'slot_booking_notice_minutes';

    /** @var int[] Selectable notice values in minutes (booking instance and option forms). */
    public const CHOICES = [0, 30, 60, 120, 720, 1440, 2880];

    /**
     * Options for the notice select in the booking instance and option forms.
     *
     * '' = "Use default" (inherit). The site setting is a number field (minutes).
     *
     * @return array<string|int, string>
     */
    public static function get_select_options(): array {
        $options = ['' => get_string('slot_change_deadline_inherit', 'mod_booking')];
        foreach (self::CHOICES as $minutes) {
            $options[$minutes] = get_string('slot_booking_notice_' . $minutes, 'mod_booking');
        }
        return $options;
    }

    /**
     * Resolve the notice in minutes for an option: option -> instance -> site.
     *
     * NULL (option) or a missing key (instance) means "inherit".
     *
     * @param int $optionid booking option id
     * @return int minutes, 0 = no minimum notice
     */
    public static function resolve_notice_minutes(int $optionid): int {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $optionval = $settings->slotconfig->booking_notice_minutes ?? null;
        if ($optionval !== null && $optionval !== '') {
            return max(0, (int)$optionval);
        }

        $instanceval = booking::get_value_of_json_by_key((int)$settings->bookingid, self::SETTING);
        if ($instanceval !== null && $instanceval !== '') {
            return max(0, (int)$instanceval);
        }

        return max(0, (int)get_config('booking', self::SETTING));
    }

    /**
     * Whether the notice applies: only when users book for themselves.
     *
     * @param int $userid the user the slot is booked for
     * @return bool
     */
    public static function applies_to_user(int $userid): bool {
        global $USER;
        return $userid > 0 && !empty($USER->id) && $userid === (int)$USER->id;
    }

    /**
     * The earliest slot start the user may book now.
     *
     * @param int $optionid booking option id
     * @param int $userid the user the slot is booked for
     * @param int|null $now reference time (defaults to time())
     * @return int timestamp, 0 if no notice applies
     */
    public static function earliest_bookable_start(int $optionid, int $userid, ?int $now = null): int {
        if (!self::applies_to_user($userid)) {
            return 0;
        }
        $minutes = self::resolve_notice_minutes($optionid);
        if ($minutes <= 0) {
            return 0;
        }
        return ($now ?? time()) + $minutes * MINSECS;
    }

    /**
     * Whether the notice blocks booking this slot for the user.
     *
     * @param int $optionid booking option id
     * @param int $userid the user the slot is booked for
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int|null $now reference time (defaults to time())
     * @return bool
     */
    public static function blocks_slot(int $optionid, int $userid, int $slotstart, int $slotend, ?int $now = null): bool {
        $earliest = self::earliest_bookable_start($optionid, $userid, $now);
        if ($earliest <= 0 || $slotstart >= $earliest) {
            return false;
        }
        return !slot_availability::user_holds_slot($optionid, $userid, $slotstart, $slotend);
    }

    /**
     * The error message for a slot that starts too soon.
     *
     * @param int $optionid booking option id
     * @return string
     */
    public static function get_error_message(int $optionid): string {
        return get_string('slot_error_booking_notice', 'mod_booking', self::get_notice_text($optionid));
    }

    /**
     * The resolved notice as readable text, e.g. "1 day".
     *
     * @param int $optionid booking option id
     * @return string
     */
    public static function get_notice_text(int $optionid): string {
        return format_time(self::resolve_notice_minutes($optionid) * MINSECS);
    }
}
