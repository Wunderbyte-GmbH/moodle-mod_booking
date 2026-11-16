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

use mod_booking\singleton_service;

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
     * Whether the user has booked the given session of a session slot option.
     *
     * Only options with slot type "session" restrict per session. For every other option the
     * session counts as booked, so callers keep their option-level booking check.
     *
     * @param int $optionid booking option id
     * @param int $sessionid booking_optiondates id
     * @param int $userid user id
     * @return bool
     */
    public static function user_booked_session(int $optionid, int $sessionid, int $userid): bool {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if ((string)($settings->slotconfig->slot_type ?? '') !== 'session') {
            return true;
        }

        $session = $settings->sessions[$sessionid] ?? null;
        if (empty($session)) {
            return false;
        }

        $answers = singleton_service::get_instance_of_booking_answers($settings);
        foreach ($answers->get_answers() as $answer) {
            if ((int)$answer->userid !== $userid || (int)$answer->waitinglist !== MOD_BOOKING_STATUSPARAM_BOOKED) {
                continue;
            }
            if (self::answer_covers_session($answer, $session)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the slots stored in a booking answer contain the given session.
     *
     * A session slot option creates one optiondate per slot, so a slot and its session share
     * the very same start and end time.
     *
     * @param object $answer booking_answers record (needs json)
     * @param object $session booking_optiondates record (needs coursestarttime, courseendtime)
     * @return bool
     */
    private static function answer_covers_session(object $answer, object $session): bool {
        foreach (slot_availability::extract_booked_ranges_from_answer($answer) as $range) {
            if (
                $range['start'] === (int)$session->coursestarttime
                && $range['end'] === (int)$session->courseendtime
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Removes the records of sessions a user did not book from the records of a booking rule.
     *
     * Rules on the date field "optiondatestarttime" join every booking answer with every session
     * of its option. For options with slot type "session" an answer only holds some of the
     * sessions, so only the records of those sessions are kept. Records without a booking answer
     * (baid), e.g. of teachers, records without a session and records of all other options are
     * returned unchanged.
     *
     * @param array $records records with optionid, optiondateid and baid
     * @return array the records without the sessions that were not booked
     */
    public static function filter_session_records(array $records): array {
        global $DB;

        $sessions = [];
        $answerids = [];
        foreach ($records as $key => $record) {
            if (empty($record->optionid) || empty($record->optiondateid) || empty($record->baid)) {
                continue;
            }
            $settings = singleton_service::get_instance_of_booking_option_settings((int)$record->optionid);
            if ((string)($settings->slotconfig->slot_type ?? '') !== 'session') {
                continue;
            }
            $sessions[$key] = $settings->sessions[$record->optiondateid] ?? null;
            $answerids[$key] = (int)$record->baid;
        }
        if (empty($answerids)) {
            return $records;
        }

        // The slots are part of the answer the rule selected, whatever its booking status is.
        $answers = $DB->get_records_list('booking_answers', 'id', array_unique($answerids), '', 'id, json');
        foreach ($answerids as $key => $answerid) {
            if (
                empty($sessions[$key])
                || empty($answers[$answerid])
                || !self::answer_covers_session($answers[$answerid], $sessions[$key])
            ) {
                unset($records[$key]);
            }
        }
        return $records;
    }

    /**
     * Renders the number of booked slots for the slot report columns.
     *
     * @param object $answer booking_answers record (needs json)
     * @return string
     */
    public static function render_numslots(object $answer): string {
        $slotdata = self::get_slot_data($answer);
        if (empty($slotdata['slots']) || !is_array($slotdata['slots'])) {
            return '';
        }

        return (string)count($slotdata['slots']);
    }

    /**
     * Renders the start time of the first booked slot for the slot report columns,
     * falling back to the startdate of the booking answer.
     *
     * @param object $answer booking_answers record (needs json, startdate)
     * @param string $format userdate format, defaults to strftimedatetime
     * @return string
     */
    public static function render_starttime(object $answer, string $format = ''): string {
        $format = $format ?: get_string('strftimedatetime', 'langconfig');
        $slotdata = self::get_slot_data($answer);
        if (!empty($slotdata['slots']) && is_array($slotdata['slots'])) {
            $firstslot = reset($slotdata['slots']);
            if (is_array($firstslot) && !empty($firstslot['start'])) {
                return userdate((int)$firstslot['start'], $format);
            }
        }

        if (!empty($answer->startdate)) {
            return userdate((int)$answer->startdate, $format);
        }

        return '';
    }

    /**
     * Renders the end time of the last booked slot for the slot report columns,
     * falling back to the enddate of the booking answer.
     *
     * @param object $answer booking_answers record (needs json, enddate)
     * @param string $format userdate format, defaults to strftimedatetime
     * @return string
     */
    public static function render_endtime(object $answer, string $format = ''): string {
        $format = $format ?: get_string('strftimedatetime', 'langconfig');
        $slotdata = self::get_slot_data($answer);
        if (!empty($slotdata['slots']) && is_array($slotdata['slots'])) {
            $lastslot = end($slotdata['slots']);
            if (is_array($lastslot) && !empty($lastslot['end'])) {
                return userdate((int)$lastslot['end'], $format);
            }
        }

        if (!empty($answer->enddate)) {
            return userdate((int)$answer->enddate, $format);
        }

        return '';
    }

    /**
     * Renders the assigned teachers from the slot JSON for the slot report columns.
     *
     * @param object $answer booking_answers record (needs json)
     * @return string
     */
    public static function render_teachers(object $answer): string {
        $slotdata = self::get_slot_data($answer);
        if (!empty($slotdata['teachers_per_slot']) && is_array($slotdata['teachers_per_slot'])) {
            $allteacherids = [];
            foreach ($slotdata['teachers_per_slot'] as $entry) {
                if (!is_array($entry) || empty($entry['teachers']) || !is_array($entry['teachers'])) {
                    continue;
                }
                $allteacherids = array_merge($allteacherids, $entry['teachers']);
            }

            $allteacherids = array_values(array_unique(array_filter(array_map('intval', $allteacherids), function ($id) {
                return $id > 0;
            })));

            $teachers = !empty($allteacherids)
                ? (method_exists(\core\user::class, 'get_users_by_id')
                    ? \core\user::get_users_by_id($allteacherids)
                    : user_get_users_by_id($allteacherids))
                : [];
            $lines = [];

            foreach ($slotdata['teachers_per_slot'] as $entry) {
                if (!is_array($entry) || empty($entry['teachers']) || !is_array($entry['teachers'])) {
                    continue;
                }

                $teacherids = array_values(array_unique(array_filter(array_map('intval', $entry['teachers']), function ($id) {
                    return $id > 0;
                })));
                if (empty($teacherids)) {
                    continue;
                }

                $names = [];
                foreach ($teacherids as $teacherid) {
                    if (!empty($teachers[$teacherid])) {
                        $names[] = fullname($teachers[$teacherid]);
                    } else {
                        $names[] = (string)$teacherid;
                    }
                }

                $start = (int)($entry['start'] ?? 0);
                $end = (int)($entry['end'] ?? 0);

                if ($start > 0 && $end > $start) {
                    $slotlabel = userdate($start, get_string('strftimedatetime', 'langconfig'))
                        . ' - ' . userdate($end, get_string('strftimetime', 'langconfig'));
                    $lines[] = $slotlabel . ': ' . implode(', ', $names);
                } else {
                    $lines[] = implode(', ', $names);
                }
            }

            if (!empty($lines)) {
                return implode(' ; ', $lines);
            }
        }

        if (empty($slotdata['teachers']) || !is_array($slotdata['teachers'])) {
            return '';
        }

        $teacherids = array_values(array_unique(array_filter(array_map('intval', $slotdata['teachers']), function ($id) {
            return $id > 0;
        })));

        if (empty($teacherids)) {
            return '';
        }

        $teachers = method_exists(\core\user::class, 'get_users_by_id')
            ? \core\user::get_users_by_id($teacherids)
            : user_get_users_by_id($teacherids);
        if (empty($teachers)) {
            return implode(', ', $teacherids);
        }

        $names = [];
        foreach ($teacherids as $teacherid) {
            if (!empty($teachers[$teacherid])) {
                $names[] = fullname($teachers[$teacherid]);
            } else {
                $names[] = (string)$teacherid;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Renders the slot price paid from the slot JSON for the slot report columns.
     *
     * @param object $answer booking_answers record (needs json)
     * @return string
     */
    public static function render_price(object $answer): string {
        $slotdata = self::get_slot_data($answer);
        if (!isset($slotdata['price'])) {
            return '';
        }

        return (string)$slotdata['price'];
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
