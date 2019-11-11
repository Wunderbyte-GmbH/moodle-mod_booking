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
namespace mod_booking\output;

defined('MOODLE_INTERNAL') || die();

use context_module;
use context;
use mod_booking\booking;
use mod_booking\booking_option;
use stdClass;

require_once($CFG->dirroot . '/mod/booking/locallib.php');

/**
 * Mobile output class for booking
 *
 * @package mod_booking
 * @copyright 2018 Andraž Prinčič, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {

    /**
     * Returns all my bookings view for mobile app.
     *
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function mobile_mybookings_list($args) {
        global $OUTPUT, $USER, $DB;

        $mybookings = $DB->get_records_sql(
        "SELECT ba.id id, c.id courseid, c.fullname fullname, b.id bookingid, b.name name, bo.text text, bo.id optionid,
        bo.coursestarttime coursestarttime, bo.courseendtime courseendtime, cm.id cmid
        FROM
        {booking_answers} ba
        LEFT JOIN
    {booking_options} bo ON ba.optionid = bo.id
        LEFT JOIN
    {booking} b ON b.id = bo.bookingid
        LEFT JOIN
    {course} c ON c.id = b.course
        LEFT JOIN
        {course_modules} cm ON cm.module = (SELECT
                id
            FROM
                {modules}
            WHERE
                name = 'booking')
            WHERE instance = b.id AND ba.userid = {$USER->id} AND cm.visible = 1");

        $outputdata = array();

        foreach ($mybookings as $key => $value) {
            $status = '';
            $coursestarttime = '';

            if ($value->coursestarttime > 0) {
                $coursestarttime = userdate($value->coursestarttime);
            }
            $status = booking_getoptionstatus($value->coursestarttime, $value->courseendtime);

            $outputdata[] = array(
                'fullname' => $value->fullname,
                'name' => $value->name,
                'text' => $value->text,
                'status' => $status,
                'coursestarttime' => $coursestarttime
            );
        }

        $data = array('mybookings' => $outputdata);

        return array(
            'templates' => array(
                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_booking/mobile_mybookings_list', $data)
                )
            ),
            'javascript' => '',
            'otherdata' => ''
        );
    }

    /**
     * Returns the booking course view for the mobile app.
     *
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function mobile_course_view($args) {
        global $OUTPUT, $USER, $DB, $COURSE;

        $bcolorshowall = 'light';
        $bcolorshowactive = 'light';
        $bcolormybooking = 'light';

        $args = (object) $args;
        $cm = get_coursemodule_from_id('booking', $args->cmid);
        $allpages = 0;
        $pagnumber = 0;

        if (isset($args->whichview)) {
            $whichview = $args->whichview;
        }

        if (isset($args->pagnumber)) {
            $pagnumber = $args->pagnumber;
        }

        $searchstring = empty($args->searchstring) ? '' : $args->searchstring;

        // Capabilities check.
        require_login($args->courseid, false, $cm, true, true);

        $context = context_module::instance($cm->id);

        $booking = new booking($cm->id);

        $paging = $booking->settings->paginationnum;
        if (!isset($whichview)) {
            $whichview = $booking->settings->whichview;
        }

        if ($paging == 0) {
            $paging = 25;
        }

        switch ($whichview) {
            case 'showall':
                $bookingoptions = $booking->get_all_options($pagnumber * $paging, $paging, $searchstring);
                $allpages = floor($booking->get_all_options_count($searchstring) / $paging);
                $bcolorshowall = '';
                break;

            case 'showactive':
                $bookingoptions = $booking->get_active_optionids($booking->id, $pagnumber * $paging,
                $paging, $searchstring);
                $allpages = floor($booking->get_active_optionids_count($booking->id, $searchstring) / $paging);
                $bcolorshowactive = '';
                break;

            case 'mybooking':
                $bookingoptions = $booking->get_my_bookingids($pagnumber * $paging, $paging, $searchstring);
                $allpages = floor($booking->get_my_bookingids_count($searchstring) / $paging);
                $bcolormybooking = '';
                break;
        }

        $options = self::prepare_options_array($bookingoptions, $booking, $context, $cm, $args->courseid);

        $data = array(
            'pagnumber' => $pagnumber, 'courseid' => $args->courseid, 'booking' => $booking,
                        'booking_option' => $options, 'cmid' => $cm->id, 'activeview' => $whichview,
            'string' => array(
                'showactive' => get_string('showactive', 'booking'),
                'showallbookings' => get_string('showallbookings', 'booking'),
                'showmybookingsonly' => get_string('showmybookingsonly', 'booking'),
                'next' => get_string('next', 'booking'),
                'previous' => get_string('previous', 'booking')
            ), 'btnnp' => self::npbuttons($allpages, $pagnumber), 'bcolorshowall' => $bcolorshowall,
            'bcolorshowactive' => $bcolorshowactive, 'bcolormybooking' => $bcolormybooking
        );
        return array(

            'templates' => array(

                array(
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_booking/mobile_view_page', $data)
                )
            ), 'javascript' => '', 'otherdata' => array('searchstring' => $searchstring)

        );
    }

    /**
     * TODO: What does it do?
     * @param $allpages
     * @param $pagnumber
     * @return array
     */
    public static function npbuttons($allpages, $pagnumber) {
        $p = 0;
        $n = 0;

        if ($pagnumber > 0) {
            $p = $pagnumber - 1;
        }

        if ($pagnumber < $allpages) {
            $n = $pagnumber + 1;
        }

        return array(
            'p' => $p, 'n' => $n
        );
    }

    /**
     * TODO: What does it do?
     *
     * @param $bookingoptions
     * @param booking $booking
     * @param context $context
     * @param stdClass $cm
     * @param $courseid
     * @return array
     * @throws \coding_exception
     */
    public static function prepare_options_array($bookingoptions, booking $booking, context $context, stdClass $cm, $courseid) {
        $options = array();

        foreach ($bookingoptions as $key => $value) {
            $option = new booking_option($cm->id,
                    (is_object($value) ? $value->id : $value));
            $option->get_teachers();
            $options[] = self::prepare_options($option, $booking, $context, $cm, $courseid);
        }

        return $options;
    }

    /**
     * Prepare booking options for output on mobile.
     *
     * @param $values
     * @param booking $booking
     * @param context $context
     * @param stdClass $cm
     * @param $courseid
     * @return array
     * @throws \coding_exception
     */
    public static function prepare_options($values, booking $booking, context $context, stdClass $cm, $courseid) {
        global $USER;

        $text = '';

        if (strlen($values->option->address) > 0) {
            $text .= $values->option->address . "<br>";
        }

        if (strlen($values->option->location) > 0) {
            $text .= (empty($booking->settings->lbllocation) ? get_string('location', 'booking') : $booking->settings->lbllocation) . ': ' . $values->option->location . "<br>";
        }
        if (strlen($values->option->institution) > 0) {
            $text .= (empty($booking->settings->lblinstitution) ? get_string('institution', 'booking') : $booking->settings->lblinstitution) . ': ' .
            $values->option->institution . "<br>";
        }

        if (!empty($values->option->description)) {
            $text .= format_text($values->option->description);
        }

        $teachers = array();
        foreach ($values->teachers as $tvalue) {
            $teachers[] = "{$tvalue->firstname} {$tvalue->lastname}";
        }

        if ($values->option->coursestarttime != 0 && $values->option->courseendtime != 0) {
            $text .= userdate($values->option->coursestarttime) . " - " . userdate(
                    $values->option->courseendtime);
        }

        $text .= (!empty($values->teachers) ? "<br>" . (empty($booking->settings->lblteachname) ? get_string(
                'teachers', 'booking') : $booking->settings->lblteachname) . ": " . implode(', ',
                $teachers) : '');

        $delete = array();
        $status = '';
        $button = array();
        $booked = '';
        $inpast = $values->option->courseendtime && ($values->option->courseendtime < time());

        $underlimit = ($booking->settings->maxperuser == 0);
        $underlimit = $underlimit || ($values->option->bookinggetuserbookingcount < $values->option->maxperuser);

        if (!$values->option->limitanswers) {
            $status = "available";
        } else if (($values->waiting + $values->booked) >= ($values->option->maxanswers + $values->option->maxoverbooking)) {
            // TO-DO: Seštej, koliko jih je na listi.
            $status = "full";
        }

        if (time() > $values->option->bookingclosingtime && $values->option->bookingclosingtime != 0) {
            $status = "closed";
        }

        // I'm booked or not.
        if ($values->iambooked) {
            if ($booking->settings->allowupdate and $status != 'closed' and $values->completed != 1) {
                // TO-DO: Naredi gumb za izpis iz opcije.
                $deletemessage = format_string($values->option->text);

                if ($values->option->coursestarttime != 0) {
                    $deletemessage .= "<br />" . userdate($values->option->coursestarttime,
                            get_string('strftimedatetime')) . " - " . userdate(
                            $values->option->courseendtime, get_string('strftimedatetime'));
                }

                $cmessage = get_string('deletebooking', 'booking', $deletemessage);
                $bname = (empty($values->option->btncancelname) ? get_string('cancelbooking',
                        'booking') : $values->option->btncancelname);
                $delete = array(
                    'text' => $bname,
                                'args' => "optionid: {$values->option->id}, cmid: {$cm->id}, courseid: {$courseid}",
                    'cmessage' => "{$cmessage}"
                );

                if ($values->option->coursestarttime > 0 && $values->booking->allowupdatedays > 0) {
                    if (time() > strtotime("-{$values->booking->allowupdatedays} day", $values->option->coursestarttime)) {
                        $delete = array();
                    }
                }
            }

            if ($values->onwaitinglist) {
                $text .= '<br><ion-chip><ion-label>' . get_string('onwaitinglist', 'booking') . '</ion-label></ion-chip>';
            } else if ($inpast) {
                $text .= '<br><ion-chip><ion-label>' . get_string('bookedpast', 'booking') . '</ion-label></ion-chip>';
            } else {
                $text .= '<br><ion-chip><ion-label>' . get_string('booked', 'booking') . '</ion-label></ion-chip>';
            }
        } else {
            $message = $values->option->text;
            if ($values->option->coursestarttime != 0) {
                $message .= "<br>" . userdate($values->option->coursestarttime,
                        get_string('strftimedatetime')) . " - " . userdate(
                        $values->option->courseendtime, get_string('strftimedatetime'));
            }
            $message .= '<br><br>' . get_string('confirmbookingoffollowing', 'booking');
            if (!empty($booking->settings->bookingpolicy)) {
                $message .= "<br><br>" . get_string('agreetobookingpolicy', 'booking');
                $message .= "<br>" . format_text($booking->settings->bookingpolicy, FORMAT_HTML);
            }
            $bnow = (empty($booking->settings->btnbooknowname) ? get_string('booknow', 'booking') : $booking->settings->btnbooknowname);
            $button = array(
                'text' => $bnow,
                            'args' => "answer: {$values->option->id}, id: {$cm->id}, courseid: {$courseid}",
                'message' => $message
            );
        }

        if (($values->option->limitanswers && ($status == "full")) || ($status == "closed") || !$underlimit || $values->option->disablebookingusers) {
            $button = array();
        }

        if ($booking->settings->cancancelbook == 0 && $values->option->courseendtime > 0 && $values->option->courseendtime < time()) {
            $button = array();
            $delete = array();
        }

        if (!empty($booking->settings->banusernames)) {
            $disabledusernames = explode(',', $booking->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $button = array();
                }
            }
        }

        if ($values->option->limitanswers) {
            $places = new places($values->option->maxanswers,
                    $values->option->maxanswers - $values->booked, $values->option->maxoverbooking,
                    $values->option->maxoverbooking - $values->waiting);
        }

        return array(
            'name' => $values->option->text, 'text' => $text, 'button' => $button,
            'delete' => $delete
        );
    }
}