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
                    $this->booking_option_add_to_cal($bookingoption->booking->settings,
                        $bookingoption->option, $userid, $bookingoption->option->calendarid);
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
                    $DB->set_field("booking_options", 'calendarid', $newcalendarid, array('id' => $this->optionid));
                }
                break;
            case $this::TYPEOPTIONDATE:
                if ($justbooked) {
                    // A user has just booked an option with sessions. The events will be created as USER events.
                    if ($optiondate = $DB->get_record("booking_optiondates", ["id" => $this->optiondateid])) {
                        $this->booking_optiondate_add_to_cal($bookingoption->booking->settings,
                            $bookingoption->option, $optiondate, $userid, $bookingoption->option->calendarid);
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
        $fulldescription = '';

        if ($option->courseendtime == 0 || $option->coursestarttime == 0) {
            return 0;
        }

        // Do not add booking option to calendar, if there are multiple sessions.
        if (!empty($DB->get_records('booking_optiondates', ['optionid' => $option->id]))) {
            return 0;
        }

        $timestart = userdate($option->coursestarttime, get_string('strftimedatetime'));
        $timefinish = userdate($option->courseendtime, get_string('strftimedatetime'));
        $fulldescription .= "<p><b>$timestart &ndash; $timefinish</b></p>";

        $fulldescription .= "<p>" . format_text($option->description, FORMAT_HTML) . "</p>";

        $customfields = $DB->get_records('booking_customfields', array('optionid' => $option->id));
        $customfieldcfg = \mod_booking\booking_option::get_customfield_settings();

        if ($customfields && !empty($customfieldcfg)) {
            foreach ($customfields as $field) {
                if (!empty($field->value)) {
                    $cfgvalue = $customfieldcfg[$field->cfgname]['value'];
                    if ($customfieldcfg[$field->cfgname]['type'] == 'multiselect') {
                        $tmpdata = implode(", ", explode("\n", $field->value));
                        $fulldescription .= "<p> <b>$cfgvalue: </b>$tmpdata</p>";
                    } else {
                        $fulldescription .= "<p> <b>$cfgvalue: </b>$field->value</p>";
                    }
                }
            }
        }

        if (strlen($option->location) > 0) {
            $fulldescription .= '<p><i>' . get_string('location', 'booking') . '</i>: ' . $option->location . '</p>';
        }

        if (strlen($option->institution) > 0) {
            $fulldescription .= '<p><i>' . get_string('institution', 'booking') . '</i>: ' . $option->institution. '</p>';
        }

        if (strlen($option->address) > 0) {
            $fulldescription .= '<p><i>' . get_string('address', 'booking') . '</i>: ' . $option->address. '</p>';
        }

        if ($userid > 0) {
            // Add to user calendar.
            $courseid = 0;
            $instance = 0;
            $modulename = 0;
            $visible = 1;
            $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$this->cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
            $fulldescription .= "<p>" . get_string("usercalendarentry", 'booking', $linkurl) . "</p>";
        } else {
            // Event calendar.
            $courseid = ($option->courseid == 0 ? $booking->course : $option->courseid);
            $modulename = ($courseid == $booking->course ? 'booking' : 0);
            $instance = ($courseid == $booking->course ? $option->bookingid : 0);
            $visible = instance_is_visible('booking', $booking);
            $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$this->cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
            $fulldescription .= "<p>" . get_string("bookingoptioncalendarentry", 'booking', $linkurl) . "</p>";
        }

        $event = new stdClass();
        $event->type = CALENDAR_EVENT_TYPE_STANDARD;
        $event->component = 'mod_booking';
        $event->id = $calendareventid;
        $event->name = $option->text;
        $event->description = $fulldescription;
        $event->format = FORMAT_HTML;

        // First, check if it is no USER event.
        if ($userid == 0) {
            if ($addtocalendar == 2) {
                // For site events use SITEID as courseid.
                $event->eventtype = 'site';
                $event->modulename = '';
                $event->courseid = SITEID;
                $event->categoryid = 0;
            } else {
                // Only include course id in course events.
                $event->eventtype = 'course';
                $event->courseid = $courseid;
                $event->userid = 0;
                $event->modulename = $modulename;
                $event->groupid = 0;
            }
        } else {
            // User event.
            $event->eventtype = 'user';
            $event->courseid = 0;
            $event->userid = (int) $userid;
            $event->modulename = $modulename;
            $event->groupid = 0;
        }

        $event->instance = $instance;
        $event->timestart = $option->coursestarttime;
        $event->visible = $visible;
        $event->timeduration = $option->courseendtime - $option->coursestarttime;
        $event->timesort = $option->coursestarttime;

        if ($userid == 0 && $calendareventid > 0 && $DB->record_exists("event", array('id' => $event->id))) {
            $calendarevent = \calendar_event::load($event->id);
            $calendarevent->update($event);
            return $event->id;
        } else {
            unset($event->id);
            $tmpevent = \calendar_event::create($event);
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

        // For multiple date sessions, hide the original booking option event.
        if ($optionevent = $DB->get_record('event', ['id' => $option->calendarid])) {
            $optionevent->visible = 0; // Hide the event.
            $DB->update_record('event', $optionevent);
        }

        $timestart = userdate($optiondate->coursestarttime, get_string('strftimedatetime'));
        $timefinish = userdate($optiondate->courseendtime, get_string('strftimedatetime'));
        $fulldescription .= "<p><b>$timestart &ndash; $timefinish</b></p>";

        $fulldescription .= "<p>" . format_text($option->description, FORMAT_HTML) . "</p>";

        // Add rendered custom fields.
        $customfieldshtml = get_rendered_customfields($optiondate->id);
        if (!empty($customfieldshtml)) {
            $fulldescription .= "<p>" . $customfieldshtml . "</p>";
        }

        if (strlen($option->location) > 0) {
            $fulldescription .= '<p><i>' . get_string('location', 'booking') . '</i>: ' . $option->location . '</p>';
        }

        if (strlen($option->institution) > 0) {
            $fulldescription .= '<p><i>' . get_string('institution', 'booking') . '</i>: ' . $option->institution. '</p>';
        }

        if (strlen($option->address) > 0) {
            $fulldescription .= '<p><i>' . get_string('address', 'booking') . '</i>: ' . $option->address. '</p>';
        }

        if ($userid > 0) {
            // Add to user calendar.
            $courseid = 0;
            $instance = 0;
            $modulename = 0;
            $visible = 1;
            $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$this->cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
            $fulldescription .= "<p>" . get_string("usercalendarentry", 'booking', $linkurl) . "</p>";
        } else {
            // Event calendar.
            $courseid = ($option->courseid == 0 ? $booking->course : $option->courseid);
            $modulename = ($courseid == $booking->course ? 'booking' : 0);
            $instance = ($courseid == $booking->course ? $option->bookingid : 0);
            $visible = instance_is_visible('booking', $booking);
            $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$this->cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
            $fulldescription .= "<p>" . get_string("bookingoptioncalendarentry", 'booking', $linkurl) . "</p>";
        }

        $event = new stdClass();
        $event->type = CALENDAR_EVENT_TYPE_STANDARD;
        $event->component = 'mod_booking';
        $event->id = $calendareventid;
        $event->name = $option->text;
        $event->description = $fulldescription;
        $event->format = FORMAT_HTML;

        if ($userid == 0) {
            if ($addtocalendar == 2) {
                // For site events use SITEID as courseid.
                $event->eventtype = 'site';
                $event->modulename = '';
                $event->courseid = SITEID;
                $event->categoryid = 0;
            } else {
                // Only include course id in course events.
                $event->eventtype = 'course';
                $event->courseid = $courseid;
                $event->userid = 0;
                $event->modulename = $modulename;
                $event->groupid = 0;
            }
        } else {
            // User event.
            $event->eventtype = 'user';
            $event->courseid = 0;
            $event->userid = (int) $userid;
            $event->modulename = $modulename;
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
            $calendarevent->update($event);
            return $optiondate->eventid;
        } else {
            // Create the calendar event.
            unset($event->id);
            $tmpevent = \calendar_event::create($event);

            // Set the eventid in table booking_optiondates so the event can be identified later.
            $optiondate->eventid = $tmpevent->id;
            if (!empty($optiondate->eventid)) {
                $DB->update_record('booking_optiondates', $optiondate);
            }
            return $tmpevent->id;
        }
    }
}