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

    private $optionid;
    private $userid;
    private $cmid;

    public function __construct($cmid, $optionid, $userid) {
        global $DB;

        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->cmid = $cmid;

        $bookingoption = new \mod_booking\booking_option($this->cmid, $this->optionid);

        if ($this->userid == 0) {
            $calendarid = 0;
            if ($bookingoption->option->addtocalendar == 1) {
                $calendarid = $this->booking_option_add_to_cal($bookingoption->booking, $bookingoption->option);
            } else {
                if ($bookingoption->option->calendarid > 0) {
                    // Delete event if exist.
                    $event = \calendar_event::load($bookingoption->option->calendarid);
                    $event->delete(true);
                }
            }
            $DB->set_field("booking_options", 'calendarid', $calendarid, array('id' => $this->optionid));
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
    private function booking_option_add_to_cal($booking, $option, $userid = 0) {
        global $DB;
        $whereis = '';

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

        $event = new \stdClass();
        $event->id = $option->calendarid;
        $event->name = $option->text;
        $event->description = $option->description . $whereis;
        $event->courseid = $option->courseid;
        if ($option->courseid == 0) {
            $event->courseid = $booking->course;
        }
        $event->groupid = 0;
        $event->userid = $userid;
        $event->modulename = 'booking';
        $event->instance = $option->bookingid;
        $event->eventtype = 'booking';
        $event->timestart = $option->coursestarttime;
        $event->visible = instance_is_visible('booking', $booking);
        $event->timeduration = $option->courseendtime - $option->coursestarttime;

        if ($DB->record_exists("event", array('id' => $event->id))) {
            $calendarevent = \calendar_event::load($event->id);
            $calendarevent->update($event);
            $calendarid = $event->id;
        } else {
            unset($event->id);
            $tmpevent = \calendar_event::create($event);
            $calendarid = $tmpevent->id;
        }

        return $calendarid;
    }
}