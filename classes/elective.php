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

use cache;
use core\event\course_completed;
use html_writer;
use mod_booking\booking_utils;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Deal with elective
 * @package mod_booking
 * @copyright 2021 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class elective {
    /** @var booking object  */
    public $booking = null;

    /**
     * Deals with all elective stuff
     *
     */
    public function __construct() {
    }

    /**
     * Add form fields to passed on mform.
     * This is used for the mod_form instance settings form.
     *
     * @param MoodleQuickForm $mform reference to the Moodle form
     * @return void
     */
    public function instance_form_definition(MoodleQuickForm &$mform) {

        // Elective.
        $mform->addElement(
            'header',
            'electivesettings',
            get_string('electivesettings', 'booking')
        );
        $mform->setExpanded('electivesettings', false);

        $mform->addElement('checkbox', 'iselective', get_string('iselective', 'booking'));

        $mform->addElement('checkbox', 'enforceorder', get_string('enforceorder', 'booking'));
        $mform->addHelpButton('enforceorder', 'enforceorder', 'mod_booking');

        $mform->addElement('advcheckbox', 'enforceteacherorder', get_string('enforceteacherorder', 'booking'));
        $mform->addHelpButton('enforceteacherorder', 'enforceteacherorder', 'mod_booking');

        $mform->addElement('checkbox', 'consumeatonce', get_string('consumeatonce', 'booking'));
        $mform->addHelpButton('consumeatonce', 'consumeatonce', 'mod_booking');

        $opts = [0 => get_string('unlimitedcredits', 'mod_booking')];
        $extraopts = array_combine(range(1, 50), range(1, 50));
        $opts = $opts + $extraopts;
        $extraopts = array_combine(range(55, 500, 5), range(55, 500, 5));
        $opts = $opts + $extraopts;
        $mform->addElement('select', 'maxcredits', get_string('maxcredits', 'mod_booking'), $opts);
        $mform->addHelpButton('maxcredits', 'maxcredits', 'mod_booking');

        // Only if the Instance is used as elective, we show these settings.
        $mform->disabledIf('enforceorder', 'iselective', 'notchecked');
        $mform->disabledIf('maxcredits', 'iselective', 'notchecked');
        $mform->disabledIf('enforceteacherorder', 'iselective', 'notchecked');
        $mform->disabledIf('consumeatonce', 'iselective', 'notchecked');
        $mform->disabledIf('consumeatonce', 'maxcredits', 'eq', 0);
    }

    /**
     * Add form fields to passed on mform.
     * This is used for the option_form form.
     *
     * @param MoodleQuickForm $mform reference to the Moodle form
     * @param array $customdata
     * @return void
     */
    public static function instance_option_form_definition(MoodleQuickForm &$mform, array $customdata) {

        global $DB;

        if (!empty($customdata['bookingid'])) {
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($customdata['bookingid']);
            if (empty($bookingsettings->iselective)) {
                return;
            }
        } else if (!empty($customdata['optionid'])) {
            $settings = singleton_service::get_instance_of_booking_option_settings($customdata['optionid']);
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
            if (empty($bookingsettings->iselective)) {
                return;
            }
        }

        // Elective.
        $mform->addElement('header', 'electiveoptions', get_string('electivesettings', 'booking'));
        $mform->setExpanded('electiveoptions', true);

        $opts = array_combine(range(0, 50), range(0, 50));
        $extraopts = array_combine(range(55, 500, 5), range(55, 500, 5));
        $opts = $opts + $extraopts;
        $mform->addElement('select', 'credits', get_string('credits', 'mod_booking'), $opts);
        $mform->addHelpButton('credits', 'credits', 'mod_booking');

        $options = [
                'multiple' => true,
                'noselectionstring' => get_string('nooptionselected', 'booking'),
        ];

        // Retrieve all the other Booking options.
        $alloptions = $DB->get_records('booking_options', ['bookingid' => $customdata['bookingid']]);
        $optionsarray = [];

        $optionid = $customdata['optionid'];

        foreach ($alloptions as $key => $optionobject) {
            if ($optionid == $key) {
                continue;
            }
            $optionsarray[$key] = $optionobject->text;
        }

        $mform->addElement('autocomplete', 'mustcombine', get_string("mustcombine", "booking"), $optionsarray, $options);
        $mform->addHelpButton('mustcombine', 'mustcombine', 'mod_booking');
        $mform->addElement('autocomplete', 'mustnotcombine', get_string("mustnotcombine", "booking"), $optionsarray, $options);
        $mform->addHelpButton('mustnotcombine', 'mustnotcombine', 'mod_booking');

        $mform->addElement('text', 'sortorder', get_string('electiveforcesortorder', 'booking'));
        $mform->setType('sortorder', PARAM_INT);
    }

    /**
     * Function to set values.
     *
     * @param stdClass $defaultvalues
     * @return void
     */
    public static function option_form_set_data(stdClass &$defaultvalues) {

        if (!empty($defaultvalues->optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($defaultvalues->optionid);

            $defaultvalues->mustcombine = $settings->electivecombinations ?
                implode(',', $settings->electivecombinations['mustcombine']) : '';
            $defaultvalues->mustnotcombine = $settings->electivecombinations ?
                implode(',', $settings->electivecombinations['mustnotcombine']) : '';
        }
    }

    /**
     * Validate data from form.
     *
     * @param MoodleQuickForm $mform reference to the Moodle form
     * @return void
     */
    public function instance_form_validation(MoodleQuickForm &$mform) {
    }


    /**
     * Process data from form.
     *
     * @param MoodleQuickForm $mform reference to the Moodle form
     * @return void
     */
    public function instance_form_save(MoodleQuickForm &$mform) {
    }



    /**
     * Called from lib.php to add autocomplete array to DB.
     * Deals with mustcombine and can't combine
     * 0 is can't combine, 1 is must combine,
     * @param int $optionid
     * @param mixed $otheroptions
     * @param bool $mustcombine
     * @throws \dml_exception
     */
    public static function addcombinations($optionid, $otheroptions, $mustcombine) {

        global $DB;
        // First we need to see if there are already entries in DB.
        $existingrecords = $DB->get_records('booking_combinations', ['optionid' => $optionid, 'cancombine' => $mustcombine]);

        // Run through the array of selected options and save them to db.
        foreach ($otheroptions as $otheroptionid) {
            // Check if the record exists already.
            if ($id = self::otheroptionidexists($existingrecords, $otheroptionid, $mustcombine)) {
                    // Mark record as existing.
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
                $DB->delete_records('booking_combinations', ['id' => $item->id]);

                // Also delete the pair.
                $DB->delete_records(
                    'booking_combinations',
                    ['optionid' => $item->otheroptionid,
                                        'otheroptionid' => $item->optionid,
                                        'cancombine' => $mustcombine,
                                        ]
                );
            }
        }
    }

    /**
     * Get combine array.
     *
     * @param int $optionid
     * @param bool $mustcombine
     * @return array
     * @throws \dml_exception
     */
    public static function get_combine_array($optionid, $mustcombine) {
        global $DB;
        return $DB->get_fieldset_select(
            'booking_combinations',
            'otheroptionid',
            "optionid = {$optionid} AND cancombine = {$mustcombine}"
        );
    }

    /**
     * Function to run through all the other booked options a user has in this Instance.
     * If one of the linked courses before this option is uncompleted, function will return false, else true.
     * @param mixed $bookingoption
     * @param int $userid
     * @return false
     * @throws \dml_exception
     */
    public static function check_if_allowed_to_inscribe($bookingoption, $userid) {

        global $DB;
        // Todo: Get all other options in this instance and check if user has completed them.

        // First, get all booked options from this instance and user.

        $sql = "SELECT ba.id, ba.userid, ba.optionid, ba.bookingid, bo.courseid
                FROM {booking_answers} ba
                JOIN {booking_options} bo
                ON ba.optionid=bo.id
                WHERE ba.bookingid=:bookingid
                AND ba.waitinglist=:waitinglist
                AND ba.userid=:userid
                ORDER BY ba.id ASC";

        $answers = $DB->get_records_sql(
            $sql,
            ['bookingid' => $bookingoption->booking->id,
                                        'userid' => $userid,
                                        'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]
        );

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

    /**
     * Show credits message
     *
     * @param mixed $booking
     *
     * @return string
     *
     */
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
        $outdata->creditsleft = self::return_credits_left($booking);
        $outdata->maxcredits = $booking->settings->maxcredits;

        $warning .= \html_writer::tag(
            'div',
            get_string('creditsmessage', 'mod_booking', $outdata),
            ['class' => 'alert alert-warning']
        );

        if (
            $booking->settings->consumeatonce
            && $outdata->creditsleft > 0
        ) {
            $warning .= \html_writer::tag(
                'div',
                get_string('consumeatonce', 'mod_booking', $outdata),
                ['class' => 'alert alert-warning']
            );
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
     * @param mixed $booking
     * @return int the number of credits left
     */
    public static function return_credits_left($booking) {

        global $DB, $USER;

        $sql = "SELECT bo.id, bo.credits
        FROM {booking_answers} ba
        INNER JOIN {booking_options} bo
        ON ba.optionid = bo.id
        WHERE ba.userid = $USER->id
        AND bo.bookingid = $booking->id
        AND ba.waitinglist =:bookingstatus";

        $params = [
            'bookingstatus' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ];

        $data = $DB->get_records_sql($sql, $params);
        $credits = 0;

        foreach ($data as $item) {
            $credits += +$item->credits;
        }

        $credits += self::return_credits_selected($booking);

        $credits = $booking->maxcredits - $credits;

        return $credits;
    }

    /**
     * Helper function to count the sum of all currently selected electives.
     * @param mixed $booking the current bookinginstance
     * @return numeric the sum of credits of all currently selected electives
     */
    public static function return_credits_selected($booking) {
        global $DB;

        if (
            !isset($_GET['list'])
                || (!$electivesarray = json_decode($_GET['list']))
        ) {
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
     * @param array $array
     * @param int $optionid
     * @param bool $mustcombine
     * @return false
     */
    private static function otheroptionidexists($array, $optionid, $mustcombine) {
        if ($optionid && $optionid !== 0) {
            foreach ($array as $item) {
                if (
                    $item->otheroptionid == $optionid
                        && $item->cancombine == $mustcombine
                ) {
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
            // Might happen for templates.
            if (empty($bookingid)) {
                continue;
            }
            $booking = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
            $boption = singleton_service::get_instance_of_booking_option($booking->cmid, $optionid);

            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* $iselective = $booking->settings->iselective; TODO: delete this? */
            $enforceorder = !empty($booking->enforceorder) || !empty($booking->enforceteacherorder) ? 1 : 0;

            // Get all booked users of the relevant booking options.
            $bookedusers = $boption->get_all_users_booked();
            // Enrol all users to the course.
            foreach ($bookedusers as $bookeduser) {
                // Todo: If enforceorder is active for this instance, check completion status of previous booked options.

                if (
                    !empty($booking->iselective)
                    && $enforceorder == 1
                ) {
                    if (!self::check_if_allowed_to_inscribe($boption, $bookeduser->userid)) {
                        continue;
                    }
                }

                $boption->enrol_user($bookeduser->userid);
                // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                /* mtrace("The user with the id {$bookeduser->id} has been enrolled
                to the course {$boption->option->courseid}."); */
            }
        }

        // If it's an elective, we can't set enrolmentstatus to 1, because we need to run check again and again.
        if (!empty($boids) && empty($booking->iselective)) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($boids));
            $DB->set_field_select('booking_options', 'enrolmentstatus', '1', 'id ' . $insql, $params);
        }
    }

    /**
     * Check if booking is alloowed in this combination
     *
     * @param booking_option_settings $settings
     * @return bool
     */
    public static function is_bookable(booking_option_settings $settings): bool {

        global $DB;

        if (empty($settings->electivecombinations['mustnotcombine'])) {
            return true;
        }

        [$inorequal, $params] = $DB->get_in_or_equal($settings->electivecombinations['mustnotcombine'], SQL_PARAMS_NAMED);

        $params['reserved'] = MOD_BOOKING_STATUSPARAM_RESERVED;

        $sql = "SELECT *
                FROM {booking_answers} ba
                WHERE ba.optionid " . $inorequal .
                "AND ba.waitinglist=:reserved";

        $conflicts = $DB->get_records_sql($sql, $params);

        if (count($conflicts) > 0) {
            return false;
        }

        return true;
    }

    /**
     * Return an array of allowed and not allowed combinations.
     *
     * @param int $optionid
     * @return array
     */
    public static function load_combinations(int $optionid) {

        global $DB;

        $sql = "SELECT *
                FROM {booking_combinations}
                WHERE optionid=:optionid1
                OR otheroptionid=:optionid2";

        $params = [
            'optionid1' => $optionid,
            'optionid2' => $optionid,
        ];

        $records = $DB->get_records_sql($sql, $params);

        $returnarrray['mustcombine'] = [];
        $returnarrray['mustnotcombine'] = [];

        foreach ($records as $record) {
            // We always need to get the other id.
            $otherid = $record->optionid == $optionid ?
                $record->otheroptionid : $record->optionid;
            if (!empty($record->cancombine)) {
                $returnarrray['mustcombine'][$otherid] = $otherid;
            } else {
                $returnarrray['mustnotcombine'][$otherid] = $otherid;
            }
        }

        return $returnarrray;
    }

    /**
     * Checks currently selected booking options if they can be combined.
     *
     * @param booking_settings $booking
     * @return bool
     */
    public static function is_bookable_combination(booking_settings $booking): bool {

        $arrayofoptions = self::get_options_from_cache($booking->cmid);

        $cancombine = true;
        foreach ($arrayofoptions as $option) {
            foreach ($option->electivecombinations['mustcombine'] as $optionid) {
                if (!isset($arrayofoptions[$optionid])) {
                    $cancombine = false;
                }
            }

            foreach ($option->electivecombinations['mustnotcombine'] as $optionid) {
                if (isset($arrayofoptions[$optionid])) {
                    $cancombine = false;
                }
            }
        }

        return $cancombine;
    }


    /**
     * Get sorted array of options from cache.
     *
     * @param int $cmid
     * @return array
     */
    public static function return_sorted_array_of_options_from_cache(int $cmid): array {

        $sortarray = self::get_options_from_cache($cmid);

        usort($sortarray, function ($a, $b) {
            if ($a->sortorder == $b->sortorder) {
                return 0;
            }

            return $a->sortorder < $b->sortorder ? -1 : 1;
        });

        $arrayofoptions = array_map(fn($x) => $x->id, $sortarray);

        return $arrayofoptions;
    }

    /**
     * Get array of ints from cache and instantiate them.
     *
     * @param int $cmid
     * @return array
     */
    public static function get_options_from_cache(int $cmid): array {

        $cache = cache::make('mod_booking', 'electivebookingorder');
        // We use itemid as cmid.
        $cachearray = $cache->get($cmid);

        // Unfortunately, we don't have the right ids at this moment. We really need to get all the options.

        if (!$cachearray) {
            return [];
        }

        $arrayofoptions = [];
        foreach ($cachearray['arrayofoptions'] as $optionid) {
            $sortoption = singleton_service::get_instance_of_booking_option_settings($optionid);
            $arrayofoptions[$sortoption->id] = $sortoption;
        }

        return $arrayofoptions;
    }
}
