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
use mod_booking\local\entities_compat;
use mod_booking\singleton_service;

/**
 * Slot availability service class.
 */
class slot_availability {
    /** @var array<int, array> */
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
     * @return array
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
     * @param int $excludemoveid pending move id to ignore (holder re-validating their own target)
     * @param array|null $holds pending holds to include in overlap checks
     * @param int[] $excludeanswerids additional booking answer ids to ignore (e.g. all of a
     *                                user's own active answers when re-validating their own
     *                                selection, since a user can hold more than one answer for
     *                                the same option via "book again")
     * @return int
     */
    public static function count_bookings(
        int $optionid,
        int $slotstart,
        int $slotend,
        int $excludeanswerid = 0,
        int $excludemoveid = 0,
        ?array $holds = null,
        array $excludeanswerids = []
    ): int {
        $count = 0;

        foreach (self::get_booked_slot_ranges_by_answer($optionid) as $answerid => $ranges) {
            if ($excludeanswerid > 0 && $answerid === $excludeanswerid) {
                continue;
            }
            if (in_array($answerid, $excludeanswerids, true)) {
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

        // Pending moves with a price difference (upgrades in checkout) hold their target slot
        // for the duration of the payment, so they occupy a seat too. Expired holds are ignored
        // by the store. A holder re-validating their own target passes $excludemoveid to not
        // block themselves.
        // $holds is option-wide (not slot-specific); callers iterating many slots pass it in once
        // to avoid re-querying it per slot (it is the same data for every slot of the option).
        $holds = $holds ?? slot_move_store::get_active_holds_for_option($optionid);
        foreach ($holds as $hold) {
            if ($excludemoveid > 0 && $hold['moveid'] === $excludemoveid) {
                continue;
            }

            foreach ($hold['slots'] as $range) {
                if (!self::slots_overlap($range['start'], $range['end'], $slotstart, $slotend)) {
                    continue;
                }

                $count++;
                break;
            }
        }

        return $count;
    }

    /**
     * Whether a candidate slot's warmup/cooldown buffer collides with the buffer of any other
     * currently booked slot (or pending hold) of the same option.
     *
     * Buffer settings are per-option (booking_slot_config), so both sides of every comparison
     * share the same warmup/cooldown/combination-mode. If both minutes are 0 this is a no-op
     * (per the "0 = disabled, no performance impact" requirement) and short-circuits before
     * touching the booked-slot cache.
     *
     * @param int $optionid booking option id
     * @param int $slotstart candidate slot start timestamp
     * @param int $slotend candidate slot end timestamp
     * @param int $excludeanswerid booking answer id to ignore (re-validating one's own slot)
     * @param int $excludemoveid pending move id to ignore (holder re-validating their own target)
     * @param array|null $holds pending holds to include; resolved from the store when null
     * @param int[] $excludeanswerids additional booking answer ids to ignore (see count_bookings())
     * @return bool
     */
    public static function has_buffer_conflict(
        int $optionid,
        int $slotstart,
        int $slotend,
        int $excludeanswerid = 0,
        int $excludemoveid = 0,
        ?array $holds = null,
        array $excludeanswerids = []
    ): bool {
        $config = self::get_slot_config($optionid);
        if (empty($config)) {
            return false;
        }

        $warmup = max(0, (int)($config->buffer_warmup_minutes ?? 0));
        $cooldown = max(0, (int)($config->buffer_cooldown_minutes ?? 0));
        if ($warmup <= 0 && $cooldown <= 0) {
            return false;
        }

        $mode = (string)($config->buffer_combination_mode ?? buffer_math::MODE_SUMMED);
        if (!in_array($mode, [buffer_math::MODE_SUMMED, buffer_math::MODE_OVERLAP], true)) {
            $mode = buffer_math::MODE_SUMMED;
        }
        $strategy = buffer_math::create_strategy($mode);

        foreach (self::get_booked_slot_ranges_by_answer($optionid) as $answerid => $ranges) {
            if ($excludeanswerid > 0 && $answerid === $excludeanswerid) {
                continue;
            }
            if (in_array($answerid, $excludeanswerids, true)) {
                continue;
            }

            foreach ($ranges as $range) {
                if (self::slot_buffers_collide($slotstart, $slotend, $warmup, $cooldown, $range, $strategy)) {
                    return true;
                }
            }
        }

        // Pending holds occupy their target slot for the duration of checkout (see count_bookings),
        // so their buffer must be respected too, or two concurrent checkouts could land back-to-back.
        $holds = $holds ?? slot_move_store::get_active_holds_for_option($optionid);
        foreach ($holds as $hold) {
            if ($excludemoveid > 0 && $hold['moveid'] === $excludemoveid) {
                continue;
            }

            foreach ($hold['slots'] as $range) {
                if (self::slot_buffers_collide($slotstart, $slotend, $warmup, $cooldown, $range, $strategy)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Small adapter around buffer_math::collides() for the ['start' => int, 'end' => int] range
     * shape used throughout this class. Both sides share the same warmup/cooldown, since buffer
     * settings are per-option, not per-slot.
     *
     * @param int $slotstart candidate slot start timestamp
     * @param int $slotend candidate slot end timestamp
     * @param int $warmupminutes option's warmup minutes
     * @param int $cooldownminutes option's cooldown minutes
     * @param array $range other booking's range (['start' => int, 'end' => int])
     * @param buffer_combination_strategy $strategy combination-mode strategy
     * @return bool
     */
    private static function slot_buffers_collide(
        int $slotstart,
        int $slotend,
        int $warmupminutes,
        int $cooldownminutes,
        array $range,
        buffer_combination_strategy $strategy
    ): bool {
        return buffer_math::collides(
            $slotstart,
            $slotend,
            $warmupminutes,
            $cooldownminutes,
            (int)($range['start'] ?? 0),
            (int)($range['end'] ?? 0),
            $warmupminutes,
            $cooldownminutes,
            $strategy
        );
    }

    /**
     * Returns booked slot ranges grouped by booking answer id, cached for this request.
     *
     * @param int $optionid booking option id
     * @return array
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
     * Returns all actively booked slot ranges for an option, flattened across all booking answers.
     *
     * Used by the shared entity occupancy provider (booking::return_array_of_entity_dates) so that
     * booked slots block overlapping dates/slots of other options that share the same entity.
     *
     * @param int $optionid booking option id
     * @return array list of ['start' => int, 'end' => int]
     */
    public static function get_booked_slot_ranges_for_option(int $optionid): array {
        $ranges = [];
        foreach (self::get_booked_slot_ranges_by_answer($optionid) as $answerranges) {
            foreach ($answerranges as $range) {
                $ranges[] = $range;
            }
        }
        return $ranges;
    }

    /**
     * Extract exact booked slot ranges from booking answer JSON.
     *
     * Canonical source of a booking answer's actually-booked slots (prefers the per-slot
     * teacher entries, falls back to the plain selected slots). Shared with the report DTO so
     * occupancy and reporting agree on which slots a multi-slot booking really occupies.
     *
     * @param object $answer booking answer row (must carry the `json` field)
     * @return array list of ['start' => int, 'end' => int]
     */
    public static function extract_booked_ranges_from_answer(object $answer): array {
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

        return array_values($ranges);
    }

    /**
     * Return booked slot ranges for a specific user on an option, aggregated across ALL of
     * their active answers (a user can hold more than one - see
     * get_active_answer_ids_for_user()), deduplicated and sorted by start time.
     *
     * Canonical source for "which slots does this user currently hold" - capacity logic
     * (has_remaining_slot_capacity()) and display (e.g. the booked slots shown in the
     * booking options table) both build on it, so they always agree.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return array list of ['start' => int, 'end' => int] ranges
     */
    public static function get_booked_slot_ranges_for_user(int $optionid, int $userid): array {
        if ($optionid <= 0 || $userid <= 0) {
            return [];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings)) {
            return [];
        }

        $answersobject = singleton_service::get_instance_of_booking_answers($settings);
        $answers = $answersobject->get_answers();
        if (empty($answers)) {
            return [];
        }

        $rangesbykey = [];
        foreach ($answers as $answer) {
            if ((int)($answer->userid ?? 0) !== $userid) {
                continue;
            }

            $bookingstate = (int)($answer->waitinglist ?? MOD_BOOKING_STATUSPARAM_NOTBOOKED);
            if (self::is_inactive_booking_state($bookingstate)) {
                continue;
            }

            $ranges = self::extract_booked_ranges_from_answer((object)$answer);
            foreach ($ranges as $range) {
                $start = (int)($range['start'] ?? 0);
                $end = (int)($range['end'] ?? 0);
                if ($start <= 0 || $end <= $start) {
                    continue;
                }

                $rangesbykey[$start . ':' . $end] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        $ranges = array_values($rangesbykey);
        usort($ranges, static function (array $left, array $right): int {
            return $left['start'] <=> $right['start'];
        });

        return $ranges;
    }

    /**
     * Return booked slot keys for a specific user on an option.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return array
     */
    private static function get_booked_slot_key_set_for_user(int $optionid, int $userid): array {
        $slotkeyset = [];
        foreach (self::get_booked_slot_ranges_for_user($optionid, $userid) as $range) {
            $slotkeyset[$range['start'] . ':' . $range['end']] = true;
        }

        return $slotkeyset;
    }

    /**
     * Whether the user can still buy additional slots for this option, i.e. the number of slots
     * they currently hold (across all of their own active answers, which can be more than one -
     * see get_active_answer_ids_for_user()) is below the option's max_slots_per_user.
     *
     * Used to let a user keep purchasing separate slots up to that limit (e.g. buying several
     * "phases" over time) even once they already hold at least one - unlike the generic
     * multiplebookings setting, which is a time-based re-booking gate, not a capacity one.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return bool
     */
    public static function has_remaining_slot_capacity(int $optionid, int $userid): bool {
        $config = self::get_slot_config($optionid);
        if (empty($config)) {
            return false;
        }

        $maxslots = max(1, (int)($config->max_slots_per_user ?? 1));
        $bookedcount = count(self::get_booked_slot_key_set_for_user($optionid, $userid));

        return $bookedcount < $maxslots;
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
     * @param int $excludemoveid pending move id to ignore (holder re-validating their own target)
     * @return bool
     */
    public static function is_slot_available(
        int $optionid,
        int $slotstart,
        int $slotend,
        int $userid = 0,
        array $selectedteachers = [],
        int $excludeanswerid = 0,
        int $excludemoveid = 0
    ): bool {
        $evaluation = self::evaluate_slot_for_user(
            $optionid,
            $slotstart,
            $slotend,
            $userid,
            $selectedteachers,
            $excludeanswerid,
            $excludemoveid
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
     * @param int $excludemoveid pending move id to ignore (holder re-validating their own target)
     * @param bool $uselivedata whether to query live (uncached) booking data
     * @param array|null $holds pending holds to include in overlap checks
     * @param array|null $assignedteachers pre-resolved assigned teachers for this slot
     * @param int[] $excludeanswerids additional booking answer ids to ignore (see count_bookings())
     * @return array
     */
    public static function evaluate_slot_for_user(
        int $optionid,
        int $slotstart,
        int $slotend,
        int $userid = 0,
        array $selectedteachers = [],
        int $excludeanswerid = 0,
        int $excludemoveid = 0,
        bool $uselivedata = false,
        ?array $holds = null,
        ?array $assignedteachers = null,
        array $excludeanswerids = []
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

        // If the option is tied to an entity that is already occupied during this slot — exclusive
        // mode: any overlapping booking on the entity; capacity mode: the entity's shared pool is
        // exhausted — the slot is not available. At booking commit time this reads live
        // (authoritative) so two users cannot both take the last unit of the same entity.
        if (self::has_entity_conflict_for_slot($optionid, $slotstart, $slotend, $uselivedata)) {
            $result['status'] = 'unavailable';
            $result['errormessage'] = get_string('slot_error_entity_occupied', 'mod_booking');
            return $result;
        }

        // Warmup/cooldown buffer: this slot must not fall within the preparation/follow-up
        // window of another booked slot (or pending hold) of the same option. No-op when both
        // buffer minutes are 0 (see has_buffer_conflict()).
        if (
            self::has_buffer_conflict(
                $optionid,
                $slotstart,
                $slotend,
                $excludeanswerid,
                $excludemoveid,
                $holds,
                $excludeanswerids
            )
        ) {
            $result['status'] = 'unavailable';
            $result['errormessage'] = get_string('slot_error_buffer_conflict', 'mod_booking');
            return $result;
        }

        $maxparticipants = max(1, (int)$config->max_participants_per_slot);
        $bookings = self::count_bookings(
            $optionid,
            $slotstart,
            $slotend,
            $excludeanswerid,
            $excludemoveid,
            $holds,
            $excludeanswerids
        );
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

        // Assigned teachers are per (option, user), not slot-specific; callers iterating many
        // slots pass them in once to avoid re-querying per slot.
        if ($assignedteachers === null) {
            $assignedteachers = $userid > 0
                ? self::get_assigned_teacher_ids_for_user($optionid, $userid)
                : [];
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
     * Checks whether the entity linked to this option is already occupied during the given slot
     * window by any other booking option or component (e.g. a normal option's optiondate, or a
     * slot booked on another option that shares the same entity).
     *
     * Reuses the shared local_entities occupancy provider, so the same overlap data drives both
     * normal-option conflict detection and slot availability. Dates belonging to this very option
     * are ignored (an option never blocks its own slots).
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param bool $uselive if true, read occupancy live (bypass cache) for an authoritative result;
     *                      used at booking commit time so two users cannot both book the same slot
     * @return bool true if the entity is occupied during the slot
     */
    public static function has_entity_conflict_for_slot(
        int $optionid,
        int $slotstart,
        int $slotend,
        bool $uselive = false
    ): bool {

        // Entity occupancy uses the capacity API (get_allocation_mode / get_all_dates_for_entity),
        // which only exists in local_entities >= 0.5.0. Without it (or an older/absent local_entities)
        // slots simply have no cross-entity occupancy constraint — the pre-capacity behaviour.
        if (!entities_compat::has_capacity_support()) {
            return false;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $entityid = (int)($settings->entity['id'] ?? 0);
        if ($entityid <= 0) {
            return false;
        }

        // Default 'none' means no overlap checking for this entity — skip cheaply (no dates query).
        $allocationmode = \local_entities\entities::get_allocation_mode($entityid);
        if ($allocationmode === 'none') {
            return false;
        }

        $bookeddates = \local_entities\entities::get_all_dates_for_entity($entityid, $uselive);

        // Capacity mode: the entity is a shared pool of 'maxallocation' units, not an exclusive
        // resource. Overlapping bookings from OTHER options each consume units (a booked slot is one
        // seat = one unit; a normal option's date reserves its stored per-relation quantity), and the
        // seat being booked now consumes one more. The slot is only occupied once that running sum
        // would exceed the pool — a mere time overlap is NOT a conflict while capacity remains.
        if ($allocationmode === 'capacity') {
            $maxallocation = (int) (\local_entities\entity::load($entityid)->__get('maxallocation') ?? 0);
            if ($maxallocation <= 0) {
                // No capacity limit configured means unlimited — never a conflict.
                return false;
            }

            $consumed = 0;
            foreach ($bookeddates as $bookeddate) {
                // An option never blocks its own slots.
                if (self::owner_option_id($bookeddate) === $optionid) {
                    continue;
                }
                if (!self::slots_overlap($slotstart, $slotend, (int)$bookeddate->starttime, (int)$bookeddate->endtime)) {
                    continue;
                }
                // A booked slot occupies exactly one unit (one seat); other occupancy (e.g. a normal
                // option's optiondate) reserves its stored per-relation quantity.
                $consumed += ($bookeddate->area === 'slot') ? 1 : max(1, (int)($bookeddate->quantity ?? 1));
            }

            return ($consumed + 1) > $maxallocation;
        }

        // Exclusive (and any other non-'none') mode: the entity is occupied per reservation, so ANY
        // overlapping booking from another option makes the slot unavailable.
        foreach ($bookeddates as $bookeddate) {
            // Ignore dates that belong to this very option; its own dates never block its slots.
            if (self::owner_option_id($bookeddate) === $optionid) {
                continue;
            }

            if (self::slots_overlap($slotstart, $slotend, (int)$bookeddate->starttime, (int)$bookeddate->endtime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the owning booking option id of an entity occupancy date from its link params.
     *
     * @param object $bookeddate an entitydate returned by entities::get_all_dates_for_entity()
     * @return int the owning option id, or 0 if it cannot be determined
     */
    private static function owner_option_id(object $bookeddate): int {
        if (empty($bookeddate->link)) {
            return 0;
        }
        $params = $bookeddate->link->params();
        return (int)($params['optionid'] ?? 0);
    }

    /**
     * Check if a custom slot stays within configured fixed/rolling boundaries.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @return bool
     */
    public static function is_within_slot_openings(int $optionid, int $slotstart, int $slotend): bool {
        $config = self::get_slot_config($optionid);
        if (empty($config) || $slotend <= $slotstart) {
            return false;
        }

        if (!empty($config->valid_from) && $slotstart < (int)$config->valid_from) {
            return false;
        }

        if (!empty($config->valid_until) && $slotend > ((int)$config->valid_until + DAYSECS)) {
            return false;
        }

        $openingseconds = self::time_to_seconds((string)$config->opening_time);
        $closingseconds = self::time_to_seconds((string)$config->closing_time);
        if ($closingseconds <= $openingseconds) {
            return false;
        }

        $alloweddays = self::parse_days_of_week((string)$config->days_of_week);
        if (empty($alloweddays)) {
            return false;
        }

        $daycursor = strtotime('midnight', $slotstart);
        $lastday = strtotime('midnight', $slotend - 1);
        while ($daycursor <= $lastday) {
            $dayofweek = (int)date('N', $daycursor);
            if (!in_array($dayofweek, $alloweddays, true)) {
                return false;
            }

            $daystartlimit = $daycursor + $openingseconds;
            $dayendlimit = $daycursor + $closingseconds;

            $segmentstart = max($slotstart, $daycursor);
            $segmentend = min($slotend, $daycursor + DAYSECS);

            if ($segmentstart < $segmentend && ($segmentstart < $daystartlimit || $segmentend > $dayendlimit)) {
                return false;
            }

            $daycursor += DAYSECS;
        }

        return true;
    }

    /**
     * Returns all virtual slots for a given option id and date range as array of [start, end].
     *
     * @param int $optionid booking option id
     * @param int $rangestart range start timestamp
     * @param int $rangeend range end timestamp
     * @return array
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
            $slots = self::get_session_slots_for_range($optionid, $rangestart, $rangeend);
            return slot_rules::apply_to_slots($optionid, $slots);
        }

        if ((string)($config->slot_type ?? 'fixed') === 'userdefined') {
            return [];
        }

        $duration = (int)$config->slot_duration_minutes * MINSECS;
        if ($duration <= 0) {
            return [];
        }

        // Fixed slots bake the option's warmup/cooldown buffer directly into the grid's
        // cadence (cycle = warmup + duration + gap-to-next-slot), so the schedule's rhythm
        // never depends on which slots end up booked; it only depends on configuration. With
        // warmup = cooldown = 0 this degenerates to the original duration-only cadence, so
        // existing options (and the 0-cost acceptance criterion) are unaffected.
        // Rolling slots keep their own explicit interval (denser, overlapping candidate start
        // times by design) and are guarded dynamically by has_buffer_conflict() instead.
        $warmupseconds = 0;
        $cooldownseconds = 0;
        $interval = $duration;
        if ((string)$config->slot_type === 'fixed') {
            $warmupminutes = max(0, (int)($config->buffer_warmup_minutes ?? 0));
            $cooldownminutes = max(0, (int)($config->buffer_cooldown_minutes ?? 0));
            $combinationmode = (string)($config->buffer_combination_mode ?? buffer_math::MODE_SUMMED);
            if (!in_array($combinationmode, [buffer_math::MODE_SUMMED, buffer_math::MODE_OVERLAP], true)) {
                $combinationmode = buffer_math::MODE_SUMMED;
            }
            $strategy = buffer_math::create_strategy($combinationmode);

            $warmupseconds = $warmupminutes * MINSECS;
            $cooldownseconds = $cooldownminutes * MINSECS;
            $gapseconds = $strategy->required_gap($cooldownminutes, $warmupminutes) * MINSECS;
            $interval = $duration + $gapseconds;
        } else if ((string)$config->slot_type === 'rolling') {
            $interval = (int)$config->slot_interval_minutes * MINSECS;
        }

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

                for (
                    $cyclestart = $dayopen;
                    $cyclestart + $warmupseconds + $duration + $cooldownseconds <= $dayclose;
                    $cyclestart += $interval
                ) {
                    $slotstart = $cyclestart + $warmupseconds;
                    $slotend = $slotstart + $duration;
                    if ($slotend <= $rangestart || $slotend > $rangeend) {
                        continue;
                    }

                    $slots[] = [$slotstart, $slotend];
                }
            }

            $daycursor += DAYSECS;
        }

        return slot_rules::apply_to_slots($optionid, $slots);
    }

    /**
     * Returns slots directly from option sessions (booking_optiondates).
     *
     * @param int $optionid booking option id
     * @param int $rangestart range start timestamp
     * @param int $rangeend range end timestamp
     * @return array
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
     * @return array
     */
    public static function get_slots_with_status(int $optionid, int $userid = 0): array {
        [$rangestart, $rangeend] = self::get_default_slot_range($optionid);
        if ($rangeend <= $rangestart) {
            return [];
        }

        return self::get_slots_with_status_for_range($optionid, $rangestart, $rangeend, $userid);
    }

    /**
     * Return booked slot ranges that overlap with the given day window.
     *
     * Every range is tagged with 'mine' (true for the given user's own bookings, false for
     * ranges booked by other users) so callers can render "your booking" separately from a
     * generic "not bookable" area. Own ranges take precedence when a range key is booked by
     * both the current user and (in a shared-capacity slot) someone else.
     *
     * @param int $optionid booking option id
     * @param int $daystart start of day timestamp
     * @param int $dayend end of day timestamp
     * @param int $userid current user id, 0 if none
     * @return array<int, array{start: int, end: int, mine: bool}>
     */
    public static function get_booked_ranges_for_day(int $optionid, int $daystart, int $dayend, int $userid = 0): array {
        $result = [];

        $ownkeyset = $userid > 0 ? self::get_booked_slot_key_set_for_user($optionid, $userid) : [];
        foreach (array_keys($ownkeyset) as $key) {
            [$start, $end] = array_map('intval', explode(':', $key, 2));
            if ($end <= $start || !self::slots_overlap($start, $end, $daystart, $dayend)) {
                continue;
            }

            $result[$key] = [
                'start' => $start,
                'end' => $end,
                'mine' => true,
            ];
        }

        $rangesbyanswer = self::get_booked_slot_ranges_by_answer($optionid);
        foreach ($rangesbyanswer as $ranges) {
            foreach ($ranges as $range) {
                $start = (int)($range['start'] ?? 0);
                $end = (int)($range['end'] ?? 0);
                if ($end <= $start || !self::slots_overlap($start, $end, $daystart, $dayend)) {
                    continue;
                }

                $key = $start . ':' . $end;
                if (isset($result[$key])) {
                    // Already recorded as the current user's own booking.
                    continue;
                }

                $result[$key] = [
                    'start' => $start,
                    'end' => $end,
                    'mine' => false,
                ];
            }
        }

        return array_values($result);
    }

    /**
     * Returns slots with availability status for an explicit range.
     *
     * @param int $optionid booking option id
     * @param int $rangestart range start timestamp
     * @param int $rangeend range end timestamp
     * @param int $userid user id
     * @return array
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

        $userbookedslotset = $userid > 0
            ? self::get_booked_slot_key_set_for_user($optionid, $userid)
            : [];

        // Fetch the option-wide pending holds once and reuse them across all slots below,
        // instead of re-querying them per slot inside count_bookings()/evaluate_slot_for_user().
        $holds = slot_move_store::get_active_holds_for_option($optionid);
        // Assigned teachers are per (option, user), not per slot - fetch once and reuse below.
        $assignedteachers = $userid > 0 ? self::get_assigned_teacher_ids_for_user($optionid, $userid) : [];

        $result = [];
        foreach ($slots as $slot) {
            [$slotstart, $slotend] = $slot;
            $slotkey = $slotstart . ':' . $slotend;

            if (!empty($userbookedslotset[$slotkey])) {
                $bookings = self::count_bookings($optionid, $slotstart, $slotend, 0, 0, $holds);
                $result[] = [
                    'start' => $slotstart,
                    'end' => $slotend,
                    'status' => 'booked',
                    'bookings' => $bookings,
                    'capacity' => $capacity,
                    'warningmessage' => '',
                ];
                continue;
            }

            $bookings = self::count_bookings($optionid, $slotstart, $slotend, 0, 0, $holds);
            $evaluation = self::evaluate_slot_for_user(
                $optionid,
                $slotstart,
                $slotend,
                $userid,
                [],
                0,
                0,
                false,
                $holds,
                $assignedteachers
            );
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
     * @return array
     */
    private static function get_default_slot_range(int $optionid): array {
        $config = self::get_slot_config($optionid);

        // For session-type slots derive the range from the actual option sessions so
        // that sessions set far in the future are not cut off by the fixed +365-day window.
        if ((string)($config->slot_type ?? 'fixed') === 'session') {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $sessions = (array)($settings->sessions ?? []);
            if (!empty($sessions)) {
                $starts = array_map(static function ($s): int {
                    return (int)($s->coursestarttime ?? 0);
                }, $sessions);
                $ends = array_map(static function ($s): int {
                    return (int)($s->courseendtime ?? 0);
                }, $sessions);
                $minsession = min($starts);
                $maxsession = max($ends);
                if ($minsession > 0 && $maxsession > $minsession) {
                    return [$minsession, $maxsession];
                }
            }
        }

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
     * Whether any two ranges in the given list overlap in time.
     *
     * A single submission can select several ranges at once (see slot_config
     * max_slots_per_user); each range is normally checked individually against the option's
     * existing bookings, which does not catch two ranges from the *same* submission
     * overlapping each other, since neither is in the database yet. Call this before
     * persisting a multi-slot selection to reject that invalid combination.
     *
     * @param array $ranges list of [start, end] pairs
     * @return bool
     */
    public static function ranges_overlap_internally(array $ranges): bool {
        $count = count($ranges);
        for ($i = 0; $i < $count; $i++) {
            [$starta, $enda] = $ranges[$i];
            for ($j = $i + 1; $j < $count; $j++) {
                [$startb, $endb] = $ranges[$j];
                if (self::slots_overlap((int)$starta, (int)$enda, (int)$startb, (int)$endb)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns the ids of all of the user's own currently active booking answers for this option.
     *
     * Used to exclude the user's own already-reserved/booked slot(s) from conflict checks when
     * re-validating a selection that was already persisted (e.g. re-checking a cached selection
     * after it was added to the shopping cart) - without this, a user's own booking is counted
     * as an occupant against itself, making an already-booked slot look unavailable. Returns
     * every active answer, not just one: "book again" (multiplebookings) lets a user hold more
     * than one active answer for the same option at once.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return int[] booking answer ids, empty if the user has no active answer for this option
     */
    public static function get_active_answer_ids_for_user(int $optionid, int $userid): array {
        if ($optionid <= 0 || $userid <= 0) {
            return [];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings)) {
            return [];
        }

        $answerids = [];
        $answersobject = singleton_service::get_instance_of_booking_answers($settings);
        foreach ($answersobject->get_answers() as $answer) {
            if ((int)($answer->userid ?? 0) !== $userid) {
                continue;
            }

            $bookingstate = (int)($answer->waitinglist ?? MOD_BOOKING_STATUSPARAM_NOTBOOKED);
            if (self::is_inactive_booking_state($bookingstate)) {
                continue;
            }

            $answerids[] = (int)($answer->baid ?? 0);
        }

        return $answerids;
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

    /**
     * Reset static caches (call from tests teardown).
     */
    public static function reset_caches(): void {
        self::$bookedslotrangecache = [];
    }
}
