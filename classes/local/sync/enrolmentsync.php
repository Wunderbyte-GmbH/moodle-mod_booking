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
 * Sync of course enrolments to the booking option that was automatically created for the course.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\sync;

use cache;
use cache_helper;
use context_course;
use mod_booking\booking_option;
use mod_booking\local\autocreateoption;
use mod_booking\singleton_service;
use mod_booking\task\sync_course_enrolments_adhoc;
use stdClass;

/**
 * Books the users enrolled in a course into the booking option that was auto-created for it, and removes them again.
 *
 * Builds on the sync rules of {@see booking_enrolment}: each auto-created option gets a rule with the source type
 * "course", which owns the booking answers it creates (booking_answers.syncruleid).
 *
 * Enrolment events are frequent (a single cohort sync can fire thousands), so the work is split:
 * - The observer only looks up the course in a cached set of synced courses and queues one adhoc task per course.
 * - The task compares the enrolments with the booking answers in SQL and books or removes only the difference.
 *
 * Every enrolment method (manual, self, cohort, meta link, database, ...) goes through the core user_enrolment
 * events. Changes without a per-user event (an enrol instance enabled, a start date reached) are picked up by the
 * reconcile scheduled task, as the task always works on the full difference.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolmentsync {
    /** @var string Source type of the sync rules of this class. */
    const SOURCETYPE = 'course';

    /** @var string The key of the synced courses cache for the enabled rules. */
    const CACHEKEY = 'courses';

    /** @var string The key of the synced courses cache for all rules, enabled or not. */
    const CACHEKEY_ALL = 'allcourses';

    /** @var int Number of users booked or removed between two cache broadcasts. */
    const CHUNKSIZE = 100;

    /** @var int Seconds a queued sync task waits, so a burst of enrolments is handled by one run. */
    const QUEUEDELAY = 30;

    /**
     * @var bool True while booking itself enrols a user into a course (autoenrol of a booked option).
     * The observer ignores these enrolments, the user is booked already.
     */
    public static bool $bookingisenrolling = false;

    /**
     * @var bool True while this class books or removes users. Suppresses the booking confirmation and cancellation
     * mails, the events (and so the booking rules) still run.
     */
    public static bool $insync = false;

    /** @var array courseid => time the sync task was queued by this process. */
    private static array $queued = [];

    /**
     * Returns the ids of the courses an enabled course sync rule exists for.
     *
     * Cached in a MUC application cache under a single key and rebuilt with one indexed query. An empty array is
     * stored explicitly, so sites without the feature never hit the DB. All rule writes purge it via the
     * setbacksyncrules event.
     *
     * @param bool $includedisabled true to include the courses of disabled rules as well
     * @return array courseid => true
     */
    public static function get_synced_courses(bool $includedisabled = false): array {
        global $DB;

        $key = $includedisabled ? self::CACHEKEY_ALL : self::CACHEKEY;
        $cache = cache::make('mod_booking', 'syncrulecourses');
        $courses = $cache->get($key);
        if (is_array($courses)) {
            return $courses;
        }

        $select = 'sourcetype = :sourcetype' . ($includedisabled ? '' : ' AND isenabled = 1');
        $ids = $DB->get_fieldset_select(
            'booking_sync_rules',
            'DISTINCT sourceid',
            $select,
            ['sourcetype' => self::SOURCETYPE]
        );
        $courses = array_fill_keys(array_map('intval', $ids), true);

        $cache->set($key, $courses);
        return $courses;
    }

    /**
     * Called by the observer for every user_enrolment_created, _updated and _deleted event.
     *
     * Costs one lookup in a cached array for courses without a course sync rule.
     *
     * @param int $courseid
     * @return void
     */
    public static function handle_enrolment_event(int $courseid): void {
        if (self::$bookingisenrolling || self::$insync) {
            return;
        }
        if (!isset(self::get_synced_courses()[$courseid])) {
            return;
        }
        self::queue_course_sync($courseid);
    }

    /**
     * Queues the sync task for a course, unless one is queued already and has not started yet.
     *
     * Core's duplicate check also matches tasks that are running at the moment. Enrolments committed after such a
     * task read the enrolments would then be lost, so only tasks that have not started count here.
     *
     * @param int $courseid
     * @return bool true if a task was queued
     */
    public static function queue_course_sync(int $courseid): bool {
        global $DB;

        // A task this process queued can not have started before its delay has passed.
        if (isset(self::$queued[$courseid]) && self::$queued[$courseid] + self::QUEUEDELAY > time()) {
            return false;
        }

        $task = new sync_course_enrolments_adhoc();
        $task->set_custom_data(['courseid' => $courseid]);

        $record = \core\task\manager::record_from_adhoc_task($task);
        $select = 'classname = :classname AND timestarted IS NULL AND '
            . $DB->sql_compare_text('customdata', \core_text::strlen($record->customdata) + 1) . ' = :customdata';
        $pending = $DB->record_exists_select(
            'task_adhoc',
            $select,
            ['classname' => $record->classname, 'customdata' => $record->customdata]
        );

        self::$queued[$courseid] = time();
        if ($pending) {
            return false;
        }

        $task->set_next_run_time(time() + self::QUEUEDELAY);
        \core\task\manager::queue_adhoc_task($task);
        return true;
    }

    /**
     * Whether booking must not enrol the user manually into the course for the answer in this option.
     *
     * True when the answer was made by a course or group sync rule while the user holds an active answer, in any
     * option, that a course sync rule of this course created. Such a user is enrolled in the course already, by the
     * method that caused the synced booking. A manual enrolment on top would keep the user in the course after the
     * real enrolment was removed, and with it the synced booking:
     * - directly: the synced option itself enrols into its course,
     * - indirectly: synced option -> group (addtogroup) -> group sync rule of another option -> manual enrolment.
     *
     * Bookings made by the user and by cohort rules still enrol: booking never changes cohort memberships, so they
     * can not be caused by the synced booking, and they must keep the user in the course on their own.
     *
     * Disabled course rules count as well: the bookings they made are kept, and a manual enrolment added while the
     * sync is off would make them permanent once the sync is switched on again.
     *
     * @param int $optionid the option booking enrols for
     * @param int $userid
     * @param int $courseid the course booking wants to enrol the user into
     * @return bool
     */
    public static function answer_is_course_synced(int $optionid, int $userid, int $courseid): bool {
        global $DB;

        if (!isset(self::get_synced_courses(true)[$courseid])) {
            return false;
        }

        // Only answers made by a rule that booking itself can feed (course enrolments, group memberships).
        [$activesql, $activeparams] = $DB->get_in_or_equal(self::active_statuses(), SQL_PARAMS_NAMED, 'astatus');
        $sql = "SELECT 1
                  FROM {booking_answers} ba
                  JOIN {booking_sync_rules} r ON r.id = ba.syncruleid
                 WHERE ba.optionid = :optionid
                   AND ba.userid = :userid
                   AND ba.waitinglist {$activesql}
                   AND r.sourcetype IN (:course, :group)";
        $activeparams += [
            'optionid' => $optionid,
            'userid' => $userid,
            'course' => self::SOURCETYPE,
            'group' => 'group',
        ];
        if (!$DB->record_exists_sql($sql, $activeparams)) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal(self::active_statuses(), SQL_PARAMS_NAMED, 'status');
        $sql = "SELECT 1
                  FROM {booking_answers} ba
                  JOIN {booking_sync_rules} r ON r.id = ba.syncruleid
                 WHERE ba.userid = :userid
                   AND ba.waitinglist {$insql}
                   AND r.sourcetype = :sourcetype
                   AND r.sourceid = :courseid";
        $params += [
            'userid' => $userid,
            'sourcetype' => self::SOURCETYPE,
            'courseid' => $courseid,
        ];
        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Creates (or re-enables) the course sync rule of an option.
     *
     * Bookings of the rule are forced (capacity, conditions and price are ignored) and removed again when the user
     * has no enrolment left in the course.
     *
     * @param int $optionid
     * @param int $courseid
     * @return int the rule id
     */
    public static function ensure_rule(int $optionid, int $courseid): int {
        return booking_enrolment::save_single_rule($optionid, (object)[
            'sourcetype' => self::SOURCETYPE,
            'sourceid' => $courseid,
            'syncenrolaction' => 1,
            'syncunenrolaction' => 1,
            'syncconditionpolicy' => booking_enrolment::CONDITION_POLICY_OVERRIDE,
        ]);
    }

    /**
     * Brings the course sync rules of a booking instance in line with its setting.
     *
     * Setting on: every option the instance auto-created gets its rule, and the course is synced (backfill).
     * Setting off: the rules are disabled, the bookings they made are kept.
     *
     * @param int $bookingid
     * @return void
     */
    public static function apply_instance_setting(int $bookingid): void {
        global $DB, $USER;

        $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        $json = $settings->jsonobject ?? new stdClass();
        $enabled = !empty($json->{autocreateoption::JSONKEY_CATEGORY})
            && !empty($json->{autocreateoption::JSONKEY_SYNCENROLMENTS});

        if (!$enabled) {
            $sql = "SELECT r.*
                      FROM {booking_sync_rules} r
                      JOIN {booking_options} bo ON bo.id = r.bookingoptionid
                     WHERE bo.bookingid = :bookingid
                       AND r.sourcetype = :sourcetype
                       AND r.isenabled = 1";
            $rules = $DB->get_records_sql($sql, ['bookingid' => $bookingid, 'sourcetype' => self::SOURCETYPE]);
            foreach ($rules as $rule) {
                $rule->isenabled = 0;
                $rule->timemodified = time();
                $rule->usermodified = $USER->id;
                $DB->update_record('booking_sync_rules', $rule);
            }
            if (!empty($rules)) {
                cache_helper::purge_by_event('setbacksyncrules');
            }
            return;
        }

        // Only the instance's options are read (indexed), the JSON marker is checked in PHP.
        $options = $DB->get_records('booking_options', ['bookingid' => $bookingid], '', 'id, json');
        foreach ($options as $option) {
            $json = empty($option->json) ? null : json_decode($option->json);
            $courseid = (int)($json->{autocreateoption::OPTIONJSONKEY_SOURCECOURSE} ?? 0);
            if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
                continue;
            }
            self::ensure_rule((int)$option->id, $courseid);
            self::queue_course_sync($courseid);
        }
    }

    /**
     * Deletes the course sync rules of an option. Called when the option is deleted.
     *
     * @param int $optionid
     * @return void
     */
    public static function delete_rules_for_option(int $optionid): void {
        global $DB;

        $conditions = ['bookingoptionid' => $optionid, 'sourcetype' => self::SOURCETYPE];
        if ($DB->record_exists('booking_sync_rules', $conditions)) {
            $DB->delete_records('booking_sync_rules', $conditions);
            cache_helper::purge_by_event('setbacksyncrules');
        }
    }

    /**
     * Syncs all enabled course sync rules of a course. Called from the adhoc task.
     *
     * @param int $courseid
     * @return array{enrolled:int, unenrolled:int}
     */
    public static function sync_course(int $courseid): array {
        global $DB;

        // From now on, enrolments of this course need a new task again.
        unset(self::$queued[$courseid]);

        $result = ['enrolled' => 0, 'unenrolled' => 0];

        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            // The course is gone: drop its rules, the bookings are kept as history.
            $conditions = ['sourcetype' => self::SOURCETYPE, 'sourceid' => $courseid];
            if ($DB->record_exists('booking_sync_rules', $conditions)) {
                $DB->delete_records('booking_sync_rules', $conditions);
                cache_helper::purge_by_event('setbacksyncrules');
            }
            return $result;
        }

        $rules = $DB->get_records('booking_sync_rules', [
            'sourcetype' => self::SOURCETYPE,
            'sourceid' => $courseid,
            'isenabled' => 1,
        ]);
        foreach ($rules as $rule) {
            $ruleresult = self::sync_rule($rule, $context);
            $result['enrolled'] += $ruleresult['enrolled'];
            $result['unenrolled'] += $ruleresult['unenrolled'];
        }

        return $result;
    }

    /**
     * Books the missing enrolled users into the option of a rule and removes the users without any enrolment.
     *
     * @param stdClass $rule a booking_sync_rules record with the source type "course"
     * @param context_course|null $context the context of the rule's course, looked up if not given
     * @return array{enrolled:int, unenrolled:int}
     */
    public static function sync_rule(stdClass $rule, ?context_course $context = null): array {
        global $DB;

        $result = ['enrolled' => 0, 'unenrolled' => 0];

        $context = $context ?? context_course::instance((int)$rule->sourceid, IGNORE_MISSING);
        if (!$context) {
            return $result;
        }

        if (!$DB->record_exists('booking_options', ['id' => $rule->bookingoptionid])) {
            // The option is gone, the rule would only keep the observer busy.
            self::delete_rules_for_option((int)$rule->bookingoptionid);
            return $result;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$rule->bookingoptionid);
        if (empty($settings->cmid)) {
            return $result;
        }
        $option = singleton_service::get_instance_of_booking_option((int)$settings->cmid, (int)$rule->bookingoptionid);

        if (!empty($rule->syncenrol)) {
            $result['enrolled'] = self::enrol_users($rule, $option, self::get_users_to_enrol($rule, $context));
        }
        if (!empty($rule->syncunenrol)) {
            $result['unenrolled'] = self::unenrol_users($rule, $option, self::get_users_to_unenrol($rule));
        }

        return $result;
    }

    /**
     * Returns the ids of all users with an active enrolment in the course.
     *
     * @param int $courseid
     * @return int[]
     */
    public static function get_enrolled_user_ids(int $courseid): array {
        global $DB;

        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return [];
        }
        $join = get_enrolled_join($context, 'u.id', true);
        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                       {$join->joins}
                 WHERE {$join->wheres}
                   AND u.suspended = 0";
        return array_map('intval', $DB->get_fieldset_sql($sql, $join->params));
    }

    /**
     * The users to book: actively enrolled, not yet booked, no course editors and no teachers of the option.
     *
     * A cancellation that is newer than the user's enrolment wins, so a user who cancelled the synced booking is not
     * booked again. A newer enrolment (e.g. after unenrolment and re-enrolment) wins over an older cancellation.
     *
     * @param stdClass $rule
     * @param context_course $context
     * @return int[]
     */
    private static function get_users_to_enrol(stdClass $rule, context_course $context): array {
        global $DB;

        $join = get_enrolled_join($context, 'u.id', true);
        [$activesql, $activeparams] = $DB->get_in_or_equal(self::active_statuses(), SQL_PARAMS_NAMED, 'status');

        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                       {$join->joins}
                 WHERE {$join->wheres}
                   AND u.suspended = 0
                   AND NOT EXISTS (
                           SELECT 1
                             FROM {booking_answers} ba
                            WHERE ba.optionid = :optionid1
                              AND ba.userid = u.id
                              AND ba.waitinglist {$activesql}
                       )
                   AND NOT EXISTS (
                           SELECT 1
                             FROM {booking_teachers} bt
                            WHERE bt.optionid = :optionid2
                              AND bt.userid = u.id
                       )
                   AND NOT EXISTS (
                           SELECT 1
                             FROM {booking_answers} bad
                            WHERE bad.optionid = :optionid3
                              AND bad.userid = u.id
                              AND bad.waitinglist = :deleted
                              AND bad.timemodified >= (
                                      SELECT MAX(lue.timemodified)
                                        FROM {user_enrolments} lue
                                        JOIN {enrol} le ON le.id = lue.enrolid
                                       WHERE le.courseid = :courseid
                                         AND lue.userid = u.id
                                  )
                       )";
        $params = $join->params + $activeparams + [
            'optionid1' => (int)$rule->bookingoptionid,
            'optionid2' => (int)$rule->bookingoptionid,
            'optionid3' => (int)$rule->bookingoptionid,
            'deleted' => MOD_BOOKING_STATUSPARAM_DELETED,
            'courseid' => (int)$context->instanceid,
        ];
        $userids = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        if (empty($userids)) {
            return [];
        }

        // Course editors are no participants. They are few, so they are removed in PHP.
        $editors = get_users_by_capability($context, 'moodle/course:update', 'u.id');
        return array_values(array_diff($userids, array_map('intval', array_keys($editors))));
    }

    /**
     * The users to remove: booked by this rule, but without any enrolment in the course, whatever its status.
     *
     * A suspended enrolment, a disabled enrol instance or an ended enrolment period keep the booking.
     *
     * @param stdClass $rule
     * @return int[]
     */
    private static function get_users_to_unenrol(stdClass $rule): array {
        global $DB;

        [$activesql, $params] = $DB->get_in_or_equal(self::active_statuses(), SQL_PARAMS_NAMED, 'status');
        $sql = "SELECT DISTINCT ba.userid
                  FROM {booking_answers} ba
                 WHERE ba.optionid = :optionid
                   AND ba.syncruleid = :ruleid
                   AND ba.waitinglist {$activesql}
                   AND NOT EXISTS (
                           SELECT 1
                             FROM {user_enrolments} ue
                             JOIN {enrol} e ON e.id = ue.enrolid
                            WHERE e.courseid = :courseid
                              AND ue.userid = ba.userid
                       )";
        $params += [
            'optionid' => (int)$rule->bookingoptionid,
            'ruleid' => (int)$rule->id,
            'courseid' => (int)$rule->sourceid,
        ];
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Books the users into the option, forced and in chunks with one cache broadcast per chunk.
     *
     * @param stdClass $rule
     * @param booking_option $option
     * @param int[] $userids
     * @return int number of users booked
     */
    private static function enrol_users(stdClass $rule, booking_option $option, array $userids): int {
        global $DB;

        $optionid = (int)$rule->bookingoptionid;
        $booked = 0;

        foreach (array_chunk($userids, self::CHUNKSIZE) as $chunk) {
            $users = $DB->get_records_list('user', 'id', $chunk);
            $logs = [];
            $bookedids = [];

            self::$insync = true;
            try {
                foreach ($chunk as $userid) {
                    $reason = booking_enrolment::REASON_OK;
                    $message = '';
                    try {
                        if (empty($users[$userid])) {
                            $reason = booking_enrolment::REASON_BLOCKED_INVALID;
                            $message = 'User not found: ' . $userid;
                        } else {
                            // Atomic against a parallel sync of the course: only one of them books (and logs) the user.
                            $reason = booking_enrolment::book_under_lock(
                                $option,
                                $users[$userid],
                                MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE,
                                (int)$rule->id,
                                true,
                                true
                            );
                            $message = booking_enrolment::reason_message($reason, $optionid);
                            if ($reason === booking_enrolment::REASON_OK) {
                                $bookedids[] = $userid;
                            }
                        }
                    } catch (\Exception $e) {
                        $reason = booking_enrolment::REASON_BLOCKED_INVALID;
                        $message = $e->getMessage();
                    }
                    $logs[] = self::attempt_record($rule, $userid, booking_enrolment::ACTION_ENROL, $reason, $message);
                }
            } finally {
                self::$insync = false;
            }

            booking_option::broadcast_answer_caches();
            self::insert_history($rule, $bookedids, MOD_BOOKING_STATUSPARAM_BOOKED, booking_enrolment::ACTION_ENROL);
            $DB->insert_records('booking_sync_attempts', $logs);
            $booked += count($bookedids);
        }

        return $booked;
    }

    /**
     * Removes the bookings of the users, in chunks. The waiting list is synced once at the end.
     *
     * @param stdClass $rule
     * @param booking_option $option
     * @param int[] $userids
     * @return int number of users removed
     */
    private static function unenrol_users(stdClass $rule, booking_option $option, array $userids): int {
        global $DB;

        $removed = 0;
        $allremovedids = [];

        foreach (array_chunk($userids, self::CHUNKSIZE) as $chunk) {
            $logs = [];
            $removedids = [];

            self::$insync = true;
            try {
                foreach ($chunk as $userid) {
                    $reason = booking_enrolment::REASON_OK;
                    $message = '';
                    try {
                        // Atomic against a parallel sync of the course: only one of them cancels (and logs) the
                        // booking. The waiting list is synced once for all users below.
                        $reason = booking_enrolment::remove_under_lock($option, $rule, $userid);
                        $message = booking_enrolment::reason_message($reason, (int)$rule->bookingoptionid);
                        if ($reason === booking_enrolment::REASON_OK) {
                            $removedids[] = $userid;
                        }
                    } catch (\Exception $e) {
                        $reason = booking_enrolment::REASON_BLOCKED_INVALID;
                        $message = $e->getMessage();
                    }
                    $logs[] = self::attempt_record(
                        $rule,
                        $userid,
                        booking_enrolment::ACTION_UNENROL,
                        $reason,
                        $message
                    );
                }
            } finally {
                self::$insync = false;
            }

            booking_option::broadcast_answer_caches();
            self::insert_history(
                $rule,
                $removedids,
                MOD_BOOKING_STATUSPARAM_DELETED,
                booking_enrolment::ACTION_UNENROL
            );
            $DB->insert_records('booking_sync_attempts', $logs);
            $removed += count($removedids);
            $allremovedids = array_merge($allremovedids, $removedids);
        }

        if ($removed > 0) {
            // The freed places go to the waiting list once, not once per removed user.
            $option->sync_waiting_list();
            self::reenrol_own_bookings((int)$rule->sourceid, $allremovedids);
        }

        return $removed;
    }

    /**
     * Enrols the removed users again for their group rule bookings of options connected to the course.
     *
     * While a user held a synced answer for the course, group rule bookings did not enrol the user manually there,
     * see {@see self::answer_is_course_synced()}. Those the user still holds need their enrolment once the last
     * synced answer is gone.
     *
     * @param int $courseid
     * @param int[] $userids
     * @return void
     */
    private static function reenrol_own_bookings(int $courseid, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
        $sql = "SELECT ba.id, ba.optionid, ba.userid, bo.bookingid
                  FROM {booking_answers} ba
                  JOIN {booking_options} bo ON bo.id = ba.optionid
                  JOIN {booking_sync_rules} r ON r.id = ba.syncruleid
                 WHERE bo.courseid = :courseid
                   AND ba.waitinglist = :booked
                   AND ba.userid {$insql}
                   AND r.sourcetype = :group";
        $params += [
            'courseid' => $courseid,
            'booked' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'group' => 'group',
        ];
        foreach ($DB->get_records_sql($sql, $params) as $answer) {
            try {
                $bo = booking_option::create_option_from_optionid((int)$answer->optionid, (int)$answer->bookingid);
                $bo->enrol_user_coursestart((int)$answer->userid);
            } catch (\Exception $e) {
                mtrace("enrolmentsync: option {$answer->optionid}, user {$answer->userid}: " . $e->getMessage());
            }
        }
    }

    /**
     * Writes the sync-origin history entries, like {@see booking_enrolment::enrol_user_by_rule()} does.
     *
     * @param stdClass $rule
     * @param int[] $userids
     * @param int $status the answer status to look for and to write
     * @param string $action one of the booking_enrolment::ACTION_* constants
     * @return void
     */
    private static function insert_history(stdClass $rule, array $userids, int $status, string $action): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
        $params += [
            'optionid' => (int)$rule->bookingoptionid,
            'ruleid' => (int)$rule->id,
            'status' => $status,
        ];
        $answers = $DB->get_records_select(
            'booking_answers',
            "optionid = :optionid AND syncruleid = :ruleid AND waitinglist = :status AND userid {$insql}",
            $params,
            '',
            'id, bookingid, userid'
        );
        foreach ($answers as $answer) {
            booking_option::booking_history_insert(
                $status,
                (int)$answer->id,
                (int)$rule->bookingoptionid,
                (int)$answer->bookingid,
                (int)$answer->userid,
                ['syncruleid' => (int)$rule->id, 'syncaction' => $action]
            );
        }
    }

    /**
     * Builds a booking_sync_attempts record, see {@see booking_enrolment::log_attempt()}.
     *
     * @param stdClass $rule
     * @param int $userid
     * @param string $action
     * @param string $reasoncode
     * @param string $message
     * @return stdClass
     */
    private static function attempt_record(
        stdClass $rule,
        int $userid,
        string $action,
        string $reasoncode,
        string $message
    ): stdClass {
        return (object)[
            'syncruleid' => (int)$rule->id,
            'bookingoptionid' => (int)$rule->bookingoptionid,
            'userid' => $userid,
            'action' => $action,
            'reasoncode' => $reasoncode,
            'reasonmessage' => $message,
            'timecreated' => time(),
        ];
    }

    /**
     * The answer states that count as booked for the sync.
     *
     * @return int[]
     */
    private static function active_statuses(): array {
        return [
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
        ];
    }

    /**
     * Resets the per-request state. Only for tests.
     *
     * @return void
     */
    public static function reset_request_state(): void {
        self::$queued = [];
        self::$bookingisenrolling = false;
        self::$insync = false;
    }
}
