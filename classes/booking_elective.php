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
use core\event\course_completed;
use mod_booking\booking_utils;
use stdClass;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/calendar/lib.php');

/**
 * Deal with elective
 * @package mod_booking
 * @copyright 2021 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_elective {

    /** @var booking object  */
    public $booking = null;

    /**
     * Deals with all elective stuff
     *
     */
    public function __construct() {

    }

    /**
     * Called from lib.php to add autocomplete array to DB.
     * Deals with mustcombine and can't combine
     * 0 is can't combine, 1 is must combine,
     * @param $optionid
     * @param $otheroptions
     * @param $mustcombine
     * @throws \dml_exception
     */
    public static function addcombinations($optionid, $otheroptions, $mustcombine) {

        global $DB;
        // First we need to see if there are already entries in DB.
        $existingrecords = $DB->get_records('booking_combinations', array('optionid' => $optionid, 'cancombine' => $mustcombine));

        // Run through the array of selected options and save them to db.
        foreach ($otheroptions as $otheroptionid) {

            // Check if the record exists already.
            if ($id = self::otheroptionidexists($existingrecords, $otheroptionid, $mustcombine)) {
                    // Mark record as existing
                    $existingrecords[$id]->exists = true;
                continue;
            }
            // If we haven't found the record, we insert an entry.
            $newbookingentry = new stdClass();
            $newbookingentry->optionid = $optionid;
            $newbookingentry->otheroptionid = $otheroptionid;
            $newbookingentry->othercourseid = null;
            $newbookingentry->cancombine = $mustcombine;

            $DB->insert_record('booking_combinations', $newbookingentry);

            $newbookingentry->optionid = $otheroptionid;
            $newbookingentry->otheroptionid = $optionid;

            $DB->insert_record('booking_combinations', $newbookingentry);
        }

        // Finally, we run through the existing records and see which were not in the array.
        // We have to delete these.

        foreach ($existingrecords as $item) {
            if (!property_exists($item, 'exists')) {
                $DB->delete_records('booking_combinations', array('id' => $item->id));

                // Also delete the pair
                $DB->delete_records('booking_combinations', array('optionid' => $item->otheroptionid, 'otheroptionid' => $item->optionid, 'cancombine' => $mustcombine));
            }
        }
    }

    /**
     * @param $optionid
     * @param $mustcombine
     * @return array
     * @throws \dml_exception
     */
    public static function get_combine_array($optionid, $mustcombine) {
        global $DB;
        return $DB->get_fieldset_select('booking_combinations', 'otheroptionid', "optionid = {$optionid} AND cancombine = {$mustcombine}");
    }

    /**
     * Add form fields to passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public static function add_elective_to_option_form(MoodleQuickForm &$mform, $cmid, $optionid) {
        global $DB;

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we always show everything.
        $showelectiveheader = true;
        $formmode = get_user_preferences('optionform_mode');
        if ($formmode !== 'expert') {
            $cfgelectiveheader = $DB->get_field('booking_optionformconfig', 'active',
                ['elementname' => 'electivesettings']);
            if ($cfgelectiveheader === "0") {
                $showelectiveheader = false;
            }
        }
        if ($showelectiveheader) {
            $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
            $mform->addElement('header', 'electiveoptions', get_string('electivesettings', 'booking'));
            $mform->setExpanded('electiveoptions', true);
            $opts = array_combine(range(0, 50), range(0, 50));
            $extraopts = array_combine(range(55, 500, 5), range(55, 500, 5));
            $opts = $opts + $extraopts;
            $mform->addElement('select', 'credits', get_string('credits', 'mod_booking'), $opts);
            $mform->addHelpButton('credits', 'credits', 'mod_booking');
    
            $options = array(
                    'multiple' => true,
                    'noselectionstring' => get_string('nooptionselected', 'booking'),
            );
    
            // Retrieve all the other Booking options.
            $alloptions = $DB->get_records('booking_options', array('bookingid' => $booking->id));
            $optionsarray = [];
            // $optionid = self::_customdata['optionid'];
    
            foreach ($alloptions as $key => $optionobject) {
                // Do not show self.
                if ($optionid == $key) {
                    continue;
                }
                $optionsarray[$key] = $optionobject->text;
            }
    
            $mform->addElement('autocomplete', 'mustcombine', get_string("mustcombine", "booking"), $optionsarray, $options);
            $mform->addHelpButton('mustcombine', 'mustcombine', 'mod_booking');
            $mform->addElement('autocomplete', 'mustnotcombine', get_string("mustnotcombine", "booking"), $optionsarray, $options);
            $mform->addHelpButton('mustnotcombine', 'mustnotcombine', 'mod_booking');
        }

    }

    /**
     * Function to run through all the other booked options a user has in this Instance.
     * If one of the linked courses before this option is uncompleted, function will return false, else true.
     * @param $bookingoption
     * @param $userid
     * @return false
     * @throws \dml_exception
     */
    public static function check_if_allowed_to_inscribe($bookingoption, $userid) {

        global $DB;
        // TODO: get all other options in this instance and check if user has completed them.

        // First, get all booked options from this instance and user

        $sql = "SELECT ba.id, ba.userid, ba.optionid, ba.bookingid, bo.courseid
                FROM {booking_answers} ba
                JOIN {booking_options} bo
                ON ba.optionid=bo.id
                WHERE ba.bookingid=:bookingid
                AND ba.userid=:userid
                ORDER BY ba.id ASC";

        $answers = $DB->get_records_sql($sql, array('bookingid' => $bookingoption->booking->id, 'userid' => $userid));

        // We run through the list of options.
        // The sorting order comes from the ids, lower was booked first.

        foreach ($answers as $answer) {


            // We run through our booked options already in the right order.
            // Either way, we have to check if this option has course attached and if the course is completed.
            // If not, we check if the id is right. if so, we inscribe.
            // If the id is not right, we stop going through answers and go to next booking option.
            // if course isn't there OR completed, we go to the next answer.

            // Get the completion status of this option.
            // First get the associated booking option.

            if ($courseid = $answer->courseid) {
                $coursecompletion = new \completion_completion(['userid' => $userid, 'course' => $courseid]);
            }
            if ($answer->optionid == $bookingoption->optionid) {
                if (!$courseid || ($coursecompletion && !$coursecompletion->is_complete())) {
                    return true;
                } else {
                    // If it's finished already, we don't need to inscribe again.
                    return false;
                }
            } else {
                if (!$courseid || ($coursecompletion && !$coursecompletion->is_complete())) {
                    return false;
                }
            }
        }
        // If in the end we didn't find an answer which would allow us to inscribe, we return false.
        return false;
    }

    public static function show_credits_message($booking) {
        global $USER;

        $warning = '';

        if (!empty($booking->settings->banusernames)) {
            $disabledusernames = explode(',', $booking->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $warning = html_writer::tag('p', get_string('banusernameswarning', 'mod_booking'));
                }
            }
        }

        if (!$booking->settings->maxcredits) {
            return $warning; // No credits maximum set.
        }

        $outdata = new stdClass();
        $outdata->creditsleft = booking_elective::return_credits_left($booking);
        $outdata->maxcredits = $booking->settings->maxcredits;

        $warning .= \html_writer::tag('div', get_string('creditsmessage', 'mod_booking', $outdata), array ('class' => 'alert alert-warning'));

        if ($booking->settings->consumeatonce
            && $outdata->creditsleft > 0) {
            $warning .= \html_writer::tag('div', get_string('consumeatonce', 'mod_booking', $outdata), array ('class' => 'alert alert-warning'));
        }

        return $warning;
    }

    /**
     * Helper function to return the sum of credits of already booked electives
     * @param stdClass $booking
     * @return int the sum of credits booked
     */
    public static function return_credits_booked($booking) {
        global $DB, $USER;

        $sql = "SELECT bo.id, bo.credits
        FROM {booking_answers} ba
        INNER JOIN {booking_options} bo
        ON ba.optionid = bo.id
        WHERE ba.userid = $USER->id
        AND bo.bookingid = $booking->id";

        $data = $DB->get_records_sql($sql);
        $credits = 0;

        foreach ($data as $item) {
            $credits += +$item->credits;
        }

        return $credits;
    }

    /**
     * Helper function to return the number of credits left after booking.
     * @param stdClass $booking
     * @return int the number of credits left
     */
    public static function return_credits_left($booking) {

        global $DB, $USER;

        $sql = "SELECT bo.id, bo.credits
        FROM {booking_answers} ba
        INNER JOIN {booking_options} bo
        ON ba.optionid = bo.id
        WHERE ba.userid = $USER->id
        AND bo.bookingid = $booking->id";

        $data = $DB->get_records_sql($sql);
        $credits = 0;

        foreach ($data as $item) {
            $credits += +$item->credits;
        }

        $credits += self::return_credits_selected($booking);

        $credits = +$booking->settings->maxcredits - $credits;

        return $credits;
    }

    /**
     * Helper function to count the sum of all currently selected electives.
     * @param stdClass $booking the current bookinginstance
     * @return numeric the sum of credits of all currently selected electives
     */
    public static function return_credits_selected($booking) {
        global $DB;

        if (!isset($_GET['list'])
                || (!$electivesarray = json_decode($_GET['list']))) {
            $listorder = '[]';
        } else {
            $listorder = $_GET['list'];
        }

        $electivesarray = json_decode($listorder);

        $credits = 0;
        foreach ($electivesarray as $selected) {
            if (!empty($selected)) {
                if (!$record = $DB->get_record('booking_options', ['id' => (int) $selected], 'credits')) {
                    return false;
                } else {
                    $credits += $record->credits;
                }
            }
        }
        return $credits;
    }

    /**
     * helperfunction to check entries from booking_combine table for match
     * @param $array
     * @param $optionid
     * @param $mustcombine
     * @return false
     */
    private static function otheroptionidexists($array, $optionid, $mustcombine) {
        if ($optionid && $optionid !== 0) {
            foreach ($array as $item) {
                if ($item->otheroptionid == $optionid
                        && $item->cancombine == $mustcombine ) {
                    return $item->id;
                }
            }
        }
        return false;
    }

    /**
     * Enrol users if course has started depending on enforceorder.
     * This function will be executed both by the course_completed event and the scheduled task enrol_bookedusers_tocourse.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function enrol_booked_users_to_course() {
        global $DB;
        // Get all booking options with associated Moodle courses that have enrolmentstatus 0 and coursestartdate in the past.
        $select = "enrolmentstatus < 1 AND coursestarttime < :now";
        $now = time();
        $boids = $DB->get_records_select_menu('booking_options', $select, ['now' => $now], '', 'id, bookingid');
        foreach ($boids as $optionid => $bookingid) {
            if ($bookingid) {
                $cm = get_coursemodule_from_instance('booking', $bookingid);
            } else {
                mtrace("WARNING: Failed to get booking instance from option id: $optionid");
            }
            $boption = new booking_option($cm->id, $optionid);

            // TODO: Make sure we don't enrol users who have not yet finished previous course.

            $booking = $boption->booking;
            // $iselective = $booking->settings->iselective; TODO: delete this?
            $enforceorder = $booking->settings->enforceorder;

            // Get all booked users of the relevant booking options.
            $bookedusers = $boption->get_all_users_booked();
            // Enrol all users to the course.
            foreach ($bookedusers as $bookeduser) {

                // Todo: If enforceorder is active for this instance, check completion status of previous booked options.

                if ($booking->is_elective()
                    && $enforceorder == 1) {
                    if (!booking_elective::check_if_allowed_to_inscribe($boption, $bookeduser->id)) {
                        continue;
                    }
                }

                $boption->enrol_user($bookeduser->userid);
                // mtrace("The user with the id {$bookeduser->id} has been enrolled to the course {$boption->option->courseid}.");
            }
        }

        // If it's an elective, we can't set enrolmentstatus to 1, because we need to run check again and again.
        if (!empty($boids) && !$booking->is_elective()) {
            list($insql, $params) = $DB->get_in_or_equal(array_keys($boids));
            $DB->set_field_select('booking_options', 'enrolmentstatus', '1', 'id ' . $insql, $params);
        }
    }
}
