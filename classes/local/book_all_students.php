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

namespace mod_booking\local;

use context_course;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\singleton_service;
use stdClass;

/**
 * Bulk-book students into a booking option.
 *
 * Uses booking_option::user_submit_response() for every booking action.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_all_students {
    /**
     * Execute bulk booking for one option.
     *
     * @param int $optionid booking option id
     * @return stdClass result summary
     */
    public static function execute(int $optionid): stdClass {
        $result = (object)[
            'processed' => 0,
            'booked' => 0,
            'waitinglist' => 0,
            'skipped' => 0,
            'failed' => 0,
            'stoppedforcapacity' => false,
        ];

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings) || empty($settings->id)) {
            self::trace($optionid, 'aborted: option settings not found');
            return $result;
        }

        $bookingoption = booking_option::create_option_from_optionid($optionid);
        if (empty($bookingoption)) {
            self::trace($optionid, 'aborted: booking option instance could not be created');
            return $result;
        }

        $coursecontext = context_course::instance((int)$bookingoption->booking->course->id);
        $slotoption = (int)($settings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT) === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;

        $candidateuserids = self::get_enrolled_userids_ordered_by_enrolment((int)$bookingoption->booking->course->id);
        if (empty($candidateuserids)) {
            self::trace($optionid, 'no enrolled candidate users found');
            return $result;
        }

        self::trace(
            $optionid,
            'start bulk booking: candidates=' . count($candidateuserids)
            . ', slotoption=' . ($slotoption ? 'yes' : 'no')
        );

        foreach ($candidateuserids as $userid) {
            if (!self::has_student_archetype_role($coursecontext, $userid)) {
                continue;
            }

            self::refresh_answer_cache($optionid);

            if (self::has_active_booking_status($settings, $userid)) {
                $result->skipped++;
                self::trace($optionid, 'skip userid=' . $userid . ' reason=already_active_booking');
                continue;
            }

            if ($slotoption) {
                $selectedkeys = [];
                $slotdebug = [];
                $slotselection = self::prepare_slot_selection_for_user($settings, $userid, $selectedkeys, $slotdebug);
                if (!$slotselection) {
                    $result->skipped++;
                    self::trace(
                        $optionid,
                        'skip userid=' . $userid . ' reason=no_slot_selection details=' . json_encode($slotdebug)
                    );
                    continue;
                }
                self::trace(
                    $optionid,
                    'slot selection userid=' . $userid . ' selected=' . implode(',', $selectedkeys)
                );
            } else if (self::no_place_capacity_left($settings)) {
                $result->stoppedforcapacity = true;
                self::trace($optionid, 'stopped: no place capacity left');
                break;
            }

            $user = singleton_service::get_instance_of_user($userid);
            if (empty($user) || empty($user->id)) {
                $result->failed++;
                self::trace($optionid, 'failed userid=' . $userid . ' reason=user_record_missing');
                continue;
            }

            $result->processed++;
            $ok = $bookingoption->user_submit_response($user, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT, MOD_BOOKING_VERIFIED);

            self::refresh_answer_cache($optionid);

            if (!$ok) {
                if (!$slotoption && self::no_place_capacity_left($settings)) {
                    $result->stoppedforcapacity = true;
                    self::trace($optionid, 'stopped after submit failure: no place capacity left');
                    break;
                }
                $result->failed++;
                self::trace($optionid, 'failed userid=' . $userid . ' reason=user_submit_response_false');
                continue;
            }

            $status = singleton_service::get_instance_of_booking_answers($settings)->user_status($userid);
            if ($status == MOD_BOOKING_STATUSPARAM_BOOKED) {
                $result->booked++;
                self::trace($optionid, 'booked userid=' . $userid);
            } else if ($status == MOD_BOOKING_STATUSPARAM_WAITINGLIST) {
                $result->waitinglist++;
                self::trace($optionid, 'waitinglist userid=' . $userid);
            } else {
                $result->booked++;
                self::trace($optionid, 'booked(userid status fallback) userid=' . $userid . ' status=' . $status);
            }
        }

        self::trace(
            $optionid,
            'finished: processed=' . $result->processed
            . ', booked=' . $result->booked
            . ', waitinglist=' . $result->waitinglist
            . ', skipped=' . $result->skipped
            . ', failed=' . $result->failed
            . ', stoppedforcapacity=' . ($result->stoppedforcapacity ? 'yes' : 'no')
        );

        return $result;
    }

    /**
     * Return enrolled user ids ordered by course enrolment time.
     *
     * @param int $courseid
     * @return int[]
     */
    private static function get_enrolled_userids_ordered_by_enrolment(int $courseid): array {
        global $DB;

        $sql = "
            SELECT ue.userid,
                   MIN(COALESCE(NULLIF(ue.timecreated, 0), NULLIF(ue.timestart, 0), 0)) AS enroltime
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {user} u ON u.id = ue.userid
             WHERE e.courseid = :courseid
               AND e.status = 0
               AND ue.status = 0
               AND u.deleted = 0
               AND u.suspended = 0
          GROUP BY ue.userid
          ORDER BY enroltime ASC, ue.userid ASC
        ";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        return array_map(static function (stdClass $record): int {
            return (int)$record->userid;
        }, array_values($records));
    }

    /**
     * Check whether user has a role based on student archetype in this course context.
     *
     * @param context_course $coursecontext
     * @param int $userid
     * @return bool
     */
    private static function has_student_archetype_role(context_course $coursecontext, int $userid): bool {
        static $studentroleids = null;

        if ($studentroleids === null) {
            $studentroleids = [];
            foreach (get_archetype_roles('student') as $role) {
                $studentroleids[(int)$role->id] = true;
            }
        }

        if (empty($studentroleids)) {
            return false;
        }

        $assignments = get_user_roles($coursecontext, $userid, true);
        foreach ($assignments as $assignment) {
            if (!empty($studentroleids[(int)$assignment->roleid])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user already has an active booking state for the option.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    private static function has_active_booking_status(booking_option_settings $settings, int $userid): bool {
        $status = singleton_service::get_instance_of_booking_answers($settings)->user_status($userid);
        return in_array($status, [
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
        ], true);
    }

    /**
     * Prepare slot selection cache for one user.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    private static function prepare_slot_selection_for_user(
        booking_option_settings $settings,
        int $userid,
        array &$selectedkeys = [],
        array &$debug = []
    ): bool {
        $optionid = (int)$settings->id;
        $maxslots = max(1, (int)($settings->slotconfig->max_slots_per_user ?? 1));
        $teachersrequired = max(0, (int)($settings->slotconfig->teachers_required ?? 0));

        $slots = slot_availability::get_slots_with_status($optionid, $userid);
        $debug = [
            'total_slots' => count($slots),
            'open_or_warning_slots' => 0,
            'max_slots_per_user' => $maxslots,
            'teachers_required' => $teachersrequired,
            'selected_slots' => 0,
            'reason' => '',
        ];

        if (empty($slots)) {
            $debug['reason'] = 'no_slots_returned';
            return false;
        }

        foreach ($slots as $slot) {
            $status = (string)($slot['status'] ?? 'unavailable');
            if (in_array($status, ['open', 'warning'], true)) {
                $debug['open_or_warning_slots']++;
            }
        }

        // Prefer the least-filled slots first to avoid bias towards the first day.
        usort($slots, static function (array $a, array $b): int {
            $abookings = (int)($a['bookings'] ?? 0);
            $bbookings = (int)($b['bookings'] ?? 0);

            if ($abookings !== $bbookings) {
                return $abookings <=> $bbookings;
            }

            return (int)($a['start'] ?? 0) <=> (int)($b['start'] ?? 0);
        });

        $selected = [];
        $teachermap = [];
        $assignedteachers = [];
        $hasteacherassignments = false;

        $debug['slots_checked'] = 0;
        $debug['skipped_unavailable_status'] = 0;
        $debug['skipped_invalid_time'] = 0;
        $debug['skipped_not_enough_available_teachers'] = 0;
        $debug['skipped_not_enough_assigned_teachers'] = 0;
        $debug['skipped_evaluation_unbookable'] = 0;
        $debug['first_unbookable_message'] = '';

        if ($teachersrequired > 0) {
            $assignedteachers = self::get_assigned_teacher_ids_for_user($optionid, $userid);
            $hasteacherassignments = self::option_has_teacher_assignments($optionid);
            $debug['assigned_teachers'] = count($assignedteachers);
            $debug['has_teacher_assignments'] = $hasteacherassignments ? 1 : 0;
        }

        foreach ($slots as $slot) {
            if (count($selected) >= $maxslots) {
                break;
            }

            $debug['slots_checked']++;

            $status = (string)($slot['status'] ?? 'unavailable');
            if (!in_array($status, ['open', 'warning'], true)) {
                $debug['skipped_unavailable_status']++;
                continue;
            }

            $start = (int)$slot['start'];
            $end = (int)$slot['end'];
            if ($start <= 0 || $end <= $start) {
                $debug['skipped_invalid_time']++;
                continue;
            }

            $selectedteachers = [];
            if ($teachersrequired > 0) {
                $availableteachers = slot_availability::get_available_teachers_for_slot($optionid, $start, $end);
                if (count($availableteachers) < $teachersrequired) {
                    $debug['skipped_not_enough_available_teachers']++;
                    continue;
                }

                $availableteacherids = array_map(static function (array $teacher): int {
                    return (int)$teacher['id'];
                }, $availableteachers);

                if (!empty($assignedteachers)) {
                    $selectedteachers = array_values(array_intersect($assignedteachers, $availableteacherids));
                    if (count($selectedteachers) < $teachersrequired) {
                        $debug['skipped_not_enough_assigned_teachers']++;
                        continue;
                    }
                    $selectedteachers = array_slice($selectedteachers, 0, $teachersrequired);
                } else {
                    // No preselection for this user: randomly pick from currently available teachers.
                    shuffle($availableteacherids);
                    $selectedteachers = array_slice($availableteacherids, 0, $teachersrequired);
                }
            }

            $evaluation = slot_availability::evaluate_slot_for_user(
                $optionid,
                $start,
                $end,
                $userid,
                $selectedteachers
            );
            if (empty($evaluation['bookable'])) {
                $debug['skipped_evaluation_unbookable']++;
                if ($debug['first_unbookable_message'] === '' && !empty($evaluation['errormessage'])) {
                    $debug['first_unbookable_message'] = (string)$evaluation['errormessage'];
                }
                continue;
            }

            $key = $start . ':' . $end;
            $selected[] = $key;
            if (!empty($selectedteachers)) {
                $teachermap[$key] = $selectedteachers;
            }
        }

        if (empty($selected)) {
            $debug['reason'] = 'no_bookable_slot_found_for_user';
            return false;
        }

        $selectedkeys = $selected;
        $debug['selected_slots'] = count($selected);
        $debug['reason'] = 'ok';

        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => implode(',', $selected),
            'slot_teacher_selection' => json_encode($teachermap),
        ]);

        return true;
    }

    /**
     * Return teacher ids explicitly assigned to the user for this slot option.
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
     * Check if option has any explicit slot-teacher assignment records.
     *
     * @param int $optionid
     * @return bool
     */
    private static function option_has_teacher_assignments(int $optionid): bool {
        global $DB;

        if ($optionid <= 0) {
            return false;
        }

        static $cache = [];
        if (isset($cache[$optionid])) {
            return $cache[$optionid];
        }

        $cache[$optionid] = $DB->record_exists('booking_slot_student_teacher', ['optionid' => $optionid]);
        return $cache[$optionid];
    }

    /**
     * Return true if no places/waitinglist places are left.
     *
     * @param booking_option_settings $settings
     * @return bool
     */
    private static function no_place_capacity_left(booking_option_settings $settings): bool {
        if (empty($settings->limitanswers)) {
            return false;
        }

        $answers = singleton_service::get_instance_of_booking_answers($settings);
        if (!$answers->is_fully_booked()) {
            return false;
        }

        $maxoverbooking = (int)($settings->maxoverbooking ?? 0);
        if ($maxoverbooking <= 0) {
            return true;
        }

        return $answers->is_fully_booked_on_waitinglist();
    }

    /**
     * Return true if no slot has bookable capacity left at all.
     *
     * @param int $optionid
     * @return bool
     */
    private static function no_slot_capacity_left(int $optionid): bool {
        $slots = slot_availability::get_slots_with_status($optionid, 0);
        foreach ($slots as $slot) {
            $status = (string)($slot['status'] ?? 'unavailable');
            if (in_array($status, ['open', 'warning'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ensure booking answer and slot availability caches are refreshed.
     *
     * @param int $optionid
     * @return void
     */
    private static function refresh_answer_cache(int $optionid): void {
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_booking_answers($optionid);
        slot_availability::clear_request_cache($optionid);
    }

    /**
     * Write diagnostic trace line for adhoc task execution logs.
     *
     * @param int $optionid
     * @param string $message
     * @return void
     */
    private static function trace(int $optionid, string $message): void {
        mtrace('[mod_booking book_all_students optionid=' . $optionid . '] ' . $message);
    }
}
