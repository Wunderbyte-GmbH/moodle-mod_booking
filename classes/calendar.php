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

    const TYPEOPTION = 1;
    const TYPEUSER = 2;
    const TYPETEACHERADD = 3;
    const TYPETEACHERREMOVE = 4;
    const TYPETEACHERUPDATE = 5;
    const TYPEOPTIONDATE = 6;

    private $optionid;
    private $userid;
    private $cmid;
    private $type;
    private $optiondateid;

    public function __construct($cmid, $optionid, $userid, $type, $optiondateid = 0, $justbooked = 0) {
        global $DB;

        $bu = new booking_utils();

        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->cmid = $cmid;
        $this->type = $type;
        $this->optiondateid = $optiondateid;

        $bookingoption = new \mod_booking\booking_option($this->cmid, $this->optionid);
        $newcalendarid = 0;

        switch ($this->type) {
            case $this::TYPEOPTION:
                if ($justbooked) {
                    // A user has just booked an option. The event will be created as USER event.
                    $newcalendarid = $this->booking_option_add_to_cal($bookingoption->booking->settings,
                        $bookingoption->option, $userid, $bookingoption->option->calendarid);
                    // When we create a user event, we have to keep track of it in our special table.
                    if ($newcalendarid) {
                        // If it's a new user event, then insert.
                        if (!$userevent = $DB->get_record('booking_userevents', ['userid' => $userid,
                            'optionid' => $optionid,
                            'optiondateid' => null])) {
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
                    if ($bookingoption->option->addtocalendar == 1) {
                        // Add to calendar as course event.
                        $newcalendarid = $this->booking_option_add_to_cal($bookingoption->booking->settings,
                            $bookingoption->option, 0, $bookingoption->option->calendarid);
                    } else if ($bookingoption->option->addtocalendar == 2) {
                        // Add to calendar as site event.
                        $newcalendarid = $this->booking_option_add_to_cal($bookingoption->booking->settings,
                            $bookingoption->option, 0, $bookingoption->option->calendarid, 2);
                    } else {
                        if ($bookingoption->option->calendarid > 0) {
                            if ($DB->record_exists("event", array('id' => $bookingoption->option->calendarid))) {
                                // Delete event if exist.
                                $event = \calendar_event::load($bookingoption->option->calendarid);
                                $event->delete(true);
                            }
                        }
                    }
                    if ($newcalendarid && $newcalendarid != 0) {
                        // Fixed: Only set calendar id, if there is one.
                        $DB->set_field("booking_options", 'calendarid', $newcalendarid, array('id' => $this->optionid));
                    }
                }
                break;
            case $this::TYPEOPTIONDATE:
                if ($justbooked) {
                    // A user has just booked an option with sessions. The events will be created as USER events.
                    if ($optiondate = $DB->get_record("booking_optiondates", ["id" => $this->optiondateid])) {
                        $newcalendarid = $this->booking_optiondate_add_to_cal($bookingoption->booking->settings,
                            $bookingoption->option, $optiondate, $userid, $bookingoption->option->calendarid);
                        if ($newcalendarid) {
                            // If it's a new user event, then insert.
                            if (!$userevent = $DB->get_record('booking_userevents', ['userid' => $userid,
                                                                                           'optionid' => $optionid,
                                                                                           'optiondateid' => $optiondateid])) {
                                $data = new stdClass();
                                $data->userid = $userid;
                                $data->optionid = $optionid;
                                $data->eventid = $newcalendarid;
                                $data->optiondateid = $this->optiondateid;
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
                } else {
                    if ($bookingoption->option->addtocalendar == 1) {
                        if ($optiondate = $DB->get_record("booking_optiondates", ["id" => $this->optiondateid])) {
                            $newcalendarid = $this->booking_optiondate_add_to_cal($bookingoption->booking->settings,
                                $bookingoption->option, $optiondate, 0, $bookingoption->option->calendarid);
                        } else {
                            echo "ERROR: Calendar entry for option date could not be created.";
                        }
                    } else if ($bookingoption->option->addtocalendar == 2) {
                        if ($optiondate = $DB->get_record("booking_optiondates", ["id" => $this->optiondateid])) {
                            $newcalendarid = $this->booking_optiondate_add_to_cal($bookingoption->booking->settings,
                                $bookingoption->option, $optiondate, 0, $bookingoption->option->calendarid,
                                2);
                        } else {
                            echo "ERROR: Calendar entry for option date could not be created.";
                        }
                    }
                }
                break;

            case $this::TYPEUSER:
                break;

            case $this::TYPETEACHERADD:
                $newcalendarid = $this->booking_option_add_to_cal($bookingoption->booking->settings, $bookingoption->option, $this->userid, 0);
                if ($newcalendarid) {
                    $DB->set_field("booking_teachers", 'calendarid', $newcalendarid, array('userid' => $this->userid, 'optionid' => $this->optionid));
                }
                break;

            case $this::TYPETEACHERUPDATE:
                $calendarid = $DB->get_field('booking_teachers', 'calendarid', array('userid' => $this->userid, 'optionid' => $this->optionid));
                $newcalendarid = $this->booking_option_add_to_cal($bookingoption->booking->settings, $bookingoption->option, $this->userid, $calendarid);
                $DB->set_field("booking_teachers", 'calendarid', $newcalendarid, array('userid' => $this->userid, 'optionid' => $this->optionid));
                break;

            case $this::TYPETEACHERREMOVE:
                $calendarid = $DB->get_field('booking_teachers', 'calendarid', array('userid' => $this->userid, 'optionid' => $this->optionid));

                if ($calendarid > 0) {
                    if ($DB->record_exists("event", array('id' => $calendarid))) {
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
     * @param $booking
     * @param array $option
     * @param numeric $userid
     * @param numeric $calendareventid
     * @param numeric $addtocalendar 0 = do not add, 1 = add as course event, 2 = add as global event.
     * @return int calendarid
     * @throws coding_exception
     * @throws dml_exception
     */
    private function booking_option_add_to_cal($booking, $option, $userid = 0, $calendareventid, $addtocalendar = 1) {
        global $DB, $CFG;

        if ($option->courseendtime == 0 || $option->coursestarttime == 0) {
            return 0;
        }

        // Do not add booking option to calendar, if there are multiple sessions.
        if (!empty($DB->get_records('booking_optiondates', ['optionid' => $option->id]))) {
            return 0;
        }

        if ($userid > 0) {
            // Add to user calendar.
            $courseid = 0;
            $instance = 0;
            $visible = 1;
            $fulldescription = get_rendered_eventdescription($option, $this->cmid, false, DESCRIPTION_CALENDAR);
        } else {
            // Event calendar.
            $courseid = !empty($booking->course) ? $booking->course : 0;
            $instance = $option->bookingid;
            $visible = instance_is_visible('booking', $booking);
            $fulldescription = get_rendered_eventdescription($option, $this->cmid, false, DESCRIPTION_CALENDAR);
        }

        $event = new stdClass();
        $event->type = CALENDAR_EVENT_TYPE_STANDARD;
        $event->component = 'mod_booking';
        // IMPORTANT: ONLY course events are allowed to have a modulename.
        $event->modulename = '';
        $event->id = $calendareventid;
        $event->name = $option->text;
        $event->description = $fulldescription;
        $event->format = FORMAT_HTML;

        // First, check if it is no USER event.
        if ($userid == 0) {
            if ($addtocalendar == 2) {
                // For site events use SITEID as courseid.
                $event->eventtype = 'site';
                $event->courseid = SITEID;
                $event->categoryid = 0;
            } else {
                // Only include course id in course events.
                $event->eventtype = 'course';
                $event->courseid = $courseid;
                // IMPORTANT: ONLY course events are allowed to have a modulename.
                $event->modulename = 'booking';
                $event->userid = 0;
                $event->groupid = 0;
            }
        } else {
            // User event.
            $event->eventtype = 'user';
            $event->courseid = 0;
            $event->userid = (int) $userid;
            $event->groupid = 0;
        }

        $event->instance = $instance;
        $event->timestart = $option->coursestarttime;
        $event->visible = $visible;
        $event->timeduration = $option->courseendtime - $option->coursestarttime;
        $event->timesort = $option->coursestarttime;

        if ($userid == 0 && $calendareventid > 0 && $DB->record_exists("event", array('id' => $event->id))) {
            $calendarevent = \calendar_event::load($event->id);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $calendarevent->update($event, false);
            return $event->id;
        } else {
            unset($event->id);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $tmpevent = \calendar_event::create($event, false);
            return $tmpevent->id;
        }
    }

    /**
     * Add a booking option date (session) to the calendar.
     *
     * @param stdClass $booking
     * @param stdClass $option
     * @param stdClass $optiondate
     * @param numeric $userid
     * @param numeric $calendareventid
     * @param numeric $addtocalendar 0 = do not add, 1 = add as course event, 2 = add as global event.
     * @return int calendarid
     * @throws coding_exception
     * @throws dml_exception
     */
    private function booking_optiondate_add_to_cal($booking, $option, $optiondate, $userid = 0, $calendareventid, $addtocalendar = 1) {
        global $DB, $CFG;
        $fulldescription = '';

        if ($optiondate->courseendtime == 0 || $optiondate->coursestarttime == 0) {
            return 0;
        }

        if ($userid > 0) {
            // Add to user calendar.
            $courseid = 0;
            $instance = 0;
            $visible = 1;

            $bookingoption = new \mod_booking\booking_option($this->cmid, $this->optionid, null, null, null, true);
            // If the user is booked, we have a different kind of description.
            $forbookeduser = isset($bookingoption->usersonlist[$userid]) ? true : false;
            $fulldescription = get_rendered_eventdescription($option, $this->cmid, $optiondate, DESCRIPTION_CALENDAR, $forbookeduser);
        } else {
            // Event calendar.
            $courseid = !empty($booking->course) ? $booking->course : 0;
            $instance = $option->bookingid;
            $visible = instance_is_visible('booking', $booking);
            $fulldescription = get_rendered_eventdescription($option, $this->cmid, $optiondate, DESCRIPTION_CALENDAR);
        }

        $event = new stdClass();
        $event->type = CALENDAR_EVENT_TYPE_STANDARD;
        $event->component = 'mod_booking';
        // IMPORTANT: ONLY course events are allowed to have a modulename.
        $event->modulename = '';
        $event->id = $calendareventid;
        $event->name = $option->text;
        $event->description = $fulldescription;
        $event->format = FORMAT_HTML;

        if ($userid == 0) {
            if ($addtocalendar == 2) {
                // For site events use SITEID as courseid.
                $event->eventtype = 'site';
                $event->courseid = SITEID;
                $event->categoryid = 0;
            } else {
                // Only include course id in course events.
                $event->eventtype = 'course';
                // IMPORTANT: ONLY course events are allowed to have a modulename.
                $event->modulename = 'booking';
                $event->courseid = $courseid;
                $event->userid = 0;
                $event->groupid = 0;
            }
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
        if ($userid == 0 && $calendareventid > 0 && $DB->record_exists("event", array('id' => $optiondate->eventid))) {
            $calendarevent = \calendar_event::load($optiondate->eventid);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $calendarevent->update($event, false);
            return $optiondate->eventid;
        } else {
            // Create the calendar event.
            unset($event->id);
            // Important: Second param needs to be false in order to fix "nopermissiontoupdatecalendar" bug.
            $tmpevent = \calendar_event::create($event, false);

            // Set the eventid in table booking_optiondates so the event can be identified later.
            $optiondate->eventid = $tmpevent->id;
            if (!empty($optiondate->eventid) && !$userid) {
                $DB->update_record('booking_optiondates', $optiondate);
            }
            return $tmpevent->id;
        }
    }
}