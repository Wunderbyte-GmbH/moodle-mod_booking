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
 * Slot availability helpers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use core_text;
use mod_booking\singleton_service;

/**
 * Slot availability service class.
 */
class slot_availability {
    /** @var array<int, array<int, array<int, array{start:int,end:int}>>> */
    private static $bookedslotrangecache = [];

    /**
     * Returns configured number of required teachers per slot.
     *
     * @param int $optionid booking option id
     * @return int
     */
    public static function get_teachers_required(int $optionid): int {
        $config = self::get_slot_config($optionid);
        if (empty($config)) {
            return 0;
        }

        return max(0, (int)$config->teachers_required);
    }

        /**
         * Clear the per-request booked-slot-range cache for a given option.
         * Call this between successive bookings in the same PHP request so slot
         * availability is re-evaluated from the database each time.
         *
         * @param int $optionid booking option id (pass 0 to clear all)
         * @return void
         */
    public static function clear_request_cache(int $optionid = 0): void {
        if ($optionid > 0) {
            unset(self::$bookedslotrangecache[$optionid]);
        } else {
            self::$bookedslotrangecache = [];
        }
    }

    /**
     * Returns available teachers for a given slot.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @return array<int, array{id:int, fullname:string, initials:string}>
     */
    public static function get_available_teachers_for_slot(int $optionid, int $slotstart, int $slotend): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/user/lib.php');

        $config = self::get_slot_config($optionid);
        if (empty($config)) {
            return [];
        }

        $teacherids = self::get_available_teacher_ids($config, $slotstart, $slotend);
        if (empty($teacherids)) {
            return [];
        }

        $users = \user_get_users_by_id($teacherids);
        if (empty($users)) {
            return [];
        }

        $result = [];
        foreach ($teacherids as $teacherid) {
            if (empty($users[$teacherid])) {
                continue;
            }
            $teacher = $users[$teacherid];
            $fullname = fullname($teacher);
            $result[] = [
                'id' => (int)$teacher->id,
                'fullname' => $fullname,
                'initials' => self::to_initials($teacher->firstname ?? '', $teacher->lastname ?? ''),
            ];
        }

        return $result;
    }
    /**
     * Returns number of bookings overlapping with the given time window.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int $excludeanswerid booking answer id to ignore in overlap checks
     * @return int
     */
    public static function count_bookings(int $optionid, int $slotstart, int $slotend, int $excludeanswerid = 0): int {
        $rangesbyanswer = self::get_booked_slot_ranges_by_answer($optionid);
        if (empty($rangesbyanswer)) {
            return 0;
        }

        $count = 0;
        foreach ($rangesbyanswer as $answerid => $ranges) {
            if ($excludeanswerid > 0 && $answerid === $excludeanswerid) {
                continue;
            }

            foreach ($ranges as $range) {
                if (!self::slots_overlap($range['start'], $range['end'], $slotstart, $slotend)) {
                    continue;
                }

                $count++;
                // One answer represents one participant; count it once per checked slot.
                break;
            }
        }

        return $count;
    }

    /**
     * Returns booked slot ranges grouped by booking answer id, cached for this request.
     *
     * @param int $optionid booking option id
     * @return array<int, array<int, array{start:int,end:int}>>
     */
    private static function get_booked_slot_ranges_by_answer(int $optionid): array {
        if (isset(self::$bookedslotrangecache[$optionid])) {
            return self::$bookedslotrangecache[$optionid];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings)) {
            self::$bookedslotrangecache[$optionid] = [];
            return [];
        }

        $answersobject = singleton_service::get_instance_of_booking_answers($settings);
        $answers = $answersobject->get_answers();
        if (empty($answers)) {
            self::$bookedslotrangecache[$optionid] = [];
            return [];
        }

        $rangesbyanswer = [];
        foreach ($answers as $answer) {
            $answerid = (int)($answer->baid ?? 0);
            if ($answerid <= 0) {
                continue;
            }

            $bookingstate = (int)($answer->waitinglist ?? MOD_BOOKING_STATUSPARAM_NOTBOOKED);
            if (self::is_inactive_booking_state($bookingstate)) {
                continue;
            }

            $ranges = self::extract_booked_ranges_from_answer((object)$answer);
            if (empty($ranges)) {
                continue;
            }

            $rangesbyanswer[$answerid] = $ranges;
        }

        self::$bookedslotrangecache[$optionid] = $rangesbyanswer;
        return $rangesbyanswer;
    }

    /**
     * Extract exact booked slot ranges from booking answer JSON.
     *
     * @param object $answer booking answer row
     * @return array<int, array{start:int,end:int}>
     */
    private static function extract_booked_ranges_from_answer(object $answer): array {
        $ranges = [];
        $slotdata = slot_answer::get_slot_data($answer);

        if (!empty($slotdata['teachers_per_slot']) && is_array($slotdata['teachers_per_slot'])) {
            foreach ($slotdata['teachers_per_slot'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $start = (int)($entry['start'] ?? 0);
                $end = (int)($entry['end'] ?? 0);
                if ($start <= 0 || $end <= $start) {
                    continue;
                }

                $ranges[$start . ':' . $end] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        if (empty($ranges) && !empty($slotdata['slots']) && is_array($slotdata['slots'])) {
            foreach ($slotdata['slots'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $start = (int)($entry['start'] ?? 0);
                $end = (int)($entry['end'] ?? 0);
                if ($start <= 0 || $end <= $start) {
                    continue;
                }

                $ranges[$start . ':' . $end] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        if (empty($ranges)) {
            // Legacy fallback: no slot fragments in JSON, use stored answer bounds.
            $start = (int)($answer->startdate ?? 0);
            $end = (int)($answer->enddate ?? 0);
            if ($start > 0 && $end > $start) {
                $ranges[$start . ':' . $end] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        return array_values($ranges);
    }

    /**
     * Returns true if slot has capacity remaining and teacher constraints are met.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int $userid user id used for overlap and teacher assignment checks
     * @param int[] $selectedteachers selected teacher ids requested for this slot
     * @param int $excludeanswerid booking answer id to ignore in overlap checks
     * @return bool
     */
    public static function is_slot_available(
        int $optionid,
        int $slotstart,
        int $slotend,
        int $userid = 0,
        array $selectedteachers = [],
        int $excludeanswerid = 0
    ): bool {
        $evaluation = self::evaluate_slot_for_user(
            $optionid,
            $slotstart,
            $slotend,
            $userid,
            $selectedteachers,
            $excludeanswerid
        );

        return !empty($evaluation['bookable']);
    }

    /**
     * Central evaluator for slot bookability, with support for warnings.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int $userid user id
     * @param int[] $selectedteachers selected teacher ids for this slot
     * @param int $excludeanswerid booking answer id to ignore in overlap checks
     * @return array{bookable:bool, status:string, errormessage:string, warningmessage:string}
     */
    public static function evaluate_slot_for_user(
        int $optionid,
        int $slotstart,
        int $slotend,
        int $userid = 0,
        array $selectedteachers = [],
        int $excludeanswerid = 0
    ): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $result = [
            'bookable' => false,
            'status' => 'unavailable',
            'errormessage' => '',
            'warningmessage' => '',
        ];

        if ($slotend <= $slotstart) {
            $result['errormessage'] = get_string('slot_error_selection_required', 'mod_booking');
            return $result;
        }

        $config = self::get_slot_config($optionid);
        if (empty($config)) {
            $result['errormessage'] = get_string('slot_error_selected_unavailable', 'mod_booking');
            return $result;
        }

        $maxparticipants = max(1, (int)$config->max_participants_per_slot);
        $bookings = self::count_bookings($optionid, $slotstart, $slotend, $excludeanswerid);
        if ($bookings >= $maxparticipants) {
            $result['status'] = 'full';
            $result['errormessage'] = get_string('slot_error_selected_unavailable', 'mod_booking');
            return $result;
        }

        $availableteacherids = self::get_available_teacher_ids($config, $slotstart, $slotend, $excludeanswerid);
        $selectedteachers = array_values(array_unique(array_filter(
            array_map('intval', $selectedteachers),
            static function (int $id): bool {
                return $id > 0;
            }
        )));

        $assignedteachers = [];
        if ($userid > 0) {
            $assignedteachers = self::get_assigned_teacher_ids_for_user($optionid, $userid);
        }

        if (!empty($assignedteachers)) {
            $unavailableassigned = array_values(array_diff($assignedteachers, $availableteacherids));
            if (!empty($unavailableassigned)) {
                $result['errormessage'] = get_string('slot_error_selected_unavailable', 'mod_booking');
                return $result;
            }

            if (!empty($selectedteachers)) {
                $invalidselection = array_values(array_diff($selectedteachers, $assignedteachers));
                if (!empty($invalidselection)) {
                    $result['errormessage'] = get_string('slot_error_selected_unavailable', 'mod_booking');
                    return $result;
                }
            }
        }

        if ($userid > 0 && empty($selectedteachers)) {
            $selectedteachers = $assignedteachers;
        }

        if (!empty($selectedteachers)) {
            $unavailableassigned = array_values(array_diff($selectedteachers, $availableteacherids));
            if (!empty($unavailableassigned)) {
                $result['errormessage'] = get_string('slot_error_selected_unavailable', 'mod_booking');
                return $result;
            }
        } else if (!self::has_teacher_capacity($config, $slotstart, $slotend, $excludeanswerid)) {
            $result['errormessage'] = get_string('slot_error_selected_unavailable', 'mod_booking');
            return $result;
        }

        if ($userid > 0) {
            $handling = self::get_student_overlap_handling($optionid);
            if ($handling !== MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY) {
                $overlaps = self::get_overlapping_option_ids_for_user_slot(
                    $userid,
                    $optionid,
                    $slotstart,
                    $slotend,
                    $excludeanswerid
                );
                if (!empty($overlaps)) {
                    $optionnames = self::get_option_names($overlaps);
                    $optionlist = empty($optionnames) ? (string)count($overlaps) : implode(', ', $optionnames);

                    if ($handling === MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK) {
                        $result['status'] = 'unavailable';
                        $result['errormessage'] = get_string('nooverlapblocking', 'mod_booking', $optionlist);
                        return $result;
                    }

                    if ($handling === MOD_BOOKING_COND_OVERLAPPING_HANDLING_WARN) {
                        $result['warningmessage'] = get_string('nooverlapwarning', 'mod_booking', $optionlist);
                        $result['status'] = 'warning';
                    }
                }
            }
        }

        $result['bookable'] = true;
        if ($result['status'] !== 'warning') {
            $result['status'] = 'open';
        }

        return $result;
    }

    /**
     * Returns all virtual slots for a given option id and date range as array of [start, end].
     *
     * @param int $optionid booking option id
     * @param int $rangestart range start timestamp
     * @param int $rangeend range end timestamp
     * @return array<int, array{0:int, 1:int}>
     */
    public static function get_slots_for_range(int $optionid, int $rangestart, int $rangeend): array {
        $config = self::get_slot_config($optionid);
        if (empty($config)) {
            return [];
        }

        if ($rangeend <= $rangestart) {
            return [];
        }

        $validfrom = (int)$config->valid_from;
        $validuntil = (int)$config->valid_until;

        if ($validfrom > 0) {
            $rangestart = max($rangestart, $validfrom);
        }
        if ($validuntil > 0) {
            // Date selector stores midnight; include the full selected day for slot generation.
            $rangeend = min($rangeend, $validuntil + DAYSECS);
        }

        if ($rangeend <= $rangestart) {
            return [];
        }

        if ((string)($config->slot_type ?? 'fixed') === 'session') {
            return self::get_session_slots_for_range($optionid, $rangestart, $rangeend);
        }

        $duration = (int)$config->slot_duration_minutes * MINSECS;
        if ($duration <= 0) {
            return [];
        }

        $interval = ((string)$config->slot_type === 'rolling')
            ? ((int)$config->slot_interval_minutes * MINSECS)
            : $duration;

        if ($interval <= 0) {
            $interval = $duration;
        }

        $openingseconds = self::time_to_seconds((string)$config->opening_time);
        $closingseconds = self::time_to_seconds((string)$config->closing_time);
        if ($closingseconds <= $openingseconds) {
            return [];
        }

        $alloweddays = self::parse_days_of_week((string)$config->days_of_week);
        if (empty($alloweddays)) {
            return [];
        }

        $slots = [];
        $daycursor = strtotime('midnight', $rangestart);

        while ($daycursor < $rangeend) {
            $dayofweek = (int)date('N', $daycursor);
            if (in_array($dayofweek, $alloweddays, true)) {
                $dayopen = $daycursor + $openingseconds;
                $dayclose = $daycursor + $closingseconds;

                for ($slotstart = $dayopen; $slotstart + $duration <= $dayclose; $slotstart += $interval) {
                    $slotend = $slotstart + $duration;

                    if ($slotstart < $rangestart || $slotend > $rangeend) {
                        continue;
                    }

                    $slots[] = [$slotstart, $slotend];
                }
            }

            $daycursor += DAYSECS;
        }

        return $slots;
    }

    /**
     * Returns slots directly from option sessions (booking_optiondates).
     *
     * @param int $optionid booking option id
     * @param int $rangestart range start timestamp
     * @param int $rangeend range end timestamp
     * @return array<int, array{0:int, 1:int}>
     */
    private static function get_session_slots_for_range(int $optionid, int $rangestart, int $rangeend): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings) || empty($settings->sessions)) {
            return [];
        }

        $slots = [];
        foreach ((array)$settings->sessions as $session) {
            $slotstart = (int)($session->coursestarttime ?? 0);
            $slotend = (int)($session->courseendtime ?? 0);
            if ($slotstart <= 0 || $slotend <= $slotstart) {
                continue;
            }

            // Keep sessions that overlap with the requested range.
            if ($slotend <= $rangestart || $slotstart >= $rangeend) {
                continue;
            }

            $slots[$slotstart . ':' . $slotend] = [$slotstart, $slotend];
        }

        if (empty($slots)) {
            return [];
        }

        $slots = array_values($slots);
        usort($slots, static function (array $a, array $b): int {
            if ($a[0] !== $b[0]) {
                return $a[0] <=> $b[0];
            }
            return $a[1] <=> $b[1];
        });

        return $slots;
    }

    /**
     * Returns slots with availability status.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return array<int, array{start:int, end:int, status:string, bookings:int, capacity:int}>
     */
    public static function get_slots_with_status(int $optionid, int $userid = 0): array {
        [$rangestart, $rangeend] = self::get_default_slot_range($optionid);
        if ($rangeend <= $rangestart) {
            return [];
        }

        return self::get_slots_with_status_for_range($optionid, $rangestart, $rangeend, $userid);
    }

    /**
     * Returns slots with availability status for an explicit range.
     *
     * @param int $optionid booking option id
     * @param int $rangestart range start timestamp
     * @param int $rangeend range end timestamp
     * @param int $userid user id
     * @return array<int, array{start:int, end:int, status:string, bookings:int, capacity:int, warningmessage:string}>
     */
    public static function get_slots_with_status_for_range(
        int $optionid,
        int $rangestart,
        int $rangeend,
        int $userid = 0
    ): array {
        $config = self::get_slot_config($optionid);
        if (empty($config)) {
            return [];
        }

        $slots = self::get_slots_for_range($optionid, $rangestart, $rangeend);
        if (empty($slots)) {
            return [];
        }

        $capacity = (int)$config->max_participants_per_slot;
        if ($capacity < 1) {
            $capacity = 1;
        }

        $result = [];
        foreach ($slots as $slot) {
            [$slotstart, $slotend] = $slot;

            $bookings = self::count_bookings($optionid, $slotstart, $slotend);
            $evaluation = self::evaluate_slot_for_user($optionid, $slotstart, $slotend, $userid);
            $status = (string)($evaluation['status'] ?? 'unavailable');

            $result[] = [
                'start' => $slotstart,
                'end' => $slotend,
                'status' => $status,
                'bookings' => $bookings,
                'capacity' => $capacity,
                'warningmessage' => (string)($evaluation['warningmessage'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Get default slot range from slot config.
     *
     * @param int $optionid booking option id
     * @return array{0:int, 1:int}
     */
    private static function get_default_slot_range(int $optionid): array {
        $config = self::get_slot_config($optionid);

        $rangestart = time();
        $rangeend = strtotime('+365 days', $rangestart);

        if (!empty($config->valid_from)) {
            $rangestart = max($rangestart, (int)$config->valid_from);
        }

        if (!empty($config->valid_until)) {
            $rangeend = (int)$config->valid_until + DAYSECS;
        }

        if ($rangeend <= $rangestart) {
            $rangeend = strtotime('+365 days', $rangestart);
        }

        return [$rangestart, $rangeend];
    }

    /**
     * Load slot configuration for an option.
     *
     * @param int $optionid booking option id
     * @return ?\stdClass
     */
    private static function get_slot_config(int $optionid): ?\stdClass {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings) || empty($settings->slotconfig)) {
            return null;
        }

        return $settings->slotconfig;
    }

    /**
     * Parse HH:MM into seconds from midnight.
     *
     * @param string $time
     * @return int
     */
    private static function time_to_seconds(string $time): int {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return 0;
        }

        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return 0;
        }

        return ($hours * HOURSECS) + ($minutes * MINSECS);
    }

    /**
     * Parse CSV day list (1..7) into integer array.
     *
     * @param string $dayscsv
     * @return int[]
     */
    private static function parse_days_of_week(string $dayscsv): array {
        $parts = array_filter(array_map('trim', explode(',', $dayscsv)), static function (string $value): bool {
            return $value !== '';
        });

        $days = [];
        foreach ($parts as $part) {
            $day = (int)$part;
            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * Check if teacher availability satisfies teachers_required for a slot.
     *
     * @param \stdClass $config slot config record
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int $excludeanswerid booking answer id to ignore in overlap checks
     * @return bool
     */
    private static function has_teacher_capacity(\stdClass $config, int $slotstart, int $slotend, int $excludeanswerid = 0): bool {
        $required = (int)$config->teachers_required;
        if ($required <= 0) {
            return true;
        }

        $availableids = self::get_available_teacher_ids($config, $slotstart, $slotend, $excludeanswerid);

        return count($availableids) >= $required;
    }

    /**
     * Return available teacher ids in deterministic order.
     *
     * @param \stdClass $config slot config record
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int $excludeanswerid booking answer id to ignore in overlap checks
     * @return int[]
     */
    private static function get_available_teacher_ids(
        \stdClass $config,
        int $slotstart,
        int $slotend,
        int $excludeanswerid = 0
    ): array {
        global $DB;

        $teacherids = self::extract_teacher_pool_ids($config);

        if (empty($teacherids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'teacher');
        $params += [
            'optionid' => (int)$config->optionid,
            'slotstart' => $slotstart,
            'slotend' => $slotend,
        ];

        $sql = "SELECT DISTINCT teacherid
                  FROM {booking_teacher_unavailability}
                 WHERE (optionid = :optionid OR optionid = 0)
                   AND teacherid $insql
                   AND unavailable_from < :slotend
                   AND unavailable_until > :slotstart";

        $unavailable = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        $busy = self::get_busy_teacher_ids($teacherids, $slotstart, $slotend, $excludeanswerid);
        $blocked = array_values(array_unique(array_merge($unavailable, $busy)));
        $availableids = array_values(array_diff($teacherids, $blocked));

        return $availableids;
    }

    /**
     * Extract teacher pool ids from slot config.
     *
     * @param \stdClass $config
     * @return int[]
     */
    private static function extract_teacher_pool_ids(\stdClass $config): array {
        $pool = json_decode((string)($config->teacher_pool ?? '[]'), true);
        if (!is_array($pool)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $pool), static function (int $id): bool {
            return $id > 0;
        })));
    }

    /**
     * Return assigned teacher ids for a user on a specific slot option.
     *
     * @param int $optionid
     * @param int $userid
     * @return int[]
     */
    private static function get_assigned_teacher_ids_for_user(int $optionid, int $userid): array {
        global $DB;

        if ($optionid <= 0 || $userid <= 0) {
            return [];
        }

        $records = $DB->get_records('booking_slot_student_teacher', [
            'optionid' => $optionid,
            'userid' => $userid,
        ], '', 'teacherid');

        if (empty($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static function ($record): int {
            return (int)($record->teacherid ?? 0);
        }, $records), static function (int $id): bool {
            return $id > 0;
        })));
    }

    /**
     * Return teacher ids that are busy in overlapping slot bookings.
     *
     * @param int[] $teacherids
     * @param int $slotstart
     * @param int $slotend
     * @param int $excludeanswerid
     * @return int[]
     */
    private static function get_busy_teacher_ids(array $teacherids, int $slotstart, int $slotend, int $excludeanswerid = 0): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if (empty($teacherids)) {
            return [];
        }

            $inactivestates = self::get_inactive_booking_states();
            [$notinsql, $notinparams] = $DB->get_in_or_equal($inactivestates, SQL_PARAMS_NAMED, 'inactive', false);

            $sql = "SELECT id, json, startdate, enddate
                  FROM {booking_answers}
                 WHERE startdate < :slotend
                   AND enddate > :slotstart
                   AND waitinglist {$notinsql}";
        $params = [
            'slotend' => $slotend,
            'slotstart' => $slotstart,
        ];
            $params = array_merge($params, $notinparams);

        if ($excludeanswerid > 0) {
            $sql .= ' AND id <> :excludeanswerid';
            $params['excludeanswerid'] = $excludeanswerid;
        }

        $answers = $DB->get_records_sql($sql, $params);
        if (empty($answers)) {
            return [];
        }

        $teacherlookup = array_fill_keys($teacherids, true);
        $busy = [];

        foreach ($answers as $answer) {
            $slotdata = slot_answer::get_slot_data((object)$answer);
            if (empty($slotdata) || !is_array($slotdata)) {
                continue;
            }

            if (!empty($slotdata['teachers_per_slot']) && is_array($slotdata['teachers_per_slot'])) {
                foreach ($slotdata['teachers_per_slot'] as $item) {
                    $itemstart = (int)($item['start'] ?? 0);
                    $itemend = (int)($item['end'] ?? 0);
                    if (!self::slots_overlap($itemstart, $itemend, $slotstart, $slotend)) {
                        continue;
                    }

                    $itemteachers = is_array($item['teachers'] ?? null) ? $item['teachers'] : [];
                    foreach ($itemteachers as $teacherid) {
                        $teacherid = (int)$teacherid;
                        if (!empty($teacherlookup[$teacherid])) {
                            $busy[$teacherid] = true;
                        }
                    }
                }
                continue;
            }

            if (!self::slots_overlap((int)($answer->startdate ?? 0), (int)($answer->enddate ?? 0), $slotstart, $slotend)) {
                continue;
            }

            $fallbackteachers = is_array($slotdata['teachers'] ?? null) ? $slotdata['teachers'] : [];
            foreach ($fallbackteachers as $teacherid) {
                $teacherid = (int)$teacherid;
                if (!empty($teacherlookup[$teacherid])) {
                    $busy[$teacherid] = true;
                }
            }
        }

        return array_values(array_map('intval', array_keys($busy)));
    }

    /**
     * Return no-overlapping handling configured on the option.
     *
     * @param int $optionid
     * @return int
     */
    private static function get_student_overlap_handling(int $optionid): int {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->availability)) {
            return MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY;
        }

        $availability = json_decode((string)$settings->availability);
        if (!is_array($availability)) {
            return MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY;
        }

        foreach ($availability as $item) {
            if (empty($item) || !is_object($item)) {
                continue;
            }
            if (!empty($item->nooverlapping)) {
                return (int)($item->nooverlappinghandling ?? MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY);
            }
        }

        return MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY;
    }

    /**
     * Return overlapping option ids for a user and slot range.
     *
     * @param int $userid
     * @param int $optionid
     * @param int $slotstart
     * @param int $slotend
     * @param int $excludeanswerid
     * @return int[]
     */
    private static function get_overlapping_option_ids_for_user_slot(
        int $userid,
        int $optionid,
        int $slotstart,
        int $slotend,
        int $excludeanswerid = 0
    ): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($userid <= 0) {
            return [];
        }

            $inactivestates = self::get_inactive_booking_states();
            [$notinsql, $notinparams] = $DB->get_in_or_equal($inactivestates, SQL_PARAMS_NAMED, 'inactive', false);

            $sql = "SELECT id, optionid
                  FROM {booking_answers}
                 WHERE userid = :userid
                   AND optionid <> :optionid
                   AND startdate < :slotend
                   AND enddate > :slotstart
                   AND waitinglist {$notinsql}";
        $params = [
            'userid' => $userid,
            'optionid' => $optionid,
            'slotend' => $slotend,
            'slotstart' => $slotstart,
        ];
            $params = array_merge($params, $notinparams);

        if ($excludeanswerid > 0) {
            $sql .= ' AND id <> :excludeanswerid';
            $params['excludeanswerid'] = $excludeanswerid;
        }

        $records = $DB->get_records_sql($sql, $params);
        if (empty($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static function ($record): int {
            return (int)($record->optionid ?? 0);
        }, $records), static function (int $id): bool {
            return $id > 0;
        })));
    }

    /**
     * Return option names for message rendering.
     *
     * @param int[] $optionids
     * @return string[]
     */
    private static function get_option_names(array $optionids): array {
        global $DB;

        if (empty($optionids)) {
            return [];
        }

        $records = $DB->get_records_list('booking_options', 'id', $optionids, '', 'id, text');
        if (empty($records)) {
            return [];
        }

        $names = [];
        foreach ($optionids as $optionid) {
            if (!empty($records[$optionid])) {
                $names[] = format_string((string)$records[$optionid]->text);
            }
        }

        return $names;
    }

    /**
     * Return booking states that must not consume slot capacity.
     *
     * @return int[]
     */
    private static function get_inactive_booking_states(): array {
        return [
            MOD_BOOKING_STATUSPARAM_NOTBOOKED,
            MOD_BOOKING_STATUSPARAM_DELETED,
            MOD_BOOKING_STATUSPARAM_BOOKED_DELETED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST_DELETED,
            MOD_BOOKING_STATUSPARAM_RESERVED_DELETED,
            MOD_BOOKING_STATUSPARAM_NOTIFYMELIST_DELETED,
            MOD_BOOKING_STATUSPARAM_CONFIRMATION_DELETED,
        ];
    }

    /**
     * Check if a booking state should be ignored for slot occupancy logic.
     *
     * @param int $bookingstate
     * @return bool
     */
    private static function is_inactive_booking_state(int $bookingstate): bool {
        static $lookup = null;

        if ($lookup === null) {
            $lookup = array_fill_keys(self::get_inactive_booking_states(), true);
        }

        return !empty($lookup[$bookingstate]);
    }

    /**
     * Check if two slot ranges overlap.
     *
     * @param int $starta
     * @param int $enda
     * @param int $startb
     * @param int $endb
     * @return bool
     */
    private static function slots_overlap(int $starta, int $enda, int $startb, int $endb): bool {
        if ($starta <= 0 || $enda <= $starta || $startb <= 0 || $endb <= $startb) {
            return false;
        }

        return $starta < $endb && $enda > $startb;
    }

    /**
     * Build initials from first and last name.
     *
     * @param string $firstname first name
     * @param string $lastname last name
     * @return string
     */
    private static function to_initials(string $firstname, string $lastname): string {
        $first = trim($firstname);
        $last = trim($lastname);

        $initials = '';
        if ($first !== '') {
            $initials .= core_text::strtoupper(core_text::substr($first, 0, 1));
        }
        if ($last !== '') {
            $initials .= core_text::strtoupper(core_text::substr($last, 0, 1));
        }

        if ($initials !== '') {
            return $initials;
        }

        $fallback = trim($firstname . ' ' . $lastname);
        if ($fallback === '') {
            return '';
        }

        return core_text::strtoupper(core_text::substr($fallback, 0, 1));
    }
}
