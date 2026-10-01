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

use cache;
use cache_helper;

/**
 * Cached view of a user's course enrolments and course completions.
 *
 * Availability conditions evaluate per booking option, so a list page would otherwise ask the DB
 * once per option and course whether the user is enrolled or has completed the course. This class
 * loads the full set of course ids once (two queries) and keeps it in two layers:
 *
 * - the request cache "mod_booking/usercoursestaterequest" for any user id, and
 * - the session cache "mod_booking/usercoursestate" for the session user only. Like the booking
 *   answers cache, the session store never holds another user's state (booking for others bypasses
 *   it in both directions). Entries are invalidated per user by the enrolment and completion
 *   observers through the "setbackusercoursestate" event.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_course_state {
    /** @var string cache event the observers use to drop a user's entry */
    public const INVALIDATION_EVENT = 'setbackusercoursestate';

    /**
     * Is the user actively enrolled in the course?
     *
     * Same semantics as is_enrolled($context, $userid, '', true): active user enrolment, enabled
     * enrolment method, and the enrolment's time window contains now. A course that does not
     * exist (anymore) simply has no enrolment.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function is_enrolled(int $userid, int $courseid): bool {
        return isset(self::load($userid)['enrolled'][$courseid]);
    }

    /**
     * Has the user completed the course (course_completions.timecompleted set)?
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function has_completed(int $userid, int $courseid): bool {
        return isset(self::load($userid)['completed'][$courseid]);
    }

    /**
     * Ids of the courses the user is actively enrolled in.
     *
     * @param int $userid
     * @return int[]
     */
    public static function enrolled_courseids(int $userid): array {
        return array_keys(self::load($userid)['enrolled']);
    }

    /**
     * Ids of the courses the user has completed.
     *
     * @param int $userid
     * @return int[]
     */
    public static function completed_courseids(int $userid): array {
        return array_keys(self::load($userid)['completed']);
    }

    /**
     * Drops the cached state of one user in both layers (observers call this on enrolment and
     * completion changes). With 0 the whole request-level layer is dropped.
     *
     * @param int $userid
     * @return void
     */
    public static function invalidate(int $userid = 0): void {
        if ($userid > 0) {
            cache_helper::invalidate_by_event(self::INVALIDATION_EVENT, [$userid]);
        } else {
            cache_helper::purge_by_event(self::INVALIDATION_EVENT);
        }
    }

    /**
     * Returns the user's state, loading it from the session cache or the DB on first use.
     *
     * @param int $userid
     * @return array{enrolled: array<int,int>, completed: array<int,int>}
     */
    private static function load(int $userid): array {
        global $USER;

        $requestcache = cache::make('mod_booking', 'usercoursestaterequest');
        $data = $requestcache->get($userid);
        if (self::valid($data)) {
            return $data;
        }

        // The session cache only ever holds the session user's state; see the class comment.
        $usecache = $userid > 0 && (int)$USER->id === $userid;
        $cache = $usecache ? cache::make('mod_booking', 'usercoursestate') : null;

        $data = $cache ? $cache->get($userid) : false;
        if (!self::valid($data)) {
            $data = self::fetch($userid);
            if ($cache) {
                $cache->set($userid, $data);
            }
        }

        $requestcache->set($userid, $data);
        return $data;
    }

    /**
     * Is this a complete cached state?
     *
     * @param mixed $data
     * @return bool
     */
    private static function valid($data): bool {
        return is_array($data) && isset($data['enrolled'], $data['completed']);
    }

    /**
     * Reads the enrolled and completed course ids of a user from the DB (two queries).
     *
     * @param int $userid
     * @return array{enrolled: array<int,int>, completed: array<int,int>}
     */
    private static function fetch(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return ['enrolled' => [], 'completed' => []];
        }

        $now = time();
        $sql = "SELECT DISTINCT e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                       AND ue.status = :active
                       AND e.status = :enabled
                       AND ue.timestart < :now1
                       AND (ue.timeend = 0 OR ue.timeend > :now2)";
        $enrolled = $DB->get_fieldset_sql($sql, [
            'userid' => $userid,
            'active' => ENROL_USER_ACTIVE,
            'enabled' => ENROL_INSTANCE_ENABLED,
            'now1' => $now,
            'now2' => $now,
        ]);

        $completed = $DB->get_fieldset_select(
            'course_completions',
            'course',
            'userid = :userid AND timecompleted IS NOT NULL AND timecompleted > 0',
            ['userid' => $userid]
        );

        return [
            'enrolled' => array_fill_keys(array_map('intval', $enrolled), 1),
            'completed' => array_fill_keys(array_map('intval', $completed), 1),
        ];
    }
}
