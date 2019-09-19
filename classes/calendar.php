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

defined('MOODLE_INTERNAL') || die();

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

    private $optionid;
    private $userid;
    private $cmid;
    private $type;

    public function __construct($cmid, $optionid, $userid, $type) {
        global $DB;

        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->cmid = $cmid;
        $this->type = $type;

        $bookingoption = new \mod_booking\booking_option($this->cmid, $this->optionid);
        $newcalendarid = 0;

        switch ($this->type) {
            case $this::TYPEOPTION:
                if ($bookingoption->option->addtocalendar == 1) {
                    $newcalendarid = $this->booking_option_add_to_cal($bookingoption->booking->settings, $bookingoption->option, 0, $bookingoption->option->calendarid);
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
                break;

            case $this::TYPEUSER:
                break;

            case $this::TYPETEACHERADD:
                $newcalendarid = $this->booking_option_add_to_cal($bookingoption->booking->settings, $bookingoption->option, $this->userid, 0);
                $DB->set_field("booking_teachers", 'calendarid', $newcalendarid, array('userid' => $this->userid, 'optionid' => $this->optionid));
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
     * Add the booking option to the calendar
     *
     * @param $booking
     * @param array $option
     * @param $optionvalues
     * @return int
     * @throws coding_exception
     * @throws dml_exception
     */
    private function booking_option_add_to_cal($booking, $option, $userid = 0, $calendareventid) {
        global $DB, $CFG;
        $whereis = '';

        if ($option->courseendtime == 0 || $option->coursestarttime == 0) {
            return 0;
        }

        $timestart = userdate($option->coursestarttime, get_string('strftimedatetime'));
        $timefinish = userdate($option->courseendtime, get_string('strftimedatetime'));
        $whereis .= "<p><b>$timestart &ndash; $timefinish</b></p>";

        $customfields = $DB->get_records('booking_customfields', array('optionid' => $option->id));
        $customfieldcfg = \mod_booking\booking_option::get_customfield_settings();

        if ($customfields && !empty($customfieldcfg)) {
            foreach ($customfields as $field) {
                if (!empty($field->value)) {
                    $cfgvalue = $customfieldcfg[$field->cfgname]['value'];
                    if ($customfieldcfg[$field->cfgname]['type'] == 'multiselect') {
                        $tmpdata = implode(", ", explode("\n", $field->value));
                        $whereis .= "<p> <b>$cfgvalue: </b>$tmpdata</p>";
                    } else {
                        $whereis .= "<p> <b>$cfgvalue: </b>$field->value</p>";
                    }
                }
            }
        }

        if (strlen($option->location) > 0) {
            $whereis .= '<p><b>' . get_string('location', 'booking') . '</b>: ' . $option->location . '</p>';
        }

        if (strlen($option->institution) > 0) {
            $whereis .= '<p><b>' . get_string('institution', 'booking') . '</b>: ' . $option->institution. '</p>';
        }

        if (strlen($option->address) > 0) {
            $whereis .= '<p><b>' . get_string('address', 'booking') . '</b>: ' . $option->address. '</p>';
        }

        if ($userid > 0) {
            // Add to user calendar
            $courseid = 0;
            $instance = 0;
            $modulename = 0;
            $visible = 1;
            $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$this->cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
            $whereis .= get_string("usercalendarentry", 'booking', $linkurl);
        } else {
            // Event calendar
            $courseid = ($option->courseid == 0 ? $booking->course : $option->courseid);
            $modulename = ($courseid == $booking->course ? 'booking' : 0);
            $instance = ($courseid == $booking->course ? $option->bookingid : 0);
            $visible = instance_is_visible('booking', $booking);
            $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$this->cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
            $whereis .= get_string("bookingoptioncalendarentry", 'booking', $linkurl);
        }

        $event = new \stdClass();
        $event->id = $calendareventid;
        $event->name = $option->text;
        $event->description = format_text($option->description, FORMAT_HTML) . $whereis;
        $event->format = FORMAT_HTML;
        $event->courseid = $courseid;
        $event->groupid = 0;
        $event->userid = $userid;
        $event->modulename = $modulename;
        $event->instance = $instance;
        $event->eventtype = 'booking';
        $event->timestart = $option->coursestarttime;
        $event->visible = $visible;
        $event->timeduration = $option->courseendtime - $option->coursestarttime;
        $event->timesort = $option->coursestarttime;

        if ($calendareventid > 0 && $DB->record_exists("event", array('id' => $event->id))) {
            $calendarevent = \calendar_event::load($event->id);
            $calendarevent->update($event);
            return $event->id;
        } else {
            unset($event->id);
            $tmpevent = \calendar_event::create($event);
            return $tmpevent->id;
        }
    }
}