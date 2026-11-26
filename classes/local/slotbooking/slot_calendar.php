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
 * Personal calendar events and iCal times of booked slots.
 *
 * A slot option keeps the booked times in its booking answers, not in sessions (option dates).
 * The generic calendar code creates one personal event per session, so for slot options it
 * created nothing (or, for the slot type "session", an event for every session of the option).
 * This class creates one personal ("user") event per booked slot instead.
 *
 * sync_user_events() always rebuilds all events of one user on one option from the user's active
 * booking answers. So it is correct after every kind of change (book, move, release, cancel) and
 * with several purchases of the same option. It is called by the observers of the slot events.
 * The events are linked in {booking_userevents} like the events of sessions, so the existing
 * clean-up (cancelled booking, deleted or cancelled option) removes them as well.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use mod_booking\calendar;
use mod_booking\singleton_service;
use stdClass;

/**
 * Personal calendar events and iCal times of booked slots.
 */
class slot_calendar {
    /**
     * Rebuild the personal calendar events of one user on one slot option.
     *
     * Deletes the user's events of this option and creates one event per slot the user has
     * booked, over all of the user's active booking answers. Does nothing for other options.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return void
     */
    public static function sync_user_events(int $optionid, int $userid): void {
        global $DB;

        if (empty($optionid) || empty($userid) || !self::is_slot_option($optionid)) {
            return;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Start from scratch, so moved, released and cancelled slots lose their events.
        calendar::delete_booking_userevents_for_option($optionid, $userid);

        if (get_config('booking', 'dontaddpersonalevents') == 1) {
            return;
        }

        foreach (self::get_booked_slot_times($optionid, $userid) as $time) {
            $eventid = calendar::booking_optiondate_add_to_cal(
                (int)$settings->cmid,
                $optionid,
                (object)[
                    'id' => 0,
                    'coursestarttime' => $time->coursestarttime,
                    'courseendtime' => $time->courseendtime,
                    'eventid' => 0,
                ],
                0,
                $userid
            );
            if (empty($eventid)) {
                continue;
            }
            $DB->insert_record('booking_userevents', (object)[
                'userid' => $userid,
                'optionid' => $optionid,
                // The session of a slot of type "session", so the session code finds the event too.
                'optiondateid' => $time->optiondateid,
                'eventid' => $eventid,
            ]);
        }
    }

    /**
     * The slots one user has booked on one slot option, over all of the user's active booking answers.
     *
     * Returned in the form of session records (coursestarttime, courseendtime), sorted by start, so
     * they can be used wherever sessions are used (e.g. for the iCal attachment). 'id' is unique per
     * slot and stable: the id of the session for the slot type "session", else built from the time.
     * 'optiondateid' is the id of the session, or null.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return stdClass[] list of objects with id, optiondateid, coursestarttime and courseendtime
     */
    public static function get_booked_slot_times(int $optionid, int $userid): array {
        global $DB;

        if (empty($optionid) || empty($userid) || !self::is_slot_option($optionid)) {
            return [];
        }

        // For the slot type "session", each slot is one session with the very same start and end.
        $sessionids = [];
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        foreach ($settings->sessions ?? [] as $session) {
            $sessionids[(int)$session->coursestarttime . ':' . (int)$session->courseendtime] = (int)$session->id;
        }

        // Read from the database, not from the answers cache: this runs right after the answers changed.
        $answers = $DB->get_records('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ], 'id ASC');

        $times = [];
        foreach ($answers as $answer) {
            foreach (slot_availability::extract_booked_ranges_from_answer($answer) as $range) {
                $key = $range['start'] . ':' . $range['end'];
                $optiondateid = $sessionids[$key] ?? null;
                // The same slot in two purchases is still one appointment.
                $times[$key] = (object)[
                    'id' => $optiondateid ?? 'slot-' . $range['start'] . '-' . $range['end'],
                    'optiondateid' => $optiondateid,
                    'coursestarttime' => (int)$range['start'],
                    'courseendtime' => (int)$range['end'],
                ];
            }
        }

        usort($times, fn($a, $b) => $a->coursestarttime <=> $b->coursestarttime);

        return $times;
    }

    /**
     * Whether the option is a slot booking option.
     *
     * @param int $optionid booking option id
     * @return bool
     */
    public static function is_slot_option(int $optionid): bool {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        return !empty($settings->id)
            && (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT) === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;
    }
}
