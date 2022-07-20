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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking;
use mod_booking\booking_utils;
use mod_booking\booking_option;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursepage_available_options implements renderable, templatable {

    /** @var string $bookinginstancename  */
    public $bookinginstancename = '';

    /** @var array */
    public $bookingoptions = [];

    /** @var null booking_utils instance*/
    public $bu = null;

    /**
     * Constructor to prepare the data for courspage booking options list
     *
     * @param \stdClass $data
     */
    public function __construct($cm) {

        global $DB, $USER, $CFG;

        $booking = new booking($cm->id);
        $this->bu = new booking_utils();
        $bookingid = $booking->id;
        $fields = "SELECT DISTINCT bo.id,
                         bo.titleprefix,
                         bo.text,
                         bo.address,
                         bo.description,
                         bo.coursestarttime,
                         bo.courseendtime,
                         bo.limitanswers,
                         bo.maxanswers,
                         bo.maxoverbooking,
                         bo.credits,
                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 0) AS booked,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 1) AS waiting,
                         bo.location,
                         bo.institution,

                  (SELECT bo.maxanswers - (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 0)) AS availableplaces,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid) AS iambooked,
                         b.allowupdate,
                         b.allowupdatedays,
                         bo.bookingclosingtime,
                         b.btncancelname,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.completed = 1
                     AND ba.userid = :userid1) AS completed,

                  (SELECT status
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.status > 0
                     AND ba.userid = :userid2) AS status,

                  (SELECT DISTINCT(ba.waitinglist)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid3) AS waitinglist,
                         b.btnbooknowname,
                         b.maxperuser,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                    LEFT JOIN
                        {booking_options} bo ON bo.id = ba.optionid
                   WHERE ba.bookingid = b.id
                     AND ba.userid = :userid4
                    AND (bo.courseendtime = 0
                    OR bo.courseendtime > :timestampnow)) AS bookinggetuserbookingcount,
                         b.cancancelbook,
                         bo.disablebookingusers,

                  (SELECT COUNT(*)
                   FROM {booking_teachers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid5) AS isteacher,

                  (SELECT AVG(rate)
                   FROM {booking_ratings} br
                  WHERE br.optionid = bo.id) AS rating,

                  (SELECT COUNT(*)
                   FROM {booking_ratings} br
                  WHERE br.optionid = bo.id) AS ratingcount,

                  (SELECT rate
                  FROM {booking_ratings} br
                  WHERE br.optionid = bo.id
                    AND br.userid = :userid6) AS myrating
                ";
        $from = "FROM {booking} b LEFT JOIN {booking_options} bo ON bo.bookingid = b.id
        ";
        $where = "WHERE b.id = :bookingid
        ";

        // We only show active on coursepage.
        $where .= "AND (bo.courseendtime > :time OR bo.courseendtime = 0)";

        $now = time();

        $records = $DB->get_records_sql($fields . $from . $where, array(
                'bookingid' => $cm->instance,
                'userid' => $USER->id,
                'userid1' => $USER->id,
                'userid2' => $USER->id,
                'userid3' => $USER->id,
                'userid4' => $USER->id,
                'userid5' => $USER->id,
                'userid6' => $USER->id,
                'timestampnow' => $now,
                'time' => $now));

        // We get all sessions for this instance right away for better performance.
        $sessions = $DB->get_records('booking_optiondates', array('bookingid' => $bookingid));

        // Prepare running through all records.
        $context = $booking->get_context();
        $utils = new booking_utils();

        $baseurl = $CFG->wwwroot;

        // Run through all the bookingoptions for this instance.
        foreach ($records as $record) {

            if (!$record->id) {
                continue;
            }

            $dates = [];

            // First we look if there are sessions for this option id.
            foreach ($sessions as $session) {
                if ($session->optionid == $record->id) {
                    $datestring = $this->bu->return_string_from_dates($session->coursestarttime, $session->courseendtime);
                    $dates[] = ['datestring' => $datestring];
                }
            }

            // If there were no sessions to be found, we take the normal option string.
            if (count($dates) == 0) {
                $datestring = $this->bu->return_string_from_dates($record->coursestarttime, $record->courseendtime);
                $dates[] = ['datestring' => $datestring];
            }

            $button = $utils->return_button_based_on_record($booking, $context, $record, true);

            $urlparams = array('id' => $booking->cm->id, 'action' => 'showonlyone',
                    'whichview' => 'showonlyone',
                    'optionid' => $record->id);
            $url = new \moodle_url($baseurl . '/mod/booking/view.php', $urlparams);
            $linkonoption = $url->out(false);

            booking_option::transform_unique_bookingoption_name_to_display_name($record);

            $this->bookingoptions[] = [
                    'bookingoptionname' => $record->text,
                    'titleprefix' => $record->titleprefix,
                    'dates' => $dates,
                    'button' => $button,
                    'linktooption' => $linkonoption
            ];
        }
        $this->bookinginstancename = $booking->settings->name;
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return array(
                'bookinginstancename' => $this->bookinginstancename,
                'bookingoptions' => $this->bookingoptions
        );
    }
}
