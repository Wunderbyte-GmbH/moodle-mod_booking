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
 * @package booking
 * @copyright 2018 Michael Pollak <moodle@michaelpollak.org>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\privacy;

// TODO: Which are needed?
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use core_privacy\manager;

class provider implements
    // This plugin stores personal data.
    \core_privacy\local\metadata\provider,

    // This plugin is a core_user_data_provider.
    \core_privacy\local\request\plugin\provider {
    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $collection) : collection {

        $collection->add_database_table(
            'booking_answers',
            [
                'userid' => 'privacy:metadata:booking_answers:userid',
                'bookingid' => 'privacy:metadata:booking_answers:bookingid',
                'optionid' => 'privacy:metadata:booking_answers:optionid',
                'timemodified' => 'privacy:metadata:booking_answers:timemodified',
                'timecreated' => 'privacy:metadata:booking_answers:timecreated',
                'waitinglist' => 'privacy:metadata:booking_answers:waitinglist',
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
                'userid' => 'privacy:metadata:booking_teachers:userid',
                'optionid' => 'privacy:metadata:booking_teachers:optionid',
                'completed' => 'privacy:metadata:booking_teachers:completed',
            ],
            'privacy:metadata:booking_teachers'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {

        // Add if the user booked an event.
        $sql = "SELECT c.id
            FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {booking} boo ON boo.id = cm.instance
            INNER JOIN {booking_answers} ans ON ans.bookingid = boo.id
            WHERE ans.userid = :userid";
        $params = [
            'modname'       => 'booking',
            'contextlevel'  => CONTEXT_MODULE,
            'userid'        => $userid,
        ];
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export personal data for the given approved_contextlist. User and context information is contained within the contextlist.
     * @param approved_contextlist $contextlist a list of contexts approved for export.
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
                    $context = \context_module::instance($lastcmid);
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
            // Important, can be more then one option. Export in one nice line.
            $bookingdata['bookedoptions'][] = $bookinganswer->bookedoptiontext . " (from " .
                \core_privacy\local\request\transform::datetime($bookinganswer->coursestart). " to " .
                \core_privacy\local\request\transform::datetime($bookinganswer->courseend). ") with rating " .
                $bookinganswer->rating;
            $lastcmid = $bookinganswer->cmid;
        }
        $bookinganswers->close();

        // The data for the last activity won't have been written yet, so make sure to write it now!
        if (!empty($bookingdata)) {
            $context = \context_module::instance($lastcmid);
            self::export_booking($bookingdata, $context, $user);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }

        if ($cm = get_coursemodule_from_id('booking', $context->instanceid)) {
            $DB->delete_records('booking_answers', ['bookingid' => $cm->instance]);
            $DB->delete_records('booking_options', ['bookingid' => $cm->instance]);
            $DB->delete_records('booking_ratings', ['bookingid' => $cm->instance]);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {

            if (!$context instanceof \context_module) {
                continue;
            }
            $instanceid = $DB->get_field('course_modules', 'instance', ['id' => $context->instanceid], MUST_EXIST);
            $DB->delete_records('booking_answers', ['bookingid' => $instanceid, 'userid' => $userid]);
            $DB->delete_records('booking_options', ['bookingid' => $instanceid, 'userid' => $userid]);
            $DB->delete_records('booking_ratings', ['bookingid' => $instanceid, 'userid' => $userid]);
        }
    }

    /**
     * Export the supplied personal data for a booking instance, along with any generic data or area files.
     *
     * @param array $bookingdata the personal data to export for the subscription.
     * @param \context_module $context the context of the subscription.
     * @param \stdClass $user the user record
     */
    protected static function export_booking(array $bookingdata, \context_module $context, \stdClass $user) {
        // Fetch the generic module data.
        $contextdata = helper::get_context_data($context, $user);

        // Merge with bookingdata and write it.
        $contextdata = (object)array_merge((array)$contextdata, $bookingdata);
        writer::with_context($context)->export_data([], $contextdata);

        // Write generic module intro files.
        helper::export_context_files($context, $user);
    }
}