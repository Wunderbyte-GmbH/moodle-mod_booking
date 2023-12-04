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
namespace mod_booking;

use calendar_event;
use Exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot.'/calendar/lib.php');

/**
 * Util class for adding events to calendar.
 *
 * @package mod_booking
 * @copyright 2019 Andraž Prinčič, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar {

    const MOD_BOOKING_TYPEOPTION = 1;
    const MOD_BOOKING_TYPEUSER = 2;
    const MOD_BOOKING_TYPETEACHERADD = 3;
    const MOD_BOOKING_TYPETEACHERREMOVE = 4;
    const MOD_BOOKING_TYPETEACHERUPDATE = 5;
    const MOD_BOOKING_TYPEOPTIONDATE = 6;

    public function __construct($cmid, $optionid, $userid, $type, $optiondateid = 0, $justbooked = 0) {
        global $DB;

        $bu = new booking_utils();

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Fix: If we only have one session and its ID is 0, then it is a "fake" session.
        // So we use the standard routine for adding an option without sessions to the calendar.
        if (count($optionsettings->sessions) == 1) {
            $onlysession = array_pop($optionsettings->sessions);
            if ($onlysession->id == 0) {
                $type = $this::MOD_BOOKING_TYPEOPTION;
            }
        }

        $newcalendarid = 0;

        switch ($type) {
            case $this::MOD_BOOKING_TYPEOPTION:
                if ($justbooked) {
                    // A user has just booked an option. The event will be created as USER event.
                    $newcalendarid = self::booking_option_add_to_cal($cmid, $optionid, $optionsettings->calendarid, $userid);
                    // When we create a user event, we have to keep track of it in our special table.
                    if ($newcalendarid) {
                        // If it's a new user event, then insert.
                        if (!$userevent = $DB->get_record('booking_userevents',
                            ['userid' => $userid,
                            'optionid' => $optionid,
                            'optiondateid' => null,
                            ])) {
                            $data = new stdClass();
                            $data->userid = $userid;
                            $data->optionid = $optionid;
                            $data->eventid = $newcalendarid;
                            $DB->insert_record('booking_userevents', $data);
                        } else {
                            // If the user event already exists, then update.
                            $DB->delete_records('event', ['id' => $userevent->eventid]);
                            $userevent->eventid = $newcalendarid;
                            $DB->update_record('booking_userevents', $userevent);
                        }
                    }
                } else {
                    if ($optionsettings->addtocalendar == 1) {
                        // Add to calendar as course event.
                        $newcalendarid = self::booking_option_add_to_cal($cmid, $optionid, $optionsettings->calendarid, 0);
                    } else {
                        if ($optionsettings->calendarid > 0) {
                            if ($DB->record_exists("event", ['id' => $optionsettings->calendarid])) {
                                // Delete event if exist.
                                $event = \calendar_event::load($optionsettings->calendarid);
                                $event->delete(true);
                            }
                        }
                    }
                    if ($newcalendarid && $newcalendarid != 0) {
                        // Fixed: Only set calendar id, if there is one.
                        $DB->set_field("booking_options", 'calendarid', $newcalendarid, ['id' => $optionid]);
                    }
                }
                break;
            case $this::MOD_BOOKING_TYPEOPTIONDATE:
                if ($justbooked) {
                    // A user has just booked. The events will be created as USER events.

                    if ($optiondate = $DB->get_record("booking_optiondates", ["id" => $optiondateid])) {
                        $newcalendarid = self::booking_optiondate_add_to_cal($cmid, $optionid,
                            $optiondate, $optionsettings->calendarid, $userid);
                        if ($newcalendarid) {
                            // If it's a new user event, then insert.
                            if (!$userevent = $DB->get_record('booking_userevents',
                                                                ['userid' => $userid,
                                                                'optionid' => $optionid,
                                                                'optiondateid' => $optiondateid,
                                                                ])) {
                                $data = new stdClass();
                                $data->userid = $userid;
                                $data->optionid = $optionid;
                                $data->eventid = $newcalendarid;
                                $data->optiondateid = $optiondateid;
                                $DB->insert_record('booking_userevents', $data);

                                // Delete old option events because we use multisession.
                                $bu->booking_hide_option_userevents($optionid);
                            } else {
                                // If the user event already exists, then update.
                                $DB->delete_records('event', ['id' => $userevent->eventid]);
                                $userevent->eventid = $newcalendarid;
                                $DB->update_record('booking_userevents', $userevent);
                            }
                        }
                    } else {
                        echo "ERROR: Calendar entry for option date could not be created.";
                    }
                } else if ($optionsettings->addtocalendar == 1) {
                    if ($optiondate = $DB->get_record("booking_optiondates", ["id" => $optiondateid])) {
                        $newcalendarid = self::booking_optiondate_add_to_cal($cmid, $optionid,
                            $optiondate, $optionsettings->calendarid, 0, 1);
                    } else {
                        echo "ERROR: Calendar entry for option date could not be created.";
                    }
                }
                break;

            case $this::MOD_BOOKING_TYPEUSER:
                break;

            case $this::MOD_BOOKING_TYPETEACHERADD:
                $newcalendarid = self::booking_option_add_to_cal($cmid, $optionid, 0, $userid);
                if ($newcalendarid) {
                    $DB->set_field("booking_teachers", 'calendarid', $newcalendarid,
                        ['userid' => $userid, 'optionid' => $optionid]);
                }
                break;

            case $this::MOD_BOOKING_TYPETEACHERUPDATE:
                $calendarid = $DB->get_field('booking_teachers', 'calendarid',
                    ['userid' => $userid, 'optionid' => $optionid]);
                $newcalendarid = self::booking_option_add_to_cal($cmid, $optionid, $calendarid, $userid);
                $DB->set_field("booking_teachers", 'calendarid', $newcalendarid,
                    ['userid' => $userid, 'optionid' => $optionid]);
                break;

            case $this::MOD_BOOKING_TYPETEACHERREMOVE:
                $calendarid = $DB->get_field('booking_teachers', 'calendarid',
                    ['userid' => $userid, 'optionid' => $optionid]);

                if ($calendarid > 0) {
                    if ($DB->record_exists("event", ['id' => $calendarid])) {
                        $event = \calendar_event::load($calendarid);
                        $event->delete(true);
                    }
                }
                break;
        }
    }

    /**
     * Add the booking option to the calendar.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $calendareventid
     * @param int $addtocalendar 0 = do not add, 1 = add as course event
     * @return int calendarid
     * @throws coding_exception
     * @throws dml_exception
     */
    private static function booking_option_add_to_cal(int $cmid, int $optionid, int $calendareventid,
        int $userid = 0, int $addtocalendar = 1) {

        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if ($optionsettings->courseendtime == 0 || $optionsettings->coursestarttime == 0) {
            return 0;
        }
        // Do not add booking option to calendar, if there are multiple sessions.
        if (count($optionsettings->sessions) > 1) {
            return 0;
        }

        if ($userid > 0) {
            // Add to user calendar.
            $courseid = 0;
            $instance = 0;
            $visible = 1;
            $fulldescription = get_rendered_eventdescription($optionid, $cmid, MOD_BOOKING_DESCRIPTION_CALENDAR);
        } else {
            // Event calendar.
            $courseid = !empty($bookingsettings->course) ? $bookingsettings->course : 0;
            $instance = $optionsettings->bookingid;
            $visible = instance_is_visible('booking', $bookingsettings);
            $fulldescription = get_rendered_eventdescription($optionid, $cmid, MOD_BOOKING_DESCRIPTION_CALENDAR);
        }

        $event = new stdClass();
        $event->type = CALENDAR_EVENT_TYPE_STANDARD;
        $event->component = 'mod_booking';
        // IMPORTANT: ONLY course events are allowed to have a modulename.
        $event->modulename = '';
        $event->id = $calendareventid;
        $event->name = $optionsettings->text;
        $event->description = $fulldescription;
        $event->format = FORMAT_HTML;

        // First, check if it is no USER event.
        if ($userid == 0 && $addtocalendar == 1) {
            $event->eventtype = 'course';
            $event->courseid = $courseid; // Only include course id in course events.
            $event->modulename = 'booking'; // ONLY course events are allowed to have a modulename.
            $event->userid = 0;
            $event->groupid = 0;
        } else {
            // User event.
            $event->eventtype = 'user';
            $event->courseid = 0;
            $event->userid = (int) $userid;
            $event->groupid = 0;
        }

        $event->instance = $instance;
        $event->timestart = $optionsettings->coursestarttime;
        $event->visible = $visible;
        $event->timeduration = $optionsettings->courseendtime - $optionsettings->coursestarttime;
        $event->timesort = $optionsettings->coursestarttime;

        if ($userid == 0 && $calendareventid > 0 && $DB->record_exists("event", ['id' => $event->id])) {
            $calendarevent = calendar_event::load($event->id);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $calendarevent->update($event, false);
            return $event->id;
        } else {
            unset($event->id);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $tmpevent = calendar_event::create($event, false);
            return $tmpevent->id;
        }
    }

    /**
     * Add a booking option date (session) to the calendar.
     *
     * @param int $cmid
     * @param int $optionid
     * @param stdClass $optiondate
     * @param int $calendareventid
     * @param int $userid
     * @param int $calendareventid
     * @param int $addtocalendar 0 = do not add, 1 = add as course event
     *
     * @return int calendarid
     */
    private static function booking_optiondate_add_to_cal(int $cmid, int $optionid, stdClass $optiondate,
        int $calendareventid, int $userid = 0, int $addtocalendar = 1) {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $fulldescription = '';

        if ($optiondate->courseendtime == 0 || $optiondate->coursestarttime == 0) {
            return 0;
        }

        if ($userid > 0) {
            // Add to user calendar.
            $courseid = 0;
            $instance = 0;
            $visible = 1;

            $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
            // If the user is booked, we have a different kind of description.
            $bookedusers = $bookingoption->get_all_users_booked();
            $forbookeduser = isset($bookedusers[$userid]);
            $fulldescription = get_rendered_eventdescription($optionid, $cmid,
                MOD_BOOKING_DESCRIPTION_CALENDAR, $forbookeduser);
        } else {
            // Event calendar.
            $courseid = !empty($bookingsettings->course) ? $bookingsettings->course : 0;
            $instance = $optionsettings->bookingid;
            $visible = instance_is_visible('booking', $bookingsettings);
            $fulldescription = get_rendered_eventdescription($optionid, $cmid, MOD_BOOKING_DESCRIPTION_CALENDAR);
        }

        $event = new stdClass();
        $event->type = CALENDAR_EVENT_TYPE_STANDARD;
        $event->component = 'mod_booking';
        // ONLY course events are allowed to have a modulename.
        $event->modulename = '';
        $event->id = $calendareventid;
        $event->name = $optionsettings->text;
        $event->description = $fulldescription;
        $event->format = FORMAT_HTML;

        if ($userid == 0 && $addtocalendar == 1) {
            $event->eventtype = 'course';
            $event->modulename = 'booking'; // ONLY course events are allowed to have a modulename.
            $event->courseid = $courseid; // Only include course id in course events.
            $event->userid = 0;
            $event->groupid = 0;
        } else {
            // User event.
            $event->eventtype = 'user';
            $event->courseid = 0;
            $event->userid = (int) $userid;
            $event->groupid = 0;
        }

        $event->instance = $instance;
        $event->timestart = $optiondate->coursestarttime;
        $event->visible = $visible;
        $event->timeduration = $optiondate->courseendtime - $optiondate->coursestarttime;
        $event->timesort = $optiondate->coursestarttime;

        // Update if the record already exists.
        if ($userid == 0 && $calendareventid > 0 && $DB->record_exists("event", ['id' => $optiondate->eventid])) {
            $calendarevent = calendar_event::load($optiondate->eventid);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $calendarevent->update($event, false);
            return $optiondate->eventid;
        } else {
            // Create the calendar event.
            unset($event->id);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $tmpevent = calendar_event::create($event, false);

            // Set the eventid in table booking_optiondates so the event can be identified later.
            $optiondate->eventid = $tmpevent->id;
            if (!empty($optiondate->eventid) && !$userid) {
                $DB->update_record('booking_optiondates', $optiondate);
            }
            return $tmpevent->id;
        }
    }

    /**
     * Delete user events. This function is needed if an option gets deleted or cancelled.
     * @param int $optionid
     * @param int $userid
     */
    public static function delete_booking_userevents_for_option(int $optionid, int $userid) {
        global $DB;

        $optioniduserid =
            "ue.optionid = :optionid
            AND ue.userid = :userid";

        $sqlusereventids =
            "SELECT eventid
            FROM {booking_userevents}
            WHERE $optioniduserid";

        $params = [
            'optionid' => $optionid,
            'userid' => $userid,
        ];
        try {
             // At first delete events themselves.
            $DB->delete_records_select("event", "id IN ( $sqlusereventids )", $params);

            // Now we can delete the booking user event entries.
            $DB->delete_records_select("booking_userevents", $optioniduserid, $params);
        } catch (Exception $e) {
            debugging('there seems to be a problem with deleting the user events.', DEBUG_NORMAL);
        }
    }
}
