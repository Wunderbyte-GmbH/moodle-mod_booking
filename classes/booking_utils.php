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

use html_writer;
use moodle_url;
use stdClass;

/**
 * Utils
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_utils {

    private function pretty_duration($seconds) {
        $measures = array('days' => 24 * 60 * 60, 'hours' => 60 * 60, 'minutes' => 60);
        $durationparts = array();
        foreach ($measures as $label => $amount) {
            if ($seconds >= $amount) {
                $howmany = floor($seconds / $amount);
                $durationparts[] = get_string($label, 'mod_booking', $howmany);
                $seconds -= $howmany * $amount;
            }
        }
        return implode(' ', $durationparts);
    }

    /**
     * Prepares the data to be sent with confirmation mail
     *
     * @param stdClass $booking
     * @return stdClass data to be sent via mail
     */
    public function generate_params(stdClass $booking, stdClass $option = null) {
        global $DB;

        $params = new stdClass();

        $params->duration = $booking->duration;
        $params->eventtype = $booking->eventtype;

        if (!is_null($option)) {

            $teacher = $DB->get_records('booking_teachers', array('optionid' => $option->id));

            $i = 1;

            foreach ($teacher as $value) {

                $user = $DB->get_record('user', array('id' => $value->userid),
                        'firstname, lastname', IGNORE_MULTIPLE);
                $params->{"teacher" . $i} = $user->firstname . ' ' . $user->lastname;

                $i++;
            }

            if (isset($params->teacher1)) {
                $params->teacher = $params->teacher1;
            } else {
                $params->teacher = '';
            }

            $timeformat = get_string('strftimetime');
            $dateformat = get_string('strftimedate');

            $duration = '';
            if ($option->coursestarttime && $option->courseendtime) {
                $seconds = $option->courseendtime - $option->coursestarttime;
                $duration = $this->pretty_duration($seconds);
            }
            $courselink = '';
            if ($option->courseid) {
                $courselink = new moodle_url('/course/view.php', array('id' => $option->courseid));
                $courselink = html_writer::link($courselink, $courselink->out());
            }

            $params->title = s($option->text);
            $params->starttime = $option->coursestarttime ? userdate($option->coursestarttime,
                    $timeformat) : '';
            $params->endtime = $option->courseendtime ? userdate($option->courseendtime,
                    $timeformat) : '';
            $params->startdate = $option->coursestarttime ? userdate($option->coursestarttime,
                    $dateformat) : '';
            $params->enddate = $option->courseendtime ? userdate($option->courseendtime,
                    $dateformat) : '';
            $params->courselink = $courselink;
            $params->location = $option->location;
            $params->institution = $option->institution;
            $params->address = $option->address;
            $params->pollstartdate = $option->coursestarttime ? userdate(
                    (int) $option->coursestarttime, get_string('pollstrftimedate', 'booking'), '',
                    false) : '';
            if (!empty($option->pollurl)) {
                $params->pollurl = $option->pollurl;
            } else {
                $params->pollurl = $booking->pollurl;
            }
            if (!empty($option->pollurlteachers)) {
                $params->pollurlteachers = $option->pollurlteachers;
            } else {
                $params->pollurlteachers = $booking->pollurlteachers;
            }

            $val = '';
            if (!empty($option->optiontimes)) {
                $additionaltimes = explode(',', $option->optiontimes);
                if (!empty($additionaltimes)) {
                    foreach ($additionaltimes as $t) {
                        $slot = explode('-', $t);
                        $tmpdate = new stdClass();
                        $tmpdate->leftdate = userdate($slot[0],
                                get_string('strftimedatetime', 'langconfig'));
                        $tmpdate->righttdate = userdate($slot[1],
                                get_string('strftimetime', 'langconfig'));
                        $val .= get_string('leftandrightdate', 'booking', $tmpdate) . '<br>';
                    }
                }
            }

            $params->times = $val;
        }

        return $params;
    }

    /**
     * Generate the email body based on the activity settings and the booking parameters
     *
     * @param object $booking the booking activity object
     * @param string $fieldname the name of the field that contains the custom text
     * @param object $params the booking details
     * @return string
     */
    public function get_body($booking, $fieldname, $params, $urlencode = false) {
        $text = $booking->$fieldname;
        foreach ($params as $name => $value) {
            if ($urlencode) {
                $text = str_replace('{' . $name . '}', urlencode($value), $text);
            } else {
                $text = str_replace('{' . $name . '}', $value, $text);
            }
        }
        return $text;
    }
}