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
 * Privacy provider implementation for mod_booking.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @author Michael Pollak <moodle@michaelpollak.org>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\privacy;

use cache_helper;
use coding_exception;
use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use dml_exception;
use mod_booking\teachers_handler;
use stdClass;

/**
 * Class privacy provider implementation for mod_booking.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @author Michael Pollak <moodle@michaelpollak.org>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    // This plugin stores personal data.
    \core_privacy\local\metadata\provider,

    // This plugin is capable of determining which users have data within it.
    \core_privacy\local\request\core_userlist_provider,

    // This plugin is a core_user_data_provider.
    \core_privacy\local\request\plugin\provider {
    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'booking_answers',
            [
                'userid' => 'privacy:metadata:booking_answers:userid',
                'bookingid' => 'privacy:metadata:booking_answers:bookingid',
                'optionid' => 'privacy:metadata:booking_answers:optionid',
                'completed' => 'privacy:metadata:booking_answers:completed',
                'timemodified' => 'privacy:metadata:booking_answers:timemodified',
                'timecreated' => 'privacy:metadata:booking_answers:timecreated',
                'waitinglist' => 'privacy:metadata:booking_answers:waitinglist',
                'frombookingid' => 'privacy:metadata:booking_answers:frombookingid',
                'numrec' => 'privacy:metadata:booking_answers:numrec',
                'status' => 'privacy:metadata:booking_answers:status',
                'notes' => 'privacy:metadata:booking_answers:notes',
            ],
            'privacy:metadata:booking_answers'
        );

        $collection->add_database_table(
            'booking_ratings',
            [
                'userid' => 'privacy:metadata:booking_ratings:userid',
                'optionid' => 'privacy:metadata:booking_ratings:optionid',
                'rate' => 'privacy:metadata:booking_ratings:rate',
            ],
            'privacy:metadata:booking_ratings'
        );

        $collection->add_database_table(
            'booking_teachers',
            [
                'bookingid' => 'privacy:metadata:booking_teachers:bookingid',
                'userid' => 'privacy:metadata:booking_teachers:userid',
                'optionid' => 'privacy:metadata:booking_teachers:optionid',
                'completed' => 'privacy:metadata:booking_teachers:completed',
                'calendarid' => 'privacy:metadata:booking_teachers:calendarid',
            ],
            'privacy:metadata:booking_teachers'
        );

        $collection->add_database_table(
            'booking_icalsequence',
            [
                'userid' => 'privacy:metadata:booking_icalsequence:userid',
                'optionid' => 'privacy:metadata:booking_icalsequence:optionid',
                'sequencevalue' => 'privacy:metadata:booking_icalsequence:sequencevalue',
            ],
            'privacy:metadata:booking_icalsequence'
        );

        $collection->add_database_table(
            'booking_userevents',
            [
                'userid' => 'privacy:metadata:booking_userevents:userid',
                'optionid' => 'privacy:metadata:booking_userevents:optionid',
                'optiondateid' => 'privacy:metadata:booking_userevents:optiondateid',
                'eventid' => 'privacy:metadata:booking_userevents:eventid',
            ],
            'privacy:metadata:booking_userevents'
        );

        $collection->add_database_table(
            'booking_optiondates_teachers',
            [
                'optiondateid' => 'privacy:metadata:booking_optiondates_teachers:optiondateid',
                'userid' => 'privacy:metadata:booking_optiondates_teachers:userid',
            ],
            'privacy:metadata:booking_optiondates_teachers'
        );

        $collection->add_database_table(
            'booking_subbooking_answers',
            [
                'itemid' => 'privacy:metadata:booking_subbooking_answers:itemid',
                'optionid' => 'privacy:metadata:booking_subbooking_answers:optionid',
                'sboptionid' => 'privacy:metadata:booking_subbooking_answers:sboptionid',
                'userid' => 'privacy:metadata:booking_subbooking_answers:userid',
                'usermodified' => 'privacy:metadata:booking_subbooking_answers:usermodified',
                'json' => 'privacy:metadata:booking_subbooking_answers:json',
                'timestart' => 'privacy:metadata:booking_subbooking_answers:timestart',
                'timeend' => 'privacy:metadata:booking_subbooking_answers:timeend',
                'status' => 'privacy:metadata:booking_subbooking_answers:status',
                'timecreated' => 'privacy:metadata:booking_subbooking_answers:timecreated',
                'timemodified' => 'privacy:metadata:booking_subbooking_answers:timemodified',
            ],
            'privacy:metadata:booking_subbooking_answers'
        );

        $collection->add_database_table(
            'booking_odt_deductions',
            [
                'optiondateid' => 'privacy:metadata:booking_odt_deductions:optiondateid',
                'userid' => 'privacy:metadata:booking_odt_deductions:userid',
                'reason' => 'privacy:metadata:booking_odt_deductions:reason',
                'usermodified' => 'privacy:metadata:booking_odt_deductions:usermodified',
                'timecreated' => 'privacy:metadata:booking_odt_deductions:timecreated',
                'timemodified' => 'privacy:metadata:booking_odt_deductions:timemodified',
            ],
            'privacy:metadata:booking_odt_deductions'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {

        // Add if the user booked an event.
        $sql = "SELECT c.id
            FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {booking} boo ON boo.id = cm.instance
            INNER JOIN {booking_answers} ans ON ans.bookingid = boo.id
            WHERE ans.userid = :userid
            UNION
            SELECT c.id
            FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel2
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname2
            INNER JOIN {booking} boo ON boo.id = cm.instance
            INNER JOIN {booking_teachers} tea ON tea.bookingid = boo.id
            WHERE tea.userid = :userid2";

        $params = [
            'modname' => 'booking',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
            // This is needed a second time because every param can only be used once.
            'modname2' => 'booking',
            'contextlevel2' => CONTEXT_MODULE,
            'userid2' => $userid,
        ];

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export personal data for the given approved_contextlist.
     * User and context information is contained within the contextlist.
     * @param approved_contextlist $contextlist a list of contexts approved for export.
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $user = $contextlist->get_user();

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT  cm.id AS cmid,
                        boo.name AS bookingname,
                        cm.course AS courseid,
                        ans.optionid AS bookedoption,
                        ans.timecreated AS bookingcreated,
                        ans.timemodified AS bookingmodified,
                        ans.waitinglist AS waitinglist,
                        ans.status AS status,
                        ans.notes AS notes,
                        opt.text AS bookedoptiontext,
                        opt.coursestarttime AS coursestart,
                        opt.courseendtime AS courseend,
                        rat.rate AS rating
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {booking} boo ON boo.id = cm.instance
            INNER JOIN {booking_answers} ans ON ans.bookingid = boo.id
            INNER JOIN {booking_options} opt ON boo.id = opt.bookingid
            LEFT JOIN {booking_ratings} rat ON opt.id = rat.optionid
                 WHERE c.id {$contextsql}
                       AND ans.userid = :userid
              ORDER BY cm.id";

        $params = ['modname' => 'booking', 'contextlevel' => CONTEXT_MODULE, 'userid' => $user->id] + $contextparams;

        // Reference to the instance seen in the last iteration of the loop. By comparing this with the current record, and
        // because we know the results are ordered, we know when we've moved to the subscription for a new instance and therefore
        // when we can export the complete data for the last instance. Used this idea from mod_choice, thank you.
        $lastcmid = null;

        $bookinganswers = $DB->get_recordset_sql($sql, $params);
        foreach ($bookinganswers as $bookinganswer) {
            // If we've moved to a new instance, then write the last bookingdata and reinit the bookingdata array.
            if ($lastcmid != $bookinganswer->cmid) {
                if (!empty($bookingdata)) {
                    $context = context_module::instance($lastcmid);
                    self::export_booking($bookingdata, $context, $user);
                }
                $bookingdata = [
                    'bookingname' => $bookinganswer->bookingname,
                    'timebooked' => \core_privacy\local\request\transform::datetime($bookinganswer->bookingcreated),
                    'timelastmodified' => \core_privacy\local\request\transform::datetime($bookinganswer->bookingmodified),
                    'waitinglist' => $bookinganswer->waitinglist,
                    'status' => $bookinganswer->status,
                    'notes' => $bookinganswer->notes,
                ];
            }
            // Important, can be more than one option. Export in one nice line.
            $bookingdata['bookedoptions'][] = $bookinganswer->bookedoptiontext . " (from " .
                \core_privacy\local\request\transform::datetime($bookinganswer->coursestart). " to " .
                \core_privacy\local\request\transform::datetime($bookinganswer->courseend). ") with rating " .
                $bookinganswer->rating;
            $lastcmid = $bookinganswer->cmid;
        }
        $bookinganswers->close();

        // The data for the last activity won't have been written yet, so make sure to write it now!
        if (!empty($bookingdata)) {
            $context = context_module::instance($lastcmid);
            self::export_booking($bookingdata, $context, $user);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context the context to delete in.
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;
        if (!$context instanceof context_module) {
            return;
        }

        if ($cm = get_coursemodule_from_id('booking', $context->instanceid)) {
            // Delete all booking answers within the instance.
            $DB->delete_records('booking_answers', ['bookingid' => $cm->instance]);

            // Delete all teachers within the instance.
            $DB->delete_records('booking_teachers', ['bookingid' => $cm->instance]);

            // Also delete all entries for booking_optiondates_teachers in context.
            teachers_handler::delete_booking_optiondates_teachers_by_bookingid($cm->instance);

            // Find all ratings within the instance.
            $ratingswhere = 'id IN (SELECT id
                FROM {booking_ratings} br
                INNER JOIN {booking_options} bo
                ON br.optionid = bo.id
                WHERE bo.bookingid = :bookingid)';
            // Delete all ratings within the instance.
            $DB->delete_records_select('booking_ratings', $ratingswhere, ['bookingid' => $cm->instance]);

            // Find all icalsequence records within the instance.
            $icalsequencewhere = 'id IN (SELECT id
                FROM {booking_icalsequence} bi
                INNER JOIN {booking_options} bo
                ON bi.optionid = bo.id
                WHERE bo.bookingid = :bookingid)';
            // Delete all icalsequence records within the instance.
            $DB->delete_records_select('booking_icalsequence', $icalsequencewhere, ['bookingid' => $cm->instance]);

            // Find all entries in booking_userevents within the instance and delete the associated events from {event}.
            $eventswhere = 'id IN (SELECT eventid
                FROM {booking_userevents} bue
                INNER JOIN {booking_options} bo
                ON bue.optionid = bo.id
                WHERE bo.bookingid = :bookingid)';
            // Delete all entries in booking_userevents within the instance.
            $DB->delete_records_select('event', $eventswhere, ['bookingid' => $cm->instance]);

            // Now, find all entries in booking_userevents within the instance.
            $usereventswhere = 'id IN (SELECT id
                FROM {booking_userevents} bue
                INNER JOIN {booking_options} bo
                ON bue.optionid = bo.id
                WHERE bo.bookingid = :bookingid)';
            // Now we can delete all entries in booking_userevents within the instance.
            $DB->delete_records_select('booking_userevents', $usereventswhere, ['bookingid' => $cm->instance]);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for deletion.
     * @throws dml_exception
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {

            if (!$context instanceof context_module) {
                continue;
            }
            $instanceid = $DB->get_field('course_modules', 'instance', ['id' => $context->instanceid], MUST_EXIST);
            $DB->delete_records('booking_answers', ['bookingid' => $instanceid, 'userid' => $userid]);
            $DB->delete_records('booking_teachers', ['bookingid' => $instanceid, 'userid' => $userid]);
            // Also delete all entries for booking_optiondates_teachers in context for the user.
            teachers_handler::delete_booking_optiondates_teachers_by_bookingid($instanceid, $userid);
        }

        // Ratings, icalsequence and userevents do not have a booking id and will therefore be deleted independent of contexts.
        $DB->delete_records('booking_ratings', ['userid' => $userid]);
        $DB->delete_records('booking_icalsequence', ['userid' => $userid]);

        // Before deleting from booking_userevents, we first have to delete the associated events in table {event}.
        $eventswhere = 'id IN (SELECT eventid
                FROM {booking_userevents}
                WHERE userid = :userid)';
        $DB->delete_records_select('event', $eventswhere, ['userid' => $userid]);

        // Now, we can delete the records in booking_userevents.
        $DB->delete_records('booking_userevents', ['userid' => $userid]);
    }

    /**
     * Export the supplied personal data for a booking instance, along with any generic data or area files.
     *
     * @param array $bookingdata the personal data to export for the subscription.
     * @param context_module $context the context of the subscription.
     * @param stdClass $user the user record
     */
    protected static function export_booking(array $bookingdata, context_module $context, stdClass $user) {
        // Fetch the generic module data.
        $contextdata = helper::get_context_data($context, $user);

        // Merge with bookingdata and write it.
        $contextdata = (object)array_merge((array)$contextdata, $bookingdata);
        writer::with_context($context)->export_data([], $contextdata);

        // Write generic module intro files.
        helper::export_context_files($context, $user);
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param   userlist    $userlist   The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        // Add all users with booking_answers.
        $userlist->add_from_sql('userid', "SELECT userid FROM {booking_answers}", []);

        // Add teachers of booking options.
        $userlist->add_from_sql('userid', "SELECT userid FROM {booking_teachers}", []);

        // Add teachers of specific sessions.
        $userlist->add_from_sql('userid', "SELECT userid FROM {booking_optiondates_teachers}", []);

        // Add users with user events.
        $userlist->add_from_sql('userid', "SELECT userid FROM {booking_userevents}", []);

        // Add users with ratings.
        $userlist->add_from_sql('userid', "SELECT userid FROM {booking_ratings}", []);

        // Add users with entries in ical sequence table.
        $userlist->add_from_sql('userid', "SELECT userid FROM {booking_icalsequence}", []);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function delete_data_for_users(approved_userlist $userlist) {

        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('booking', $context->instanceid);

        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        list($usersql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $select = "userid $usersql";

        // Now delete everything related to the selected userids.
        $DB->delete_records_select('booking_answers', $select, $params);
        $DB->delete_records_select('booking_teachers', $select, $params);
        $DB->delete_records_select('booking_optiondates_teachers', $select, $params);
        cache_helper::purge_by_event('setbackcachedteachersjournal');
        $DB->delete_records_select('booking_userevents', $select, $params);
        $DB->delete_records_select('booking_ratings', $select, $params);
        $DB->delete_records_select('booking_icalsequence', $select, $params);
    }
}
