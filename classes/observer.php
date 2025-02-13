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
 * Event observers.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\event\course_module_updated;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\booking_rules\rules_info;
use mod_booking\calendar;
use mod_booking\elective;
use mod_booking\singleton_service;

/**
 * Class to handle event observer for mod_booking.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_observer {
    /**
     * Observer for the user_created event
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event) {

        $userid = $event->relateduserid;

        // Check if any booking rules apply for this new user.
        rules_info::execute_rules_for_user($userid);
    }

    /**
     * Observer for the user_updated event
     *
     * @param \core\event\user_updated $event
     */
    public static function user_updated(\core\event\user_updated $event) {

        // Prices can be depend on the user profile field. Therefore, we need to update caching of prices.
        cache_helper::purge_by_event('setbackprices');

        $userid = $event->relateduserid;

        // Check if any booking rules apply for this new user.
        rules_info::execute_rules_for_user($userid);
    }

    /**
     * Observer for the user_deleted event
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event) {
        global $DB;

        $params = ['userid' => $event->relateduserid];

        $DB->delete_records_select('booking_answers', 'userid = :userid', $params);
        $DB->delete_records_select('booking_teachers', 'userid = :userid', $params);
        $DB->delete_records_select('booking_optiondates_teachers', 'userid = :userid', $params);
        cache_helper::purge_by_event('setbackcachedteachersjournal');
        $DB->delete_records_select('booking_userevents', 'userid = :userid', $params);
        $DB->delete_records_select('booking_icalsequence', 'userid = :userid', $params);
    }

    /**
     * Triggered via user_enrolment_deleted event.
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        global $DB;
        $cp = (object) $event->other['userenrolment'];
        if ($cp->lastenrol) {
            $sql = 'SELECT bo.id, bo.bookingid
            FROM {booking_options} bo
            JOIN {booking} b ON bo.bookingid = b.id
            WHERE bo.courseid = :courseid
            AND b.removeuseronunenrol = 1';
            $params = ['courseid' => $cp->courseid];
            $options = $DB->get_records_sql($sql, $params);
            if (!empty($options)) {
                foreach ($options as $option) {
                    $bo = booking_option::create_option_from_optionid($option->id, $option->bookingid);
                    $bo->user_delete_response($cp->userid);
                }
                $optionids = array_keys($options);
                 [$insql, $inparams] = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED);
                $inparams['userid'] = $cp->userid;
                $DB->delete_records_select(
                    'booking_teachers',
                    "userid = :userid AND optionid $insql",
                    $inparams
                );
            }
        }
    }

    /**
     * Function to execute when a booking option has been created.
     * @param \mod_booking\event\bookingoption_created $event
     * @throws dml_exception
     */
    public static function bookingoption_created(\mod_booking\event\bookingoption_created $event) {
        // We do not create a calendar event here, because this is handled by bookingoption_updated event.
    }


    /**
     * Booking answer cancelled.
     *
     * @param \mod_booking\event\bookinganswer_cancelled $event
     * @throws dml_exception
     */
    public static function bookinganswer_cancelled(\mod_booking\event\bookinganswer_cancelled $event) {

        rules_info::$eventstoexecute[] = function () use ($event) {

            global $DB;

            $userid = $event->relateduserid;
            $optionid = $event->objectid;

            // If a user is removed from a booking option, we also have to delete his/her user events.
            $records = $DB->get_records('booking_userevents', ['userid' => $userid, 'optionid' => $optionid]);
            foreach ($records as $record) {
                $DB->delete_records('event', ['id' => $record->eventid]);
                $DB->delete_records('booking_userevents', ['id' => $record->id]);
            }
        };
    }

    /**
     * Booking option cancelled.
     *
     * @param \mod_booking\event\bookingoption_cancelled $event
     * @throws dml_exception
     */
    public static function bookingoption_cancelled(\mod_booking\event\bookingoption_cancelled $event) {
        rules_info::$eventstoexecute[] = function () use ($event) {
            $optionid = $event->objectid;
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

            // We don't test.
            if (PHPUNIT_TEST && empty($settings->cmid)) {
                return;
            }
            $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

            foreach ($bookinganswer->users as $user) {
                /* Third param $bookingoptioncancel = true is important,
                so we do not trigger bookinganswer_cancelled
                and send no extra cancellation mails to each user.
                Instead we want to use our new bookingoption_cancelled rule here. */
                $bookingoption->user_delete_response($user->id, false, true);

                // Also delete user events.
                calendar::delete_booking_userevents_for_option($optionid, $user->id);
            }
        };
    }

    /**
     * Updates calendar entry for teachers when a booking option is updated.
     *
     * @param \mod_booking\event\bookingoption_updated $event
     * @throws dml_exception
     */
    public static function bookingoption_updated(\mod_booking\event\bookingoption_updated $event) {
        global $DB;

        $optionid = $event->objectid;
        $cmid = $event->contextinstanceid;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // If there are associated optiondates (sessions) then update their calendar events.
        if ($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid])) {
            // Delete course event if we have optiondates (multisession!).
            if ($settings->calendarid) {
                $DB->delete_records('event', ['id' => $settings->calendarid]);
                $data = new stdClass();
                $data->id = $optionid;
                $data->calendarid = 0;
                $DB->update_record('booking_options', $data);

                // Also, delete all associated user events.

                // Get all the user events.
                $sql = "SELECT e.*
                        FROM {booking_userevents} ue
                        JOIN {event} e
                        ON ue.eventid = e.id
                        WHERE ue.optionid = :optionid
                        AND ue.optiondateid IS NULL";

                $allevents = $DB->get_records_sql($sql, ['optionid' => $optionid]);

                // We delete all userevents and return false.
                foreach ($allevents as $eventrecord) {
                    $DB->delete_records('event', ['id' => $eventrecord->id]);
                    $DB->delete_records('booking_userevents', ['id' => $eventrecord->id]);
                }
            }

            foreach ($optiondates as $optiondate) {
                // Create or update the sessions.
                option_optiondate_update_event($optionid, $cmid, $optiondate);
            }
        }

        $allteachers = $DB->get_fieldset_select(
            'booking_teachers',
            'userid',
            'optionid = :optionid AND calendarid > 0',
            [ 'optionid' => $event->objectid]
        );
        foreach ($allteachers as $key => $value) {
            new calendar($event->contextinstanceid, $event->objectid, $value, calendar::MOD_BOOKING_TYPETEACHERUPDATE);
        }

        // At the very last moment, when everything is done, we invalidate the table cache.
        booking_option::purge_cache_for_option($optionid);
    }

    /**
     * When a new booking option date is created, we insert a new calendar entry for the session
     * and hide the old booking option calendar entry.
     *
     * @param \mod_booking\event\bookingoptiondate_created $event
     */
    public static function bookingoptiondate_created(\mod_booking\event\bookingoptiondate_created $event) {

        $optionid = $event->other['optionid'];
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (empty($optionid)) {
            return;
        }

        new calendar(
            $event->contextinstanceid,
            $optionid,
            0,
            calendar::MOD_BOOKING_TYPEOPTIONDATE,
            $event->objectid
        );

        $cmid = $event->contextinstanceid;
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        $users = $bookingoption->get_all_users_booked();
        foreach ($users as $user) {
            new calendar(
                $event->contextinstanceid,
                $optionid,
                $user->userid,
                calendar::MOD_BOOKING_TYPEOPTIONDATE,
                $event->objectid,
                1
            );
        }
        // Also create calendar events for teachers.
        foreach ($settings->teacherids as $teacherid) {
            new calendar(
                $event->contextinstanceid,
                $optionid,
                $teacherid,
                calendar::MOD_BOOKING_TYPEOPTIONDATE,
                $event->objectid,
                1
            );
        }
    }

    /**
     * When a booking option is completed, we send a mail to the user (as long as sendmail is activated).
     *
     * @param \mod_booking\event\bookingoption_completed $event
     */
    public static function bookingoption_completed(\mod_booking\event\bookingoption_completed $event) {

        $optionid = $event->objectid;
        $cmid = $event->other['cmid'];
        $selecteduserid = $event->relateduserid;

        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        if (empty($bookingoption->booking->settings->sendmail)) {
            // If sendmail is not set or not active, we don't do anything.
            return;
        }

        try {
            // Send a message to the user who has completed the booking option (or who has been marked for completion).
            $bookingoption->sendmessage_completed($selecteduserid);
        } catch (coding_exception | dml_exception $e) {
            debugging('Booking option completion message could not be sent. ' .
                'Exception in function observer.php/bookingoption_completed.');
        }
    }

    /**
     * Change calendar entry when custom field is changed.
     *
     * @param \mod_booking\event\custom_field_changed $event
     * @throws dml_exception
     */
    public static function custom_field_changed(\mod_booking\event\custom_field_changed $event) {
        global $DB;

        $alloptions = $DB->get_records_sql(
            "SELECT id, bookingid
            FROM {booking_options}
            WHERE addtocalendar IN (1, 2) AND calendarid > 0"
        );

        foreach ($alloptions as $key => $value) {
            $tmpcmid = $DB->get_record_sql(
                "SELECT cm.id FROM {course_modules} cm
                JOIN {modules} md ON md.id = cm.module
                JOIN {booking} m ON m.id = cm.instance
                WHERE md.name = 'booking' AND cm.instance = ?",
                [$value->bookingid]
            );

            // There are no calendar entries for whole booking options anymore. Only for optiondates!
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* new calendar($tmpcmid->id, $value->id, 0, calendar::MOD_BOOKING_TYPEOPTION); */

            // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
            // TODO: We have to re-write this function so all calendar entries of optiondates will get updated correctly.

            $allteachers = $DB->get_records_sql(
                "SELECT userid FROM {booking_teachers} WHERE optionid = ? AND calendarid > 0",
                [$value->id]
            );

            foreach ($allteachers as $keyt => $valuet) {
                new calendar($tmpcmid->id, $value->id, $valuet->userid, calendar::MOD_BOOKING_TYPETEACHERUPDATE);
            }
        }
    }

    /**
     * When we add teacher to booking option, we also add calendar event to their calendar.
     *
     * @param \mod_booking\event\teacher_added $event
     */
    public static function teacher_added(\mod_booking\event\teacher_added $event) {
        $optionid = $event->objectid;
        if (empty($optionid)) {
            return;
        }
        $teacherid = $event->relateduserid;
        $cmid = $event->contextinstanceid;

        // For templates, we do not create calendar events for teachers (fixes #766).
        if (empty($cmid)) {
            return;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Create calendar events for the newly added teacher.
        foreach ($settings->sessions as $session) {
            new calendar(
                $cmid,
                $optionid,
                $teacherid,
                calendar::MOD_BOOKING_TYPEOPTIONDATE,
                $session->id,
                1
            );
        }
    }

    /**
     * When teacher is removed from booking option we delete their calendar records.
     *
     * @param \mod_booking\event\teacher_removed $event
     */
    public static function teacher_removed(\mod_booking\event\teacher_removed $event) {
        new calendar($event->contextinstanceid, $event->objectid, $event->relateduserid, calendar::MOD_BOOKING_TYPETEACHERREMOVE);
    }

    /**
     * When a price category identifier was changed
     * we need to update the identifiers of all associated prices.
     *
     * @param \mod_booking\event\pricecategory_changed $event
     */
    public static function pricecategory_changed(\mod_booking\event\pricecategory_changed $event) {
        global $DB;
        $oldidentifier = $event->other['oldidentifier'];
        $newidentifier = $event->other['newidentifier'];
        $pricestochange = $DB->get_records('booking_prices', ['pricecategoryidentifier' => $oldidentifier]);
        foreach ($pricestochange as $price) {
            $price->pricecategoryidentifier = $newidentifier;
            $DB->update_record('booking_prices', $price);
        }
    }

    /**
     * This is triggered on any event. Depending on the rule, the execution is triggered.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function execute_rule(\core\event\base $event) {

        rules_info::collect_rules_for_execution($event);
        if (PHPUNIT_TEST) {
            // Process after every event when unit testing.
            rules_info::filter_rules_and_execute();
        }
    }

    /**
     * When a course is completed, check if the user needs to be enrolled in the next course.
     *
     * @param \core\event\course_completed $event
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function course_completed(\core\event\course_completed $event) {
        global $DB;

        // Check if there is an associated booking_answer with status 'booked' for the userid and courseid.
        $sql = 'SELECT ba.userid, bo.courseid
                FROM {booking_answers} ba
                JOIN {booking_options} bo
                ON ba.optionid = bo.id
                WHERE ba.userid = :userid AND ba.waitinglist = 0 AND bo.courseid = :courseid';
        $params = ['userid' => $event->relateduserid, 'courseid' => $event->courseid];

        // Only execute if there are associated booking_answers.
        if ($bookedanswers = $DB->get_records_sql($sql, $params)) {
            // Call the enrolment function.
            elective::enrol_booked_users_to_course();
        }
    }

    /**
     * React on update of course module and purge singleton & caches.
     *
     * @param course_module_updated $event
     *
     * @return void
     *
     */
    public static function course_module_updated(course_module_updated $event) {

        if (!empty($event->objectid)) {
            $cm = get_coursemodule_from_id('booking', $event->objectid);
            if (!empty($cm->id)) {
                booking::purge_cache_for_booking_instance_by_cmid($cm->id);
            }
        }
    }
}
