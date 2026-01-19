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
 * Helper functions for the Moodle calendar.
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\calendar;

use context_module;
use mod_booking\event\bookingoptiondate_created;
use mod_booking\singleton_service;
use stdClass;

/**
 * Helper functions for the Bookings tracker (report2).
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_helper {
    /**
     * Helper function to hide all calendar events
     * for a specific option. (This is needed when an option is set to invisible.)
     *
     * @param int $optionid
     * @param int $visible 1=visible, 0=hidden
     */
    public static function option_set_visibility_for_all_calendar_events(int $optionid, int $visible = 1): void {
        global $DB;
        // First, hide the course event(s).
        $courseeventsql = "SELECT * FROM {event} WHERE uuid LIKE " .
            $DB->sql_concat((string)$optionid, "'-%'");
        $courseevents = $DB->get_records_sql($courseeventsql);
        foreach ($courseevents as $courseevent) {
            $courseevent->visible = $visible;
            $DB->update_record('event', $courseevent);
        }
        // Now, hide all user events.
        $usereventsql = "SELECT e.*
                        FROM {event} e
                        JOIN {booking_userevents} bue ON bue.eventid = e.id
                        WHERE bue.optionid = :optionid;";
        $params['optionid'] = $optionid;
        $userevents = $DB->get_records_sql($usereventsql, $params);
        foreach ($userevents as $userevent) {
            $userevent->visible = $visible;
            $DB->update_record('event', $userevent);
        }
        // We keep the records in booking_userevents for possible future reactivation.
        return;
    }

    /**
     * Helper function to delete all calendar events
     * for a specific option.
     *
     * @param int $optionid
     */
    public static function option_delete_all_calendar_events(int $optionid): void {
        self::option_delete_course_calendar_events($optionid);
        self::option_delete_user_calendar_events($optionid);
        return;
    }

    /**
     * Helper function to delete only course calendar events
     * for a specific option.
     *
     * @param int $optionid
     */
    public static function option_delete_course_calendar_events(int $optionid): void {
        global $DB;

        // Just to be safe, we also delete via uuid.
        $courseeventsql = "SELECT * FROM {event} WHERE uuid LIKE " .
            $DB->sql_concat((string)$optionid, "'-%'");
        $courseevents = $DB->get_records_sql($courseeventsql);
        foreach ($courseevents as $courseevent) {
            $DB->delete_records('event', ['id' => $courseevent->id]);
        }

        // Also remove the reference in booking_option!
        $data = new stdClass();
        $data->id = $optionid;
        $data->calendarid = 0;
        $DB->update_record('booking_options', $data);
        return;
    }

    /**
     * Helper function to delete only course calendar events
     * for a specific option.
     *
     * @param int $optionid
     */
    public static function option_delete_user_calendar_events(int $optionid): void {
        global $DB;
        // Hide all user calendar events for the option.
        $usereventsql = "SELECT *
                        FROM {booking_userevents}
                        WHERE optionid = :optionid;";
        $params['optionid'] = $optionid;
        $userevents = $DB->get_records_sql($usereventsql, $params);
        foreach ($userevents as $userevent) {
            $DB->delete_records('event', ['id' => $userevent->eventid]);
        }
        // Also delete the references in booking_userevents!
        $DB->delete_records('booking_userevents', ['optionid' => $optionid]);
        return;
    }

    /**
     * Helper function to update user calendar events
     * after an option or optiondate (a session of a booking option) has been changed.
     *
     * @param int $optionid
     * @param int $cmid
     * @param ?stdClass $optiondate
     *
     */
    public static function option_optiondate_update_event(int $optionid, int $cmid, ?stdClass $optiondate = null) {
        global $DB, $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // We either do this for option or optiondate
        // different way to retrieve the right events.
        if ($optiondate && !empty($settings->id)) {
            // Check if we have already associated userevents.
            if (!isset($optiondate->eventid) || (!$event = $DB->get_record('event', ['id' => $optiondate->eventid]))) {
                // If we don't find the event here, we might still be just switching to multisession.
                // Let's create the event anew.
                $bocreatedevent = bookingoptiondate_created::create(
                    [
                        'context' => context_module::instance($cmid),
                        'objectid' => $optiondate->id,
                        'userid' => $USER->id,
                        'other' => ['optionid' => $settings->id],
                    ]
                );
                $bocreatedevent->trigger();

                // We have to return false if we have switched from multisession to create the right events.
                return false;
            } else {
                // Get all the userevents.
                $sql = "SELECT e.* FROM {booking_userevents} ue
                JOIN {event} e
                ON ue.eventid = e.id
                WHERE ue.optiondateid = :optiondateid";

                $allevents = $DB->get_records_sql($sql, ['optiondateid' => $optiondate->id]);

                // Use the optiondate as data object.
                $data = $optiondate;

                if ($event = $DB->get_record('event', ['id' => $optiondate->eventid])) {
                    if ($allevents && count($allevents) > 0) {
                        if ($event && isset($event->description)) {
                            $allevents[] = $event;
                        }
                    } else {
                        $allevents = [$event];
                    }
                }
            }
        } else {
            // Get all the userevents.
            $sql = "SELECT e.* FROM {booking_userevents} ue
                        JOIN {event} e
                        ON ue.eventid = e.id
                        WHERE ue.optionid = :optionid";

            $allevents = $DB->get_records_sql($sql, ['optionid' => $settings->id]);

            // Use the option as data object.
            $data = $settings;

            if ($event = $DB->get_record('event', ['id' => $settings->calendarid])) {
                if ($allevents && count($allevents) > 0) {
                    if ($event && isset($event->description)) {
                        $allevents[] = $event;
                    }
                } else {
                    $allevents = [$event];
                }
            }
        }

        // We use $data here for $option and $optiondate, the necessary keys are the same.
        foreach ($allevents as $eventrecord) {
            if ($eventrecord->eventtype == 'user') {
                $eventrecord->description = get_rendered_eventdescription(
                    $settings->id,
                    $cmid,
                    MOD_BOOKING_DESCRIPTION_CALENDAR,
                    true
                );
            } else {
                $eventrecord->description = get_rendered_eventdescription(
                    $settings->id,
                    $cmid,
                    MOD_BOOKING_DESCRIPTION_CALENDAR,
                    false
                );
            }
            $eventrecord->name = $settings->get_title_with_prefix();
            $eventrecord->timestart = $data->coursestarttime;
            $eventrecord->timeduration = $data->courseendtime - $data->coursestarttime;
            $eventrecord->timesort = $data->coursestarttime;
            if (!$DB->update_record('event', $eventrecord)) {
                return false;
            }
        }
    }
}
