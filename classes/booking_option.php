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
 * Managing a single booking option
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use cache_helper;
use coding_exception;
use completion_info;
use context_module;
use context_system;
use context;
use dml_exception;
use Exception;
use html_writer;
use invalid_parameter_exception;
use local_entities\entitiesrelation_handler;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\event\booking_rulesexecutionfailed;
use mod_booking\event\bookinganswer_waitingforconfirmation;
use mod_booking\option\dates_handler;
use mod_booking\bo_actions\actions_info;
use mod_booking\booking_rules\rules_info;
use stdClass;
use moodle_url;
use mod_booking\booking_utils;
use mod_booking\calendar;
use mod_booking\teachers_handler;
use mod_booking\customfield\booking_handler;
use mod_booking\event\booking_afteractionsfailed;
use mod_booking\event\bookinganswer_cancelled;
use mod_booking\event\bookingoption_freetobookagain;
use mod_booking\message_controller;
use mod_booking\option\fields\credits;
use mod_booking\option\fields_info;
use mod_booking\placeholders\placeholders_info;
use mod_booking\subbookings\subbookings_info;
use mod_booking\task\send_completion_mails;
use moodle_exception;
use MoodleQuickForm;

use function get_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to managing a single booking option
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option {

    /** @var ?int $cmid course module id */
    public ?int $cmid = null;

    /** @var ?int id of the booking option in table booking_options */
    public ?int $id = null;

    /** @var ?int id of the booking option in table booking_options */
    public ?int $optionid = null;

    /** @var ?int id of the booking instance */
    public $bookingid = null;

    /** @var ?booking object  */
    public ?booking $booking = null;

    /** @var array of the users booked for this option key userid */
    public array $bookedusers = [];

    /** @var array of booked users visible to the current user (group members) */
    public array $bookedvisibleusers = [];

    /** @var array of users subscribeable to booking option if groups enabled, members of groups user has access to */
    public array $potentialusers = [];

    /** @var ?stdClass option config object */
    public ?stdClass $option = null;

    /** @var array booking option teachers defined in booking_teachers table */
    public array $teachers = [];

    /** @var ?int number of answers */
    public ?int $numberofanswers = null;

    /** @var array of all user objects (waitinglist and regular) - filtered */
    public array $users = [];

    /** @var array filter and other url params */
    public array $urlparams;

    /** @var string $times course start time - course end time or session times separated with a comma */
    public string $optiontimes = '';

    /** @var bool if I'm booked */
    public $iambooked = 0;

    /** @var bool if I'm on waiting list */
    public $onwaitinglist = 0;

    /** @var bool if I completed? */
    public $completed = 0;

    /** @var int user on waiting list */
    public int $waiting = 0;

    /** @var int booked users */
    public int $booked = 0;

    /** @var ?booking_option_settings $settings */
    public ?booking_option_settings $settings = null;

    /** @var ?int Seconds */
    public ?int $secondstostart = null;

    /** @var ?int Seconds passed since start */
    public ?int $secondspassed = null;

    /**
     * Booking options should always be created via singleton service.
     * The only usage of this constructor should therefore be in singleton service.
     *
     * @param int $cmid
     * @param int $optionid
     */
    public function __construct(int $cmid, int $optionid) {

        $this->cmid = $cmid;

        $this->optionid = $optionid;
        $this->id = $optionid; // Store it in id AND in optionid.

        $this->settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        if (empty($this->settings->id)) {
            debugging('ERROR: Option settings could not be created. Most probably, the option was deleted from DB.',
                    DEBUG_DEVELOPER);
            return;
        }

        $this->bookingid = $this->booking->id;

        // In the future, we will get rid of the stdClass - right now, it's still used very often.
        $this->option = $this->settings->return_settings_as_stdclass();

        // Get cached sessions from booking settings class.
        if (!empty($this->settings->sessions)) {
            foreach ($this->settings->sessions as $time) {
                $this->optiontimes .= $time->coursestarttime . " - " . $time->courseendtime . ",";
            }
            trim($this->optiontimes, ",");
        } else {
            $this->optiontimes = '';
        }
    }

    /**
     * Returns a booking_option object when optionid is passed along.
     * Saves db query when booking id is given as well, but uses already cached settings.
     *
     * @param int $optionid
     * @param ?int $bookingid booking id
     *
     * @return booking_option
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function create_option_from_optionid(int $optionid, ?int $bookingid = null) {
        global $DB;

        if (empty($bookingid)) {
            if ($settings = singleton_service::get_instance_of_booking_option_settings($optionid)) {
                $bookingid = $settings->bookingid;
            } else {
                $bookingid = $DB->get_field('booking_options', 'bookingid', ['id' => $optionid]);
            }
        }

        // If we could not retrieve it, we have to return null.
        if (empty($bookingid)) {
            return null;
        }

        $cm = get_coursemodule_from_instance('booking', $bookingid);

        return singleton_service::get_instance_of_booking_option($cm->id, $optionid);
    }

    /**
     * This calculates number of user that can be booked to the connected booking option
     * Looks for max participant in the connected booking given the optionid
     *
     * @param int $optionid
     * @return int
     */
    public function calculate_how_many_can_book_to_other(int $optionid): int {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        if (isset($optionid) && $optionid > 0) {
            $alreadybooked = 0;

            $result = $DB->get_records_sql(
                    'SELECT answers.userid FROM {booking_answers} answers
                    INNER JOIN {booking_answers} parent on parent.userid = answers.userid
                    WHERE answers.optionid = ? AND parent.optionid = ?',
                    [$this->optionid, $optionid]);

            $alreadybooked = count($result);

            $keys = [];

            foreach ($result as $value) {
                $keys[] = $value->userid;
            }

            foreach ($this->get_all_users_on_waitinglist() as $user) {
                if (in_array($user->userid, $keys)) {
                    $user->bookedtootherbooking = 1;
                } else {
                    $user->bookedtootherbooking = 0;
                }
            }

            foreach ($this->get_all_users_booked() as $user) {
                if (in_array($user->userid, $keys)) {
                    $user->usersonlist = 1;
                } else {
                    $user->usersonlist = 0;
                }
            }

            $connectedbooking = $DB->get_record("booking",
                    ['conectedbooking' => $bookingsettings->id], 'id', IGNORE_MULTIPLE);

            if ($connectedbooking) {

                $nolimits = $DB->get_records_sql(
                        "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ?", [$connectedbooking->id]);

                if (!$nolimits) {
                    $howmanynum = $this->option->howmanyusers;
                } else {
                    $howmany = $DB->get_record_sql(
                            "SELECT userslimit FROM {booking_other} WHERE optionid = ? AND otheroptionid = ?",
                            [$optionid, $this->optionid]);

                    $howmanynum = 0;
                    if ($howmany) {
                        $howmanynum = $howmany->userslimit;
                    }
                }
            }

            if ($howmanynum == 0) {
                $howmanynum = 999999;
            }

            return (int) $howmanynum - (int) $alreadybooked;
        } else {
            return 0;
        }
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see booking::apply_tags()
     */
    public function apply_tags() {
        $this->booking->apply_tags();
        $tags = new booking_tags($this->booking->cm->course);
        $this->option = $tags->option_replace($this->option);
    }

    /**
     * Get url params
     *
     * @return void
     *
     */
    public function get_url_params() {
        $bu = new booking_utils();
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);
        $bookingsettings = $bookingsettings->return_settings_as_stdclass();
        $params = $bu->generate_params($bookingsettings, $this->option);
        $this->option->pollurl = $bu->get_body($params, 'pollurl', $params, true);
        $this->option->pollurlteachers = $bu->get_body($params, 'pollurlteachers', $params, true);
    }

    /**
     * Get teachers from booking_teachers if not set
     */
    public function get_teachers() {
        global $DB;
        if (empty($this->teachers)) {
            $this->teachers = $DB->get_records_sql(
                    'SELECT DISTINCT t.userid, u.firstname, u.lastname, u.email, u.institution
                            FROM {booking_teachers} t
                       LEFT JOIN {user} u ON t.userid = u.id
                           WHERE t.optionid = ' . $this->optionid . '');
        }
        return $this->teachers;
    }

    /**
     * Get all users (from booking answers object using singleton_service).
     *
     * @return array of objects
     * @throws dml_exception
     */
    public function get_all_users() {
        $bookinganswers = singleton_service::get_instance_of_booking_answers($this->settings);
        return $bookinganswers->users;
    }

    /**
     * Get all users on waitinglist as an array of objects.
     *
     * @return array users on waiting list as an array of objects
     */
    public function get_all_users_on_waitinglist(): array {
        $bookinganswers = singleton_service::get_instance_of_booking_answers($this->settings);
        return $bookinganswers->usersonwaitinglist;
    }

    /**
     * Get all users booked who booked (not on waiting list) as an array of objects
     *
     * @return array users who booked as an array of objects
     */
    public function get_all_users_booked() {
        $bookinganswers = singleton_service::get_instance_of_booking_answers($this->settings);
        return $bookinganswers->usersonlist;
    }

    /**
     * Return if user can rate.
     *
     * @return bool
     */
    public function can_rate() {
        global $USER;

        $bookinganswers = booking_answers::get_instance_from_optionid($this->optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        if ($bookingsettings->ratings == 0) {
            return false;
        }

        if ($bookingsettings->ratings == 1) {
            return true;
        }

        if ($bookingsettings->ratings == 2) {
            if (in_array($bookinganswers->user_status($USER->id),
                [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_WAITINGLIST])) {
                return true;
            } else {
                return false;
            }
        }

        if ($bookingsettings->ratings == 3) {
            if ($bookinganswers->is_activity_completed($USER->id)) {
                return true;
            } else {
                return false;
            }
        }

        return false;
    }

    /**
     * Get option text depending on status of users booking.
     *
     * @param booking_answers $bookinganswers
     * @param ?int $userid optional userid
     * @return string
     */
    public function get_text_depending_on_status(booking_answers $bookinganswers, ?int $userid = null) {
        global $USER, $PAGE;

        // Notice: For performance reasons, we stopped supporting placeholders here!

        // With shortcodes & webservice we might not have a valid context object.
        booking_context_helper::fix_booking_page_context($PAGE, $this->cmid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        $userid = $userid ?? $USER->id;

        // If there is no user, just return empty string.
        if (empty($userid)) {
            return '';
        }

        $text = "";

        if (in_array($bookinganswers->user_status($userid),
            [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_WAITINGLIST])) {
            $ac = $bookinganswers->is_activity_completed($userid);
            if ($ac == 1) {
                if (!empty($this->settings->aftercompletedtext)) {
                    $text = $this->settings->aftercompletedtext;
                } else if (!empty($bookingsettings->aftercompletedtext)) {
                    $text = $bookingsettings->aftercompletedtext;
                }
            } else {
                if (!empty($this->settings->beforecompletedtext)) {
                    $text = $this->settings->beforecompletedtext;
                } else if (!empty($bookingsettings->beforecompletedtext)) {
                    $text = $bookingsettings->beforecompletedtext;
                }
            }
        } else {
            if (!empty($this->settings->beforebookedtext)) {
                $text = $this->settings->beforebookedtext;
            } else if (!empty($bookingsettings->beforebookedtext)) {
                $text = $bookingsettings->beforebookedtext;
            }
        }

        $text = placeholders_info::render_text($text, $this->settings->cmid, $this->settings->id, $userid);

        return format_string($text);
    }

    /**
     * Updates canbookusers and bookedusers does not check the status (booked or waitinglist)
     * Just gets the registered booking from database
     * Calculates the potential users (bookers able to book, but not yet booked)
     */
    public function update_booked_users() {
        global $CFG, $DB, $USER;

        $bookanyone = get_user_preferences('bookanyone', '0');

        if (empty($this->booking->canbookusers)) {
            $this->booking->get_canbook_userids();
        }

        if ($CFG->version >= 2021051700) {
            // This only works in Moodle 3.11 and later.
            $mainuserfields = \core_user\fields::for_name()->with_userpic()->get_sql('u')->selects;
            // The $mainuserfields variable already includes a comma in the beginning, so trim it first.
            $mainuserfields = trim($mainuserfields, ', ');
        } else {
            // This is deprecated in Moodle 3.11 and later.
            $mainuserfields = \user_picture::fields('u', null);
        }

        $sql = "SELECT $mainuserfields, ba.id AS answerid, ba.optionid, ba.bookingid
                 FROM {booking_answers} ba, {user} u
                WHERE ba.userid = u.id
                  AND u.deleted = 0
                  AND ba.optionid = :optionid
                  AND ba.waitinglist = 0
             ORDER BY ba.timemodified ASC";

        $params = ["optionid" => $this->optionid];

        // Note: mod/booking:choose may have been revoked after the user has booked: not count them as booked.
        $allanswers = $DB->get_records_sql($sql, $params);

        // If $bookanyone is true, we do not check for enrolment.
        $this->bookedusers = $bookanyone ? $allanswers : array_intersect_key($allanswers, $this->booking->canbookusers);

        // TODO offer users with according caps to delete excluded users from booking option.
        $this->numberofanswers = count($this->bookedusers);
        if (groups_get_activity_groupmode($this->booking->cm) == SEPARATEGROUPS &&
                 !has_capability('moodle/site:accessallgroups',
                        \context_course::instance($this->booking->course->id))) {
            $mygroups = groups_get_all_groups($this->booking->course->id, $USER->id);
            $mygroupids = array_keys($mygroups);
            list($insql, $inparams) = $DB->get_in_or_equal($mygroupids, SQL_PARAMS_NAMED, 'grp', true, -1);

            $sql = "SELECT $mainuserfields, ba.id AS answerid, ba.optionid, ba.bookingid
            FROM {booking_answers} ba, {user} u, {groups_members} gm
            WHERE ba.userid = u.id AND
            u.deleted = 0 AND
            ba.optionid = :optionid AND
            u.id = gm.userid AND gm.groupid $insql
            GROUP BY u.id
            ORDER BY ba.timemodified ASC";
            $groupmembers = $DB->get_records_sql($sql, array_merge($params, $inparams));
            $this->bookedusers = array_intersect_key($groupmembers, $this->booking->canbookusers);
            $this->bookedvisibleusers = $this->bookedusers;
        } else {
            $this->bookedvisibleusers = $allanswers;
        }
        $this->potentialusers = array_diff_key($this->booking->canbookusers, $this->bookedvisibleusers);
        $this->sort_answers();
    }

    /**
     * Add booked/waitinglist info to each userobject of users.
     */
    public function sort_answers() {

        $maxoverbooking = $this->option->maxoverbooking ?? 0;

        if (!empty($this->bookedusers) && null != $this->option) {
            foreach ($this->bookedusers as $rank => $userobject) {
                $userobject->bookingcmid = $this->cmid;
                if (!$this->option->limitanswers) {
                    $userobject->booked = 'booked';
                }
                // Rank starts at 0 so add + 1 to corespond to max answer settings.
                if ($this->option->maxanswers < ($rank + 1) &&
                         $rank + 1 <= ($this->option->maxanswers + $maxoverbooking)) {
                    $userobject->booked = 'waitinglist';
                } else if ($rank + 1 <= $this->option->maxanswers) {
                    $userobject->booked = 'booked';
                }
            }
        }
    }

    /**
     * Mass delete all users with activity completion.
     *
     * @return array
     * @throws dml_exception
     */
    public function delete_responses_activitycompletion() {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        $ud = [];
        $oud = [];
        $users = $DB->get_records('course_modules_completion',
                ['coursemoduleid' => $bookingsettings->completionmodule]);
        $ousers = $DB->get_records('booking_answers', ['optionid' => $this->optionid]);

        foreach ($users as $u) {
            $ud[] = $u->userid;
        }

        foreach ($ousers as $u) {
            $oud[] = $u->userid;
        }

        $todelete = array_intersect($ud, $oud);

        $results = [];
        foreach ($todelete as $userid) {
            $results[$userid] = $this->user_delete_response($userid);
        }

        return $results;
    }

    /**
     * Mass delete all responses
     *
     * @param array $users array of users
     * @return array
     */
    public function delete_responses($users = []) {
        $results = [];
        if (!is_array($users) || empty($users)) {
            return $results;
        }
        foreach ($users as $userid) {
            $results[$userid] = $this->user_delete_response($userid);
        }
        return $results;
    }

    /**
     * Deletes a single booking of a user if user cancels the booking, sends mail to bookingmanager.
     * If there is a limit book other user and send mail to the user.
     *
     * @param int $userid
     * @param bool $cancelreservation
     * @param bool $bookingoptioncancel indicates if the function was called
     *     after the whole booking option was cancelled, false by default
     * @param bool $syncwaitinglist set this to false, if you do not want to sync_waiting_list here (avoid recursions)
     * @return bool true if booking was deleted successfully, otherwise false
     */
    public function user_delete_response($userid, $cancelreservation = false,
        $bookingoptioncancel = false, $syncwaitinglist = true) {

        global $USER, $DB;

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);
        $ba = singleton_service::get_instance_of_booking_answers($optionsettings);

        // We need to check if we were, before deleting, fully booked.
        if ($ba->is_fully_booked()) {
            $fullybooked = true;
        } else {
            $fullybooked = false;
        }

        // If waitforconfirmation is turned on, we will never sync waitinglist (we do it manually).
        if (!empty($optionsettings->waitforconfirmation)) {
            $syncwaitinglist = false;
        }

        $results = $DB->get_records('booking_answers',
                ['userid' => $userid, 'optionid' => $this->optionid, 'completed' => 0]);

        if (count($results) == 0) {
            return false;
        }

        if ($cancelreservation) {
            $DB->delete_records('booking_answers',
                    ['userid' => $userid,
                      'optionid' => $this->optionid,
                      'completed' => 0,
                      'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
                    ]);
        } else {
            foreach ($results as $result) {
                if ($result->waitinglist != MOD_BOOKING_STATUSPARAM_DELETED) {
                    $result->waitinglist = MOD_BOOKING_STATUSPARAM_DELETED;
                    $result->timemodified = time();
                    // We mark all the booking answers as deleted.

                    $DB->update_record('booking_answers', $result);
                }
            }
        }

        // Purge caches BEFORE sync_waiting_list.
        self::purge_cache_for_answers($this->optionid);

        // If the whole option was cancelled, there is no need to sync anymore.
        if ($syncwaitinglist && !$bookingoptioncancel) {
            // Sync the waiting list and send status change mails.
            $this->sync_waiting_list();
        }

        // We also have to trigger unenrolement of corresponding subbookings.
        $subbookings = subbookings_info::return_array_of_subbookings($this->optionid);

        foreach ($subbookings as $subbooking) {
            // We delete this subbooking option.
            subbookings_info::save_response($subbooking->area, $subbooking->itemid, MOD_BOOKING_STATUSPARAM_DELETED, $userid);
        }

        if ($cancelreservation) {
            return true;
        }

        if ($userid == $USER->id) {
            $user = $USER;
        } else {
            $user = $DB->get_record('user', ['id' => $userid]);
        }

        /* NOTE FOR THE FUTURE: Currently we have no rule condition to select affected users of an event.
        In the future, we need to figure out a way, so we can react to this event
        (when a user gets cancelled or cancels by himself) and send mails by rules.
        BUT: We do not want to send mails twice if a booking option gets cancelled. */

        // Log cancellation of user.
        $event = bookinganswer_cancelled::create([
            'objectid' => $this->optionid,
            'context' => \context_module::instance($this->cmid),
            'userid' => $USER->id, // The user who did cancel.
            'relateduserid' => $userid, // Affected user - the user who was cancelled.
        ]);
        $event->trigger(); // This will trigger the observer function and delete calendar events.
        $this->unenrol_user($user->id);

        // We only send messages for booking answers for individual cancellations!
        // If a whole booking option was cancelled, we can use the new global booking rules...
        // ...and react to the event bookingoption_cancelled instead.
        if (!$bookingoptioncancel) {

            if ($userid == $USER->id) {
                // Participant cancelled the booking herself.
                $msgparam = MOD_BOOKING_MSGPARAM_CANCELLED_BY_PARTICIPANT;

                // TODO: Trigger event.
            } else {
                // An admin user cancelled the booking.
                $msgparam = MOD_BOOKING_MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM;
            }

            // Before sending an e-mail, we make sure that caches are purged.
            self::purge_cache_for_option($this->optionid);

            // Let's send the cancel e-mails by using adhoc tasks.
            $messagecontroller = new message_controller(
                MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC, $msgparam,
                $this->cmid, $this->optionid, $userid, $this->bookingid
            );
            $messagecontroller->send_or_queue();
        }

        // Remove activity completion.
        $course = $DB->get_record('course', ['id' => $bookingsettings->course]);
        $completion = new completion_info($course);

        $countcompleted = $DB->count_records('booking_answers',
                ['bookingid' => $bookingsettings->id, 'userid' => $user->id, 'completed' => '1']);

        if ($completion->is_enabled($this->booking->cm) && $bookingsettings->enablecompletion < $countcompleted) {
            $completion->update_state($this->booking->cm, COMPLETION_INCOMPLETE, $userid);
        }

        // After deleting an answer, cache has to be invalidated.
        self::purge_cache_for_option($this->optionid);

        if ($fullybooked) {
            $ba = singleton_service::get_instance_of_booking_answers($optionsettings);
            // Check if there is, after potential syncing, still a place.
            if (!$ba->is_fully_booked()) {
                // Now we trigger the event.
                // Log cancellation of user.
                $event = bookingoption_freetobookagain::create([
                    'objectid' => $this->optionid,
                    'context' => \context_module::instance($this->cmid),
                    'userid' => $userid, // The user who did cancel.
                ]);
                $event->trigger();
            }
        }

        return true;
    }

    /**
     * Unsubscribes given users from this booking option and subscribes them to the newoption
     *
     * @param int $newoption
     * @param array $userids of numbers
     * @return stdClass transferred->success = true/false, transferred->no[] errored users,
     *         $transferred->yes transferred users
     */
    public function transfer_users_to_otheroption(int $newoption, array $userids) {
        global $CFG, $DB;
        $transferred = new stdClass();
        $transferred->yes = []; // Successfully transferred users.
        $transferred->no = []; // Errored users.
        $transferred->success = false;
        $otheroption = singleton_service::get_instance_of_booking_option($this->cmid, $newoption);
        if (!empty($userids) && (has_capability('mod/booking:subscribeusers', $this->booking->get_context()) ||
                booking_check_if_teacher($otheroption->option))) {
            $transferred->success = true;
            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "limit_");

            if ($CFG->version >= 2021051700) {
                // This only works in Moodle 3.11 and later.
                $mainuserfields = \core_user\fields::for_name()->get_sql('u')->selects;
                $mainuserfields = trim($mainuserfields, ', ');
            } else {
                // This is only here to support Moodle versions earlier than 3.11.
                $mainuserfields = get_all_user_name_fields(true, 'u');
            }

            $sql = 'SELECT ba.userid AS id,
                ba.timecreated,
                ' . $mainuserfields . ', ' .
                     $DB->sql_fullname('u.firstname', 'u.lastname') . ' AS fullname
                FROM {booking_answers} ba
                LEFT JOIN {user} u ON ba.userid = u.id
                WHERE ' . 'ba.userid ' . $insql . '
                AND ba.optionid = ' . $this->optionid . '
                ORDER BY ba.timecreated ASC';
            $users = $DB->get_records_sql($sql, $inparams);
            foreach ($users as $user) {
                if ($otheroption->user_submit_response($user, 0, 1, 0, MOD_BOOKING_VERIFIED)) {
                    $transferred->yes[] = $user;
                } else {
                    $transferred->no[] = $user;
                    $transferred->success = false;
                }
            }
        }
        if (!empty($transferred->yes)) {
            foreach ($transferred->yes as $user) {
                $this->user_delete_response($user->id);
            }
        }

        return $transferred;
    }

    /**
     * "Sync" users on waiting list, based on edited option - if has limit or not.
     */
    public function sync_waiting_list() {
        global $USER;

        $context = context_module::instance(($this->cmid));
        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        // If waiting list is turned off globally, we return right away.
        if (get_config('booking', 'turnoffwaitinglist') ||
            (get_config('booking', 'turnoffwaitinglistaftercoursestart') && time() > $settings->coursestarttime)
        ) {
            return;
        }

        // If the booking option has a price, we don't sync waitinglist.
        if (!empty($settings->jsonobject->useprice)) {
            return;
        }

        $ba = singleton_service::get_instance_of_booking_answers($settings);

        // If there is no waiting list, we do not do anything!
        if ($settings->limitanswers && !empty($settings->maxanswers)) {

            // 1. Update, enrol and inform users who have switched from the waiting list to status "booked".
            $usersonwaitinglist = array_replace([], $ba->usersonwaitinglist);
            $noofuserstobook = $settings->maxanswers - count($ba->usersonlist) - count($ba->usersreserved);

            // We want to enrol people who have been waiting longer first.
            usort($usersonwaitinglist, fn($a, $b) => $a->timemodified < $b->timemodified ? -1 : 1);
            if ($noofuserstobook > 0 && !empty($ba->usersonwaitinglist)) {
                while ($noofuserstobook > 0) {
                    $noofuserstobook--; // Decrement.
                    $currentanswer = array_shift($usersonwaitinglist);
                    if (empty($currentanswer->userid)) {
                        continue;
                    }
                    $user = singleton_service::get_instance_of_user($currentanswer->userid);
                    $this->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
                    $this->enrol_user_coursestart($currentanswer->userid);

                    // Before sending, we delete the booking answers cache!
                    self::purge_cache_for_answers($this->optionid);
                    $messagecontroller = new message_controller(
                        MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC, MOD_BOOKING_MSGPARAM_STATUS_CHANGED,
                        $this->cmid, $this->optionid, $currentanswer->userid, $this->bookingid
                    );
                    $messagecontroller->send_or_queue();
                }
            }

            // 2. Update and inform users who have been put on the waiting list because of changed limits.
            $usersonlist = array_merge($ba->usersonlist, $ba->usersreserved);
            usort($usersonlist, fn($a, $b) => $a->timemodified < $b->timemodified ? -1 : 1);
            while (count($usersonlist) > $settings->maxanswers) {
                $currentanswer = array_pop($usersonlist);
                array_push($usersonwaitinglist, $currentanswer);

                $user = singleton_service::get_instance_of_user($currentanswer->userid);
                $this->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
                $this->unenrol_user($currentanswer->userid);

                // Before sending, we delete the booking answers cache!
                self::purge_cache_for_answers($this->optionid);
                $messagecontroller = new message_controller(
                    MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC, MOD_BOOKING_MSGPARAM_STATUS_CHANGED,
                    $this->cmid, $this->optionid, $currentanswer->userid, $this->bookingid
                );
                $messagecontroller->send_or_queue();
            }

            // 3. If users drop out of the waiting list because of changed limits, delete and inform them.
            while (count($usersonwaitinglist) > $settings->maxoverbooking) {
                $currentanswer = array_pop($usersonwaitinglist);
                // The fourth param needs to be false here, so we do not run into a recursion.
                $this->user_delete_response($currentanswer->userid, false, false, false);

                $event = bookinganswer_cancelled::create([
                    'objectid' => $this->optionid,
                    'context' => $context,
                    'userid' => $USER->id, // The user who did cancel.
                    'relateduserid' => $currentanswer->userid, // Affected user - the user who was cancelled.
                    'other' => [
                        'extrainfo' => 'Answer deleted by sync_waiting_list.',
                    ],
                ]);
                $event->trigger();

                // Before sending, we delete the booking answers cache!
                self::purge_cache_for_answers($this->optionid);
                $messagecontroller = new message_controller(
                    MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC, MOD_BOOKING_MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM,
                    $this->cmid, $this->optionid, $currentanswer->userid, $this->bookingid
                );
                $messagecontroller->send_or_queue();
            }

        } else {
            // If option was set to unlimited, we book all users that have been on the waiting list and inform them.
            foreach ($ba->usersonwaitinglist as $currentanswer) {
                $user = singleton_service::get_instance_of_user($currentanswer->userid);
                $this->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
                $this->enrol_user_coursestart($currentanswer->userid);

                // Before sending, we delete the booking answers cache!
                self::purge_cache_for_answers($this->optionid);
                $messagecontroller = new message_controller(
                    MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC, MOD_BOOKING_MSGPARAM_STATUS_CHANGED,
                    $this->cmid, $this->optionid, $currentanswer->userid, $this->bookingid
                );
                $messagecontroller->send_or_queue();
            }
        }
    }

    /**
     * Enrol users only if either course has already started or booking option is set to immediately enrol users.
     *
     * @param int $userid
     * @throws \moodle_exception
     */
    public function enrol_user_coursestart($userid) {
        if ($this->option->enrolmentstatus == 2 ||
            ($this->option->enrolmentstatus < 2 && $this->option->coursestarttime < time())) {

                // This is a new elective function. We only allow booking in the right order.
            if ($this->booking->is_elective()) {
                if (!elective::check_if_allowed_to_inscribe($this, $userid)) {
                    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                    /* mtrace("The user with the userid {$userid} has to finish courses of other booking options first."); */
                    return;
                }
            }

            $this->enrol_user($userid);
        }
    }

    /**
     * Subscribe a user to a booking option
     *
     * @param stdClass $user
     * @param int $frombookingid
     * @param int $subtractfromlimit this is used for transferring users from one option to
     *        another
     *        The number of bookings for the user has to be decreased by one, because, the user will
     *        be unsubscribed
     *        from the old booking option afterwards (which is not yet taken into account).
     * @param int $status 1 if we just added this booking option to the shopping cart, 2 for confirmation.
     * @param int $verified 0 for unverified, 1 for pending and 2 for verified.
     * @return bool true if booking was possible, false if meanwhile the booking got full
     */
    public function user_submit_response(
            $user,
            $frombookingid = 0,
            $subtractfromlimit = 0,
            $status = 0,
            $verified = MOD_BOOKING_UNVERIFIED) {

        global $USER;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        // First check, we only accept verified submissions.
        // This function always needs to be called with the verified param.
        if (!$verified) {
            return false;
        }

        if (empty($this->option)) {
            echo "<br>Didn't find option to subscribe user $user->id <br>";
            return false;
        }

        // We check if we can still book the user.
        // False means, that it can't be booked.
        // 0 means, that we can book right away
        // 1 means, that there is only a place on the waiting list.
        $waitinglist = $this->check_if_limit($user->id, self::option_allows_overbooking_for_user($this->optionid));
        // With the second param, we check if overbooking is allowed.

        // The $status == 2 means confirm. Under some circumstances, waitinglist can be false here.
        if ($waitinglist === false && $status != 2) {

            // TODO: introduce an "allowoverbooking" param into the availability JSON.
            // If the JSON contains it, we want to allow overbooking even without a waiting list.
            // TOOD: It has to be added to the override conditions mform elements as a checkbox.

            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* echo "Couldn't subscribe user $user->id because of full waitinglist <br>";*/
            return false;
        }

        switch ($status) {
            case 1: // Means reserve.
                $waitinglist = MOD_BOOKING_STATUSPARAM_RESERVED;
                break;
            case 2: // Means confirm on waitinglist.
            case 3: // Means unconfirm on waitinglist.
                $waitinglist = MOD_BOOKING_STATUSPARAM_WAITINGLIST;
                break;
        }

        // Only if maxperuser is set, the part after the OR is executed.
        $underlimit = ($bookingsettings->maxperuser == 0);
        $underlimit = $underlimit ||
                (($this->booking->get_user_booking_count($user) - $subtractfromlimit) < $bookingsettings->maxperuser);
        if (!$underlimit) {
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* mtrace("Couldn't subscribe user $user->id because of maxperuser setting <br>"); */
            return false;
        }

        $bookinganswers = singleton_service::get_instance_of_booking_answers($this->settings);

        if (isset($bookinganswers->users[$user->id]) && ($currentanswer = $bookinganswers->users[$user->id])) {
            switch($currentanswer->waitinglist) {
                case MOD_BOOKING_STATUSPARAM_DELETED:
                    break;
                case MOD_BOOKING_STATUSPARAM_BOOKED:
                    // If we come from sync_waiting_list it might be possible that someone is moved from booked to waiting list.
                    // If we are already booked, we don't do anything.
                    if ($waitinglist == MOD_BOOKING_STATUSPARAM_BOOKED) {
                        return true;
                    }
                    // Else, we might move from booked to waitinglist, we just continue.
                    break;
                case MOD_BOOKING_STATUSPARAM_RESERVED:
                    // If the old and the new value is reserved, we just return true, we don't need to do anything.
                    if ($waitinglist == MOD_BOOKING_STATUSPARAM_RESERVED) {
                        return true;
                    }
                    // Else, we might move from reserved to booked, we just continue.
                    break;
                case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                    // If we are not yet booked and we need manual confirmation...
                    // ... We switch booking param to waitinglist.
                    if (!empty($this->settings->waitforconfirmation)) {

                        $waitinglist = MOD_BOOKING_STATUSPARAM_WAITINGLIST;

                    }

                    break;
            }
            $currentanswerid = $currentanswer->baid;
            $timecreated = $currentanswer->timecreated;
        } else {
            $currentanswerid = null;
            $timecreated = null;

            if ($waitinglist === MOD_BOOKING_STATUSPARAM_BOOKED
                && !empty($this->settings->waitforconfirmation)) {

                $waitinglist = MOD_BOOKING_STATUSPARAM_WAITINGLIST;

                $event = bookinganswer_waitingforconfirmation::create([
                    'objectid' => $this->optionid,
                    'context' => context_module::instance($this->cmid),
                    'userid' => $USER->id, // The user triggered the action.
                    'relateduserid' => $user->id, // Affected user - the user who is waiting for confirmation.
                ]);
                $event->trigger(); // This will trigger the observer function.
            }
        }

        self::write_user_answer_to_db($this->booking->id,
                                       $frombookingid,
                                       $user->id,
                                       $this->optionid,
                                       $waitinglist,
                                       $currentanswerid,
                                       $timecreated,
                                       $status);

        // Important: Purge caches after submitting a new user.
        self::purge_cache_for_answers($this->optionid);

        // To avoid a problem with the payment process, we catch any error that might occur.
        try {
            $this->after_successful_booking_routine($user, $waitinglist);
            return true;
        } catch (Exception $e) {
            // We do not want this to fail if there was an exception.
            // So we still return true.

            $message = $e->getMessage();
            // Log cancellation of user.
            $event = booking_afteractionsfailed::create([
                'objectid' => $this->optionid,
                'context' => \context_module::instance($this->cmid),
                'userid' => $USER->id, // The user triggered the action.
                'relateduserid' => $user->id, // Affected user - the user for whom the booking failed..
                'other' => [
                    'error' => $message,
                ],
            ]);
            $event->trigger(); // This will trigger the observer function.

            return true;
        }
    }

    /**
     * Handles the actual writing or updating.
     *
     * @param int $bookingid
     * @param int $frombookingid
     * @param int $userid
     * @param int $optionid
     * @param int $waitinglist
     * @param [type] $currentanswerid
     * @param [type] $timecreated
     * @param int $confirmwaitinglist
     * @return void
     */
    public static function write_user_answer_to_db(int $bookingid,
                                            int $frombookingid,
                                            int $userid,
                                            int $optionid,
                                            int $waitinglist,
                                            $currentanswerid = null,
                                            $timecreated = null,
                                            $confirmwaitinglist = 0) {

        global $DB, $USER;

        $now = time();

        // For book with credits, we need to delete the cache.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $newanswer = new stdClass();
        $newanswer->bookingid = $bookingid;
        $newanswer->frombookingid = $frombookingid;
        $newanswer->userid = $userid;
        $newanswer->optionid = $optionid;
        $newanswer->timemodified = $now;
        $newanswer->timecreated = $timecreated ?? $now;
        $newanswer->waitinglist = $waitinglist;

        // When a user submits a userform, we need to save this as well.
        customform::add_json_to_booking_answer($newanswer, $userid);

        // When a user submits a userform, we need to save this as well.
        credits::add_json_to_booking_answer($newanswer, $userid);

        // The confirmation on the waitinglist is saved here.
        if ($confirmwaitinglist === 2) {
            self::add_data_to_json($newanswer, 'confirmwaitinglist', 1);
            self::add_data_to_json($newanswer, 'confirmwaitinglist_modifieduserid', $USER->id);
            self::add_data_to_json($newanswer, 'confirmwaitinglist_timemodified', time());
        } else if ($confirmwaitinglist === 3) {
            // We only remove the key if we are still on waitinglist.
            self::remove_key_from_json($newanswer, 'confirmwaitinglist');
            self::remove_key_from_json($newanswer, 'confirmwaitinglist_modifieduserid');
            self::remove_key_from_json($newanswer, 'confirmwaitinglist_timemodified');
        }

        if (isset($currentanswerid)) {
            $newanswer->id = $currentanswerid;
            if (!$DB->update_record('booking_answers', $newanswer)) {
                new \moodle_exception("dmlwriteexception");
            }
        } else {
            if (!$DB->insert_record('booking_answers', $newanswer)) {
                new \moodle_exception("dmlwriteexception");
            }
        }

        // After writing an answer, cache has to be invalidated.
        self::purge_cache_for_answers($optionid);
    }


    /**
     * Function to move user from reserved to booked status in DB.
     *
     * @param stdClass $user
     * @return bool
     */
    public function user_confirm_response(stdClass $user): bool {

        global $DB, $USER;

        $ba = singleton_service::get_instance_of_booking_answers($this->settings);

        // We have to get all the records of the user, there might be more than one.
        $currentanswers = null;
        foreach ($ba->answers as $answer) {
            if ($answer->optionid == $this->settings->id
                    && $answer->userid == $user->id
                    && $answer->waitinglist == MOD_BOOKING_STATUSPARAM_RESERVED) {
                $currentanswers[] = $answer;
            }
        }

        if (!$currentanswers) {
            return false;
        }

        $counter = 0;
        foreach ($currentanswers as $currentanswer) {
            // This should never happen, but if we have more than one reservation, we just confirm the first and delete the rest.
            if ($counter > 0) {
                $DB->delete_records('booking_answers',
                    ['id' => $currentanswer->id, 'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED]);
            } else {
                // When it's the first reserveration, we just confirm it.
                $currentanswer->timemodified = time();
                $currentanswer->waitinglist = MOD_BOOKING_STATUSPARAM_BOOKED;

                self::write_user_answer_to_db($this->settings->bookingid,
                                               $currentanswer->frombookingid ?? 0,
                                               $currentanswer->userid,
                                               $currentanswer->optionid,
                                               $currentanswer->waitinglist,
                                               $currentanswer->baid,
                                               $currentanswer->timecreated);

                $counter++;
            }
        }

        if ($counter > 0) {
            try {
                $this->after_successful_booking_routine($user, MOD_BOOKING_STATUSPARAM_BOOKED);
                return true;
            } catch (Exception $e) {
                // We do not want this to fail if there was an exception.
                // So we still return true.

                $message = $e->getMessage();
                // Log cancellation of user.
                $event = booking_afteractionsfailed::create([
                    'objectid' => $this->optionid,
                    'context' => \context_module::instance($this->cmid),
                    'userid' => $USER->id, // The user triggered the action.
                    'relateduserid' => $user->id, // Affected user - the user for whom the booking failed..
                    'other' => [
                        'error' => $message,
                    ],
                ]);
                $event->trigger(); // This will trigger the observer function.

                return true;
            }
        } else {
            return false;
        }
    }

    /**
     * Once we introduced the booking answer to DB, we need to clean cache, notify etc.
     *
     * @param stdClass $user
     * @param int $waitinglist
     * @return bool
     */
    public function after_successful_booking_routine(stdClass $user, int $waitinglist) {

        global $DB, $USER;

        // If we have only put the option in the shopping card (reserved) we will skip the rest of the fucntion here.
        // Also, when we just confirm the waitinglist.
        if ($waitinglist == MOD_BOOKING_STATUSPARAM_RESERVED
            || $waitinglist == MOD_BOOKING_STATUSPARAM_NOTIFYMELIST) {

            return true;
        }

        // At this point, we trigger the after booking actions.
        // Depending on the status, we have different ways of continueing.
        if (actions_info::apply_actions($this->settings) == 1) {
            return true;
        }

        $this->enrol_user_coursestart($user->id);

        $other = [];
        $ba = singleton_service::get_instance_of_booking_answers($this->settings);
        if (isset($ba->usersonlist[$user->id])) {
            $answer = $ba->usersonlist[$user->id];
            $other['baid'] = $answer->baid;
            $other['json'] = $answer->json ?? '';
        }

        if ($waitinglist == MOD_BOOKING_STATUSPARAM_WAITINGLIST) {
            // Booked on waitinglist -> trigger corresponding event.
            $event = event\bookingoptionwaitinglist_booked::create(
                ['objectid' => $this->optionid,
                    'context' => context_module::instance($this->cmid),
                    'userid' => $USER->id,
                    'relateduserid' => $user->id,
                    'other' => $other,
                ]);
            $event->trigger();
        } else {
            $event = event\bookingoption_booked::create(
                ['objectid' => $this->optionid,
                    'context' => context_module::instance($this->cmid),
                    'userid' => $USER->id,
                    'relateduserid' => $user->id,
                    'other' => $other,
                ]);
            $event->trigger();
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        // Check if the option is a multidates session.
        if (!$optiondates = $settings->sessions) {
            $optiondates = false;
        }

        $dontusepersonalevents = get_config('booking', 'dontaddpersonalevents');

        if ($dontusepersonalevents != 1) {
            // If the option has optiondates, then add the optiondate events to the user's calendar.
            if ($optiondates) {
                foreach ($optiondates as $optiondate) {
                    new calendar($this->cmid, $this->optionid, $user->id, 6, $optiondate->id, 1);
                }
            } else {
                // Else add the booking option event to the user's calendar.
                new calendar($this->cmid, $this->optionid, $user->id, 1, 0, 1);
            }
        }

        // Now check, if there are rules to execute.
        rules_info::execute_rules_for_option($this->optionid, $user->id);

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);
        if ($bookingsettings->sendmail) {
            $this->send_confirm_message($user);
        }
        return true;
    }

    /**
     * Event that sends confirmation notification after user successfully booked.
     *
     * With the second param "optionchanged" set to true, this will send a notification mail to the user,
     * informing him/her that the option event has changed and will include the updated ical.
     *
     * @param stdClass $user user object
     * @param bool $optionchanged optional param used to inform the user of updates on the option
     * @param ?array $changes a string containing changes to be replaced in the update message
     * @return bool
     */
    public function send_confirm_message(stdClass $user, bool $optionchanged = false, ?array $changes = null) {

        if (!get_config('booking', 'uselegacymailtemplates')) {
            // Check if this deprecated method should really still be used.
            return false;
        }

        global $DB;

        $user = $DB->get_record('user', ['id' => $user->id]);

        /* Status can be MOD_BOOKING_STATUSPARAM_BOOKED (0), MOD_BOOKING_STATUSPARAM_NOTBOOKED (4),
        MOD_BOOKING_STATUSPARAM_WAITINGLIST (1). */
        $ba = singleton_service::get_instance_of_booking_answers($this->settings);
        $status = $ba->user_status($user->id);

        if ($optionchanged) {

            // Change notification.
            $msgparam = MOD_BOOKING_MSGPARAM_CHANGE_NOTIFICATION;

        } else if ($status == MOD_BOOKING_STATUSPARAM_BOOKED) {

            // Booking confirmation.
            $msgparam = MOD_BOOKING_MSGPARAM_CONFIRMATION;

        } else if ($status == MOD_BOOKING_STATUSPARAM_WAITINGLIST) {

            // Waiting list confirmation.
            $msgparam = MOD_BOOKING_MSGPARAM_WAITINGLIST;

        } else {
            // Error: No message can be sent.
            return false;
        }

        // Use message controller to send the message.
        $messagecontroller = new message_controller(
            MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC, $msgparam, $this->cmid,
            $this->optionid, $user->id, $this->bookingid, null, $changes
        );
        $messagecontroller->send_or_queue();

        return true;
    }

    /**
     * Automatically enrol the user in the relevant course, if that setting is on and a course has been specified.
     * Added option, to manualy enrol user, with a click of button.
     *
     * @param int $userid
     * @param bool $manual
     * @param int $roleid
     * @param bool $isteacher true for teacher enrolments
     * @param int $courseid can override given courseid.
     */
    public function enrol_user(int $userid, bool $manual = false, int $roleid = 0, bool $isteacher = false, int $courseid = 0) {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);
        if (!$manual) {
            if (!$bookingsettings->autoenrol) {
                return; // Autoenrol not enabled.
            }
        }
        $courseid = empty($courseid) ? $this->option->courseid : $courseid;
        if (empty($courseid)) {
            return; // No course specified.
        }

        if (!enrol_is_enabled('manual')) {
            return; // Manual enrolment not enabled.
        }

        if (!$enrol = enrol_get_plugin('manual')) {
            return; // No manual enrolment plugin.
        }
        if (!$instances = $DB->get_records('enrol',
                        ['enrol' => 'manual', 'courseid' => $courseid, 'status' => ENROL_INSTANCE_ENABLED],
                        'sortorder,id ASC')) {
            return; // No manual enrolment instance on this course.
        }

        $bookinganswers = booking_answers::get_instance_from_optionid($this->optionid);

        $instance = reset($instances); // Use the first manual enrolment plugin in the course.
        if ($bookinganswers->user_status($userid) == MOD_BOOKING_STATUSPARAM_BOOKED || $isteacher) {

            // If a semester is set for the booking option...
            // ...then we only want to enrol from semester startdate to semester enddate.
            if (empty($this->settings->semesterid)) {
                // Enrol using the default role.
                $enrol->enrol_user($instance, $userid, ($roleid > 0 ? $roleid : $instance->roleid));
            } else {
                if ($semesterobj = $DB->get_record('booking_semesters', ['id' => $this->settings->semesterid])) {
                    // Enrol using the default role from semester start until semester end.
                    $enrol->enrol_user($instance, $userid, ($roleid > 0 ? $roleid : $instance->roleid),
                        $semesterobj->startdate, $semesterobj->enddate);
                } else {
                    // Enrol using the default role.
                    $enrol->enrol_user($instance, $userid, ($roleid > 0 ? $roleid : $instance->roleid));
                }
            }

            // TODO: Track enrolment status in booking_answers. It makes no sense to track it in booking_options.
            if ($bookingsettings->addtogroup == 1) {
                $groups = groups_get_all_groups($courseid);
                if (!is_null($this->option->groupid) && ($this->option->groupid > 0) &&
                        in_array($this->option->groupid, $groups)) {
                    groups_add_member($this->option->groupid, $userid);
                } else {
                    $newoptionstd = $this->settings->return_settings_as_stdclass();
                    $newoptionstd->courseid = $courseid;
                    if ($groupid = $this->create_group($newoptionstd)) {
                        groups_add_member($groupid, $userid);
                    } else {
                        throw new moodle_exception('groupexists', 'booking');
                    }
                }
            }
        }
    }

    /**
     * Unenrol the user from the course, which has been defined as target course
     * in the booking option settings
     *
     * @param int $userid
     */
    public function unenrol_user($userid) {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        if (!$bookingsettings->autoenrol) {
            return; // Autoenrol not enabled.
        }
        if (!$this->option->courseid) {
            return; // No course specified.
        }
        if (!enrol_is_enabled('manual')) {
            return; // Manual enrolment not enabled.
        }
        if (!$enrol = enrol_get_plugin('manual')) {
            return; // No manual enrolment plugin.
        }

        if (!$instances = $DB->get_records('enrol',
                    ['enrol' => 'manual', 'courseid' => $this->option->courseid, 'status' => ENROL_INSTANCE_ENABLED],
                    'sortorder,id ASC')) {
            return; // No manual enrolment instance on this course.
        }
        if ($bookingsettings->addtogroup == 1) {
            if (!is_null($this->option->groupid) && ($this->option->groupid > 0)) {
                $groupsofuser = groups_get_all_groups($this->option->courseid, $userid);
                $numberofgroups = count($groupsofuser);
                // When user is member of only 1 group: unenrol from course otherwise remove from group.
                if ($numberofgroups > 1) {
                    groups_remove_member($this->option->groupid, $userid);
                    return;
                }
            }
        }
        $instance = reset($instances); // Use the first manual enrolment plugin in the course.
        $enrol->unenrol_user($instance, $userid); // Unenrol the user.
    }

    /**
     * Create a new group for a booking option if it is not already created
     * Return the id of the group.
     * @param stdClass $newoption
     * @return bool|number id of the group
     * @throws \moodle_exception
     */
    public function create_group($newoption) {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);
        $bookingsettings = $bookingsettings->return_settings_as_stdclass();
        $newgroupdata = self::generate_group_data($bookingsettings, $newoption);

        $groupids = array_keys(groups_get_all_groups($newoption->courseid));
        // If group name already exists, do not create it a second time, it should be unique.
        if ($groupid = groups_get_group_by_name($newgroupdata->courseid, $newgroupdata->name)) {
            return $groupid;
        }
        if ($groupid = groups_get_group_by_name($newgroupdata->courseid, $newgroupdata->name) &&
                !isset($this->option->id)) {
            $url = new moodle_url('/mod/booking/view.php', ['id' => $this->cmid]);
            throw new \moodle_exception('groupexists', 'booking', $url->out());
        }
        if ($this->option->groupid > 0 && in_array($this->option->groupid, $groupids)) {
            // Group has been created but renamed.
            $newgroupdata->id = $this->option->groupid;
            groups_update_group($newgroupdata);
            return $this->option->groupid;
        } else if (($this->option->groupid > 0 && !in_array($this->option->groupid, $groupids)) || $this->option->groupid == 0) {
            // Group has been deleted and must be created and groupid updated in DB. Or group does not yet exist.
            $data = new stdClass();
            $data->id = $this->option->id;
            $data->groupid = groups_create_group($newgroupdata);
            if ($data->groupid) {
                $DB->update_record('booking_options', $data);
            }
            return $data->groupid;
        }

        return false;
    }

    /**
     * Generate data for creating the group.
     *
     * @param stdClass $bookingsettings
     * @param stdClass $optionsettings
     * @return stdClass
     * @throws \moodle_exception
     */
    public static function generate_group_data(stdClass $bookingsettings, stdClass $optionsettings): stdClass {
        global $DB;

        // Replace tags with content. This alters the booking settings so cloning them.
        $newbookingsettings = clone $bookingsettings;
        $newoptionsettings = clone $optionsettings;

        $tags = new booking_tags($newoptionsettings->courseid);
        $newbookingsettings = $tags->booking_replace($newbookingsettings);
        $newoptionsettings = $tags->option_replace($newoptionsettings);

        $newgroupdata = new stdClass();
        $newgroupdata->courseid = $newoptionsettings->courseid;

        $optionname = $DB->get_field('booking_options', 'text', ['id' => $newoptionsettings->id]);
        // Before setting name, we have to resolve the id Tag.
        $newgroupdata->name = "{$newbookingsettings->name} - $optionname ({$newoptionsettings->id})";
        $newgroupdata->description = "{$newbookingsettings->name} - $optionname ({$newoptionsettings->id})";
        $newgroupdata->descriptionformat = FORMAT_HTML;

        return $newgroupdata;
    }

    /**
     *
     * Deletes a booking option and the associated user answers
     *
     * @return bool false if not successful, true on success
     * @throws coding_exception
     * @throws dml_exception
     */
    public function delete_booking_option() {
        global $DB, $USER;
        if (!$DB->record_exists("booking_options", ["id" => $this->optionid])) {
            return false;
        }

        $result = true;
        $answers = $this->get_all_users();
        foreach ($answers as $answer) {
            $this->unenrol_user($answer->userid); // Unenrol any users enrolled via this option.
        }
        if (!$DB->delete_records("booking_answers",
                ["bookingid" => $this->booking->id, "optionid" => $this->optionid])) {
            $result = false;
        }

        foreach ($this->get_teachers() as $teacher) {
            $teacherhandler = new teachers_handler($this->optionid);
            $teacherhandler->unsubscribe_teacher_from_booking_option(
                $teacher->userid,
                $this->optionid,
                $this->cmid);
        }

        // Delete calendar entry, if any.
        $eventid = $DB->get_field('booking_options', 'calendarid', ['id' => $this->optionid]);
        $eventexists = true;
        if ($eventid > 0) {
            // Delete event if exist.
            try {
                $event = \calendar_event::load($eventid);
            } catch (\Exception $e) {
                $eventexists = false;
            }
            if ($eventexists) {
                $event->delete(true);
            }
        }

        // Delete all associated user events for option:
        // Get all the userevents.
        $sql = "SELECT e.* FROM {booking_userevents} ue
              JOIN {event} e
              ON ue.eventid = e.id
              WHERE ue.optionid = :optionid";

        $allevents = $DB->get_records_sql($sql, ['optionid' => $this->optionid]);

        // Delete all the events we found associated with a user.
        foreach ($allevents as $item) {
            $DB->delete_records('event', ['id' => $item->id]);
        }

        // Delete all the entries in booking_userevents, where we have previously linked users do optiondates and options.
        $DB->delete_records('booking_userevents', ['optionid' => $this->optionid]);

        // Delete comments.
        $DB->delete_records("comments",
                            ['itemid' => $this->optionid,
                            'commentarea' => 'booking_option',
                            'contextid' => $this->booking->get_context()->id,
                            ]);

        // Delete entity relation for the booking option.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            $erhandler->delete_relation($this->optionid);
        }

        // Get existing optiondates (a.k.a. sessions).
        if ($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid])) {
            foreach ($optiondates as $record) {
                // Delete calendar events of sessions (option dates).
                if (!$DB->delete_records('event', ['id' => $record->eventid])) {
                    $result = false;
                }

                // Delete entity relations for each optiondate.
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
                    $optiondateid = $record->id;
                    $erhandler->delete_relation($optiondateid);
                }

                // We also delete the corresponding records in the optiondates_teachers table.
                if (!$DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $record->id])) {
                    $result = false;
                }
                cache_helper::purge_by_event('setbackcachedteachersjournal');
            }
        }

        // Delete optiondate custom fields belonging to the option.
        if (!$DB->delete_records('booking_customfields', ['optionid' => $this->optionid])) {
            $result = false;
        }

        // Delete Moodle custom fields belonging to the option (e.g. "sports" customfield).
        $cfhandler = booking_handler::create();
        $cfhandler->delete_instance($this->optionid);

        // Delete sessions (option dates).
        if (!$DB->delete_records('booking_optiondates', ['optionid' => $this->optionid])) {
            $result = false;
        } else {
            // Also delete associated entries in booking_optiondates_teachers.
            teachers_handler::delete_booking_optiondates_teachers_by_optionid($this->optionid);
        }

        // Delete image files belonging to the option.
        $imgfilesql = "SELECT contextid, filepath, filename, userid, source, author, license
            FROM {files}
            WHERE component = 'mod_booking'
            AND filearea = 'bookingoptionimage'
            AND filesize > 0
            AND mimetype LIKE 'image%'
            AND itemid = :optionid";

        $imgfileparams = [
            'optionid' => $this->optionid,
        ];

        if ($filerecord = $DB->get_record_sql($imgfilesql, $imgfileparams)) {
            $fs = get_file_storage();
            $fileinfo = [
                'component' => 'mod_booking',
                'filearea' => 'bookingoptionimage',
                'itemid' => $this->optionid,
                'contextid' => $filerecord->contextid,
                'filepath' => $filerecord->filepath,
                'filename' => $filerecord->filename,
            ];
            // Get file.
            $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                    $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
            // Delete it if it exists.
            if ($file) {
                $file->delete();
                // Also delete remaining artifacts.
                $DB->delete_records('files', [
                    'component' => 'mod_booking',
                    'filearea' => 'bookingoptionimage',
                    'itemid' => $this->optionid,
                    'contextid' => $filerecord->contextid,
                    'filepath' => $filerecord->filepath,
                ]);
            }
        }

        if (!$DB->delete_records("booking_options", ["id" => $this->optionid])) {
            $result = false;
        }

        $event = event\bookingoption_deleted::create([
                                                    'context' => $this->booking->get_context(),
                                                    'objectid' => $this->optionid,
                                                    'userid' => $USER->id,
                                                    ]);
        $event->trigger();

        // At the very last moment, we purge caches for the option.
        self::purge_cache_for_option($this->optionid);

        return $result;
    }

    /**
     * Change presence status
     *
     * @param array $allselectedusers
     * @param int $presencestatus
     */
    public function changepresencestatus($allselectedusers, $presencestatus) {
        global $DB;

        foreach ($allselectedusers as $ui) {

            $userdata = $DB->get_record_sql(
                "SELECT *
                FROM {booking_answers}
                WHERE optionid = :optionid AND userid = :userid AND waitinglist < 2",
                ['optionid' => $this->optionid, 'userid' => $ui]);

            $userdata->status = $presencestatus;
            $DB->update_record('booking_answers', $userdata);
        }

        // After updating, cache has to be invalidated.
        self::purge_cache_for_option($this->optionid);
    }

    /**
     * Returns, to which booking option user was sent to.
     *
     * @return array
     */
    public function get_other_options() {
        global $DB;
        return $result = $DB->get_records_sql(
                'SELECT obo.id, obo.text, obo.titleprefix, oba.id, oba.userid
                  FROM {booking_answers} oba
             LEFT JOIN {booking_options} obo ON obo.id = oba.optionid
                 WHERE oba.frombookingid = ?',
                [$this->optionid]);
    }

    /**
     * Check if user can enrol.
     *
     * Important notice: As of Booking 8, we use availability conditions to configure if a user can see
     * the book now button. If a user is entitled to book (e.g. an admin or a special user who can always book
     * - which was set with "OR" override conditions) then (s)he can even book if the option is fully booked.
     *
     * @param int $userid
     * @param bool $allowoverbooking
     * @return mixed false if enrolement is not possible, 0 for can book, 1 for waitinglist and 2 for notification list.
     */
    private function check_if_limit(int $userid, bool $allowoverbooking = false) {

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

        // We get the booking information of a specific user.
        $bookingstatus = $bookinganswer->return_all_booking_information($userid);

        // We get different arrays from return_all_booking_information as this is used for template as well.
        // Therefore, we take the one array which actually is present.
        if ($bookingstatus = reset($bookingstatus)) {
            if (isset($bookingstatus['fullybooked'])
            && !$bookingstatus['fullybooked']) {

                $status = MOD_BOOKING_STATUSPARAM_BOOKED;

            } else if (isset($bookingstatus['freeonwaitinglist']) && $bookingstatus['freeonwaitinglist'] > 0) {
                $status = MOD_BOOKING_STATUSPARAM_WAITINGLIST;
            } else {
                if ($allowoverbooking) {
                    $status = MOD_BOOKING_STATUSPARAM_BOOKED;
                } else {
                    $status = false;
                }
            }
        }

        return $status;
    }

    /**
     * Check if at least one user already completed the option. When yes, deleting users is not possible.
     *
     * @return bool
     * @throws dml_exception
     */
    public function user_completed_option() {
        global $DB;
        return $DB->count_records_select('booking_answers', 'optionid = :optionid AND completed = 1',
            ['optionid' => $this->optionid]);
    }

    /**
     * Transfer the booking option including users to another booking option of the same course.
     *
     * @param int $targetcmid
     * @return string error message, empty if no error.
     * @throws coding_exception
     * @throws dml_exception
     * @throws \moodle_exception
     * @throws invalid_parameter_exception
     */
    public function move_option_otherbookinginstance($targetcmid) {
        $error = '';
        list($targetcourse, $targetcm) = get_course_and_cm_from_cmid($targetcmid, 'booking');
        if ($this->booking->course->id !== $targetcourse->id) {
            throw new invalid_parameter_exception("Target booking instance must be in same course");
        }
        $targetbooking = singleton_service::get_instance_of_booking_by_cmid($targetcmid);
        $targetcontext = context_module::instance($targetcmid);
        // Get users of source option.
        // Check for completion errors on unsubscribe.
        if ($this->user_completed_option()) {
            $error = 'You can not move options, when at least one user has the status completed for the option.
            You have to remove the completion status before moving the booking option to another booking instance.';
            return $error;
        }
        // Create target option.
        $newoption = $this->option;
        $newoption->id = -1;
        $newoption->bookingid = $targetbooking->id;
        $newoptionid = self::update($newoption, $targetcontext);
        // Subscribe users.
        $newoption = singleton_service::get_instance_of_booking_option($targetcmid, $newoptionid);
        $users = $this->get_all_users();
        // Unsubscribe users from option.
        $failed = [];
        foreach ($users as $user) {
            $this->user_delete_response($user->userid);
            if (!$newoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED)) {
                $failed[$user->userid] = $user->firstname . ' ' . $user->lastname . ' (' . $user->email . ')';
            }
        }
        if (!empty($failed)) {
            $error .= 'The following users could not be registered to the new booking option:';
            $error .= \html_writer::empty_tag('br');
            $error .= \html_writer::alist($failed);
        }
        // Remove source option.
        $this->delete_booking_option();
        return $error;
    }

    /**
     * Retrieves the global booking settings and returns the customfields
     * string[customfieldname][value]
     * Will return the actual text for the custom field string[customfieldname][type]
     * Will return the type: for now only textfield
     *
     * @return array string[customfieldname][value|type]; empty array if no settings set
     */
    public static function get_customfield_settings() {
        $values = [];
        $bkgconfig = get_config('booking');
        $customfieldvals = \get_object_vars($bkgconfig);
        if (!empty($customfieldvals)) {
            foreach (array_keys($customfieldvals) as $customfieldname) {
                $iscustomfield = \strpos($customfieldname, 'customfield');
                $istype = \strpos($customfieldname, 'type');
                $isoptions = \strpos($customfieldname, 'options');
                if ($iscustomfield !== false && $istype === false && $isoptions === false) {
                    $type = $customfieldname . "type";
                    $options = $customfieldname . "options";
                    $values[$customfieldname]['value'] = $bkgconfig->$customfieldname;
                    $values[$customfieldname]['type'] = $bkgconfig->$type ?? '';
                    $values[$customfieldname]['options'] = $bkgconfig->$options ?? '';
                }
            }
        }
        return $values;
    }

    /**
     * Confirm activity for selected user.
     *
     * @param ?int $userid
     */
    public function confirmactivity(?int $userid = null) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $course = $DB->get_record('course', ['id' => $this->booking->cm->course]);
        $completion = new completion_info($course);
        $cm = get_coursemodule_from_id('booking', $this->cmid, 0, false, MUST_EXIST);

        $suser = null;

        $bookinganswers = singleton_service::get_instance_of_booking_answers($this->settings);

        foreach ($bookinganswers->usersonlist as $key => $value) {
            if ($value->userid == $userid) {
                $suser = $key;
                break;
            }
        }

        if (is_null($suser)) {
            return;
        }

        if ($bookinganswers->usersonlist[$suser]->completed == 0) {
            $userdata = $DB->get_record('booking_answers',
                    ['optionid' => $this->optionid, 'userid' => $userid, 'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED]);
            $userdata->completed = '1';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);

            $countcompleted = $DB->count_records('booking_answers',
                ['bookingid' => $this->booking->id, 'userid' => $userid, 'completed' => '1']);

            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
                $bookingsettings->enablecompletion <= $countcompleted) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }
        }

        // After updating, we have to invalidate cache.
        self::purge_cache_for_option($this->optionid);
    }

    /**
     * Copy this booking option to template.
     */
    public function copytotemplate() {
        global $DB;

        $option = $DB->get_record('booking_options', ['id' => $this->optionid]);

        unset($option->id);
        $option->bookingid = 0;

        $DB->insert_record("booking_options", $option);
    }

    /**
     * This function transform each option date into a separate booking option.
     * The result is going to be a booking option with a single date for each option date present.
     * The original booking option will have the date which is nearest to now.
     *
     * @return void
     */
    public function create_booking_options_from_optiondates(): void {
        $dateobjects = dates_handler::get_existing_optiondates($this->optionid);
        $context = context_module::instance($this->cmid);
        // Check if we have option dates that can be used for creating new options. If there aren't any do nothing.
        if (empty($dateobjects)) {
            return;
        }
        // Modify the existing option to have only one start and end date.
        $dateshandler = new dates_handler($this->optionid, $this->bookingid);
        $dateshandler->delete_all_option_dates();
        $settings = $this->settings;
        $firstrun = true;
        foreach ($dateobjects as $optiondate) {
            $newoption = $settings->return_settings_as_stdclass();
            $newoption->coursestarttime = $optiondate->starttimestamp;
            $newoption->courseendtime = $optiondate->endtimestamp;
            if (!$firstrun) {
                unset($newoption->optionid);
                unset($newoption->id);
                unset($newoption->sessions);
                unset($newoption->optiondate);
                unset($newoption->identifier);
            }
            self::update($newoption, $context);
            $firstrun = false;
        }
    }

    /**
     * Print custom report.
     *
     * @return void
     *
     */
    public function printcustomreport() {
        global $CFG;

        include_once($CFG->dirroot . '/mod/booking/TinyButStrong/tbs_class.php');
        include_once($CFG->dirroot . '/mod/booking/OpenTBS/tbs_plugin_opentbs.php');

        $tbs = new \clsTinyButStrong;
        $tbs->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);
        $tbs->NoErr = true;

        list($course, $cm) = get_course_and_cm_from_cmid($this->cmid);
        $context = \context_module::instance($this->cmid);
        $coursecontext = \context_course::instance($course->id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        $booking = [
            'name' => $bookingsettings->name,
            'eventtype' => $bookingsettings->eventtype,
            'duration' => $bookingsettings->duration,
            'organizatorname' => $bookingsettings->organizatorname,
            'pollurl' => $bookingsettings->pollurl,
            'pollurlteachers' => $bookingsettings->pollurlteachers,
        ];
        $bu = new booking_utils();
        $option = [
            'name' => $this->option->text,
            'location' => $this->option->location,
            'institution' => $this->option->institution,
            'address' => $this->option->address,
            'maxanswers' => $this->option->maxanswers,
            'maxoverbooking' => $this->option->maxoverbooking ?? 0,
            'minanswers' => $this->option->minanswers,
            'bookingopeningtime' => ($this->option->bookingopeningtime == 0 ? get_string('nodateset', 'mod_booking') : userdate(
                $this->option->bookingopeningtime, get_string('strftimedatetime', 'langconfig'))),
            'bookingclosingtime' => ($this->option->bookingclosingtime == 0 ? get_string('nodateset', 'mod_booking') : userdate(
                $this->option->bookingclosingtime, get_string('strftimedatetime', 'langconfig'))),
            'duration' => $bu->get_pretty_duration($this->option->duration),
            'coursestarttime' => ($this->option->coursestarttime == 0 ? get_string('nodateset', 'mod_booking') : userdate(
                $this->option->coursestarttime, get_string('strftimedatetime', 'langconfig'))),
            'courseendtime' => ($this->option->courseendtime == 0 ? get_string('nodateset', 'mod_booking') : userdate(
                $this->option->courseendtime, get_string('strftimedatetime', 'langconfig'))),
            'pollurl' => $this->option->pollurl,
            'pollurlteachers' => $this->option->pollurlteachers,
        ];

        $allusers = $this->get_all_users();
        $allteachers = $this->get_teachers();

        $users = [];
        foreach ($allusers as $key => $value) {
            $users[] = [
                'id' => $value->userid,
                'firstname' => $value->firstname,
                'lastname' => $value->lastname,
                'email' => $value->email,
                'institution' => $value->institution,
            ];
        }

        $teachers = [];
        foreach ($allteachers as $key => $value) {
            $teachers[] = [
                'id' => $value->userid,
                'firstname' => $value->firstname,
                'lastname' => $value->lastname,
                'email' => $value->email,
                'institution' => $value->institution,
            ];
        }

        $fs = get_file_storage();

        $files = $fs->get_area_files($coursecontext->id, 'mod_booking', 'templatefile',
            $bookingsettings->customtemplateid, 'sortorder,filepath,filename', false);

        if ($files) {
            $file = reset($files);

            // Get file.
            $file = $fs->get_file($coursecontext->id, 'mod_booking', 'templatefile',
            $bookingsettings->customtemplateid, $file->get_filepath(), $file->get_filename());
        }

        $ext = pathinfo($file->get_filename(), PATHINFO_EXTENSION);
        $filename = uniqid(rand(), false);

        $tempfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename . ".{$ext}";

        $handle = fopen($tempfile, "w");
        fwrite($handle, $file->get_content());
        fclose($handle);

        $tbs->LoadTemplate($tempfile, OPENTBS_ALREADY_UTF8);

        $tbs->PlugIn(OPENTBS_SELECT_MAIN);
        $tbs->MergeField('booking', $booking);
        $tbs->MergeField('option', $option);
        $tbs->MergeBlock('users', $users);
        $tbs->MergeBlock('teachers', $teachers);

        $tbs->LoadTemplate('#styles.xml');
        $tbs->MergeField('booking', $booking);
        $tbs->MergeField('option', $option);
        $tbs->MergeBlock('users', $users);
        $tbs->MergeBlock('teachers', $teachers);

        $tbs->Show(OPENTBS_STRING);
        $tempfilefull = $tbs->Source;

        $fullfile = [
            'contextid' => $coursecontext->id, // ID of context.
            'component' => 'mod_booking',     // Usually = table name.
            'filearea' => 'templatefile',     // Usually = table name.
            'itemid' => 0,               // Usually = ID of row in table.
            'filepath' => '/',           // Any path beginning and ending in '/'.
            'filename' => "{$filename}.{$ext}", // Any filename.
        ];

        // Create file containing text 'hello world'.
        $newfile = $fs->create_file_from_string($fullfile, $tbs->Source);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $converter = new \core_files\converter();
        $conversion = $converter->start_conversion($newfile, 'pdf', true);

        if ($conversion->get_destfile() !== false) {
            header('Content-Disposition: attachment; filename="' . $conversion->get_destfile()->get_filename() . '.pdf"');
            echo $conversion->get_destfile()->get_content();
            $conversion->get_destfile()->delete();
        } else {
            header('Content-Disposition: attachment; filename="' . $newfile->get_filename() . '"');
            echo $newfile->get_content();
        }

        unlink($tempfile);
        $newfile->delete();

        exit();
    }

    /**
     * Central function to return a list of booking options with all possible filters applied.
     * Default is a list of all booking options from the whole site.
     *
     * @param int $bookingid // Should be set.
     * @param array $filters
     * @param string $fields
     * @param string $from
     * @param string $where
     * @param array $params
     * @param string $order
     *
     * @return array
     */
    public static function search_all_options_sql($bookingid = 0,
                                    $filters = [],
                                    $fields = '*',
                                    $from = '',
                                    $where = '',
                                    $params = [],
                                    $order = 'ORDER BY bo.id ASC'): array {
        $from = $from ?? '{booking_options} bo
                        JOIN {customfield_data} cfd
                        ON bo.id=cfd.instanceid
                        JOIN {customfield_field} cff
                        ON cfd.fieldid=cff.id';

        // If there is no booking id, we look for all booking options.
        if (isset($bookingid)) {
            $where = $where ?? 'bookingid=:bookingid';
            $params['bookingid'] = $bookingid;
        }
        return [$fields, $from, $where, $params, $order];
    }

    /**
     * Apply filters.
     * Currently this function is not used.
     *
     * @return void
     */
    public function apply_filters() {
    }

    /**
     * Send message: Poll URL.
     *
     * @param array $userids the selected userids
     *
     * @return void
     */
    public function sendmessage_pollurl(array $userids) {
        global $DB;

        foreach ($userids as $userid) {

            // Use message controller to send the Poll URL to every selected user.
            $messagecontroller = new message_controller(
                MOD_BOOKING_MSGCONTRPARAM_SEND_NOW, MOD_BOOKING_MSGPARAM_POLLURL_PARTICIPANT,
                $this->cmid, $this->optionid, $userid, $this->bookingid
            );
            $messagecontroller->send_or_queue();
        }

        $dataobject = new stdClass();
        $dataobject->id = $this->optionid;
        $dataobject->pollsend = 1;

        $DB->update_record('booking_options', $dataobject);

        // After updating, we have to invalidate cache.
        cache_helper::invalidate_by_event('setbackoption', [$this->optionid]);
    }

    /**
     * Send message: Poll URL for teachers.
     *
     * @return void
     */
    public function sendmessage_pollurlteachers() {
        global $DB;

        $teachers = $DB->get_records("booking_teachers",
                ["optionid" => $this->optionid, 'bookingid' => $this->bookingid]);

        foreach ($teachers as $teacher) {

            // Use message controller to send the Poll URL to teacher(s).
            $messagecontroller = new message_controller(
                MOD_BOOKING_MSGCONTRPARAM_SEND_NOW, MOD_BOOKING_MSGPARAM_POLLURL_TEACHER,
                $this->cmid, $this->optionid, $teacher->userid, $this->bookingid
            );
            $messagecontroller->send_or_queue();
        }
    }

    /**
     * Send notifications function for different types of notifications.
     * @param int $messageparam the message type
     * @param array $tousers
     * @param ?int $optiondateid optional (needed for session reminders only)
     * @throws coding_exception
     * @throws dml_exception
     */
    public function sendmessage_notification(int $messageparam, array $tousers = [], ?int $optiondateid = null) {

        $allusers = [];

        $bookingoption = singleton_service::get_instance_of_booking_option($this->cmid, $this->optionid);
        $bookingoption->apply_tags(); // Do we need this here?

        if (!empty($tousers)) {
            foreach ($tousers as $currentuserid) {
                $tmpuser = new stdClass();
                $tmpuser->id = $currentuserid;
                $allusers[$currentuserid] = $tmpuser;
            }
        } else {
            // Send to all booked users if we have an empty $tousers array.
            // Also make sure that teacher reminders won't be send to booked users.
            $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
            $answers = singleton_service::get_instance_of_booking_answers($settings);
            if (!empty($answers->usersonlist) && $messageparam !== MOD_BOOKING_MSGPARAM_REMINDER_TEACHER) {
                foreach ($answers->usersonlist as $currentuser) {
                    $tmpuser = new stdClass();
                    $tmpuser->id = $currentuser->userid;
                    $allusers[] = $tmpuser;
                }
            } else {
                $allusers = [];
            }
        }

        foreach ($allusers as $user) {

            $messagecontroller = new message_controller(
                MOD_BOOKING_MSGCONTRPARAM_SEND_NOW, $messageparam, $this->cmid, $this->optionid, $user->id,
                $this->bookingid, $optiondateid
            );
            $messagecontroller->send_or_queue();
        }
    }

    /**
     * Send a message to the user who has completed the booking option.
     * Triggered by the event bookingoption_completed and executed by the
     * function bookingoption_completed in observer.php
     *
     * @param int $userid
     * @throws coding_exception
     * @throws dml_exception
     */
    public function sendmessage_completed(int $userid) {

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($this->cmid);

        // We DO NOT send if global mail templates are activated for the instance.
        // We use the new global booking rules instead.
        if (isset($bookingsettings->mailtemplatessource) && $bookingsettings->mailtemplatessource == 1) {
            return;
        }

        $taskdata = [
            'userid' => $userid,
            'optionid' => $this->optionid,
            'cmid' => $this->cmid,
        ];

        $sendtask = new send_completion_mails();
        $sendtask->set_custom_data($taskdata);
        \core\task\manager::queue_adhoc_task($sendtask);
    }

    /**
     * Get the user status as a string.
     *
     * @param int $userid userid of the user
     * @param ?int $statusparam optional statusparam if we already know it
     * @return string localized string of user status
     */
    public function get_user_status_string(int $userid, ?int $statusparam = null) {

        if ($statusparam === null) {
            $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
            $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
            $statusparam = $bookinganswers->user_status($userid);
        }

        switch ($statusparam) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                $status = get_string('booked', 'booking');
                break;
            case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                $status = get_string('notbooked', 'booking');
                break;
            case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                $status = get_string('onwaitinglist', 'booking');
                break;
            default:
                $status = get_string('notbooked', 'booking');
                break;
        }

        return $status;
    }

    /**
     * Helper function for mustache template to return array with datestring and customfields
     *
     * @param object $bookingevent
     * @param int $descriptionparam
     * @param bool $withcustomfields
     * @param bool $forbookeduser
     * @param bool $ashtml
     *
     * @return array
     * @throws \dml_exception
     */
    public function return_array_of_sessions($bookingevent = null,
                                            $descriptionparam = 0,
                                            $withcustomfields = false,
                                            $forbookeduser = false,
                                            $ashtml = false) {

        // If we didn't set a $bookingevent (record from booking_optiondates) we retrieve all of them for this option.
        // Else, we check if there are sessions.
        // If not, we just use normal coursestart & endtime.
        if ($bookingevent) {
            $data = dates_handler::prettify_datetime($bookingevent->coursestarttime, $bookingevent->courseendtime);
            $data->id = $bookingevent->id;
            $sessions = [$data];
        } else {
            $sessions = dates_handler::return_dates_with_strings($this->settings, '', false, $ashtml);
        }

        $returnarray = [];

        foreach ($sessions as $date) {

            $returnsession = [
                'datestring' => $date->datestring,
            ];

            // 0 in a date id means it comes form normal course start & endtime.
            // Therefore, there can't be these customfields.
            if ($withcustomfields && $date->id !== 0) {
                // TODO: Can we cache this?
                // Filter the matching customfields.
                $returnsession['customfields'] = self::return_array_of_customfields(
                    $this->settings->sessioncustomfields,
                    $date->id,
                    $descriptionparam,
                    $forbookeduser);
            }

            if (class_exists('local_entities\entitiesrelation_handler')) {
                $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate', $date->id);
                $entity = $erhandler->get_instance_data($date->id);
                $entityid = $erhandler->get_entityid_by_instanceid($date->id);
                $entityurl = null; // Important: initialize!
                if (!empty($entityid)) {
                    $entityurl = new moodle_url('/local/entities/view.php', ['id' => $entityid]);
                }
                if (!empty($entity->parentname)) {
                    $entityfullname = "$entity->parentname ($entity->name)";
                } else if (!empty($entity->name)) {
                    $entityfullname = $entity->name;
                }
                if (!empty($entityurl) && !empty($entityfullname)) {
                    $returnsession['entitylink'] = html_writer::link($entityurl->out(false), $entityfullname,
                        ['target' => '_blank']);
                }
            }

            if (!empty($date->htmlstring)) {
                $returnsession['htmlstring'] = $date->htmlstring;
            }

            $returnarray[] = $returnsession;
        }

        return $returnarray;
    }

    /**
     * Helper function to return array with name - value items for mustache templates
     * $fields must be records from booking_customfields
     * @param array $fields
     * @param int $sessionid
     * @param int $descriptionparam
     * @param bool $forbookeduser
     * @return array
     */
    public static function return_array_of_customfields($fields, $sessionid = 0,
            $descriptionparam = 0, $forbookeduser = false) {

        $returnarray = [];
        foreach ($fields as $field) {
            if ($field->optiondateid != $sessionid) {
                continue;
            }
            $settings = singleton_service::get_instance_of_booking_option_settings($field->optionid);
            $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $field->optionid);
            if ($value = $bookingoption->render_customfield_data($field, $sessionid,
                $descriptionparam, $forbookeduser)) {
                $returnarray[] = $value;
            }
        }
        return $returnarray;
    }

    /**
     * This function is meant to return the right name and value array for custom fields.
     * This is the place to return buttons etc. for special name, keys, like teams-meeting or zoom meeting.
     * @param stdClass $field
     * @param int $sessionid
     * @param int $descriptionparam
     * @param bool $forbookeduser
     */
    public function render_customfield_data(
            $field,
            $sessionid = 0,
            $descriptionparam = 0,
            $forbookeduser = false) {

        // At first, we handle special meeting fields.
        // The regex will match ZoomMeeting, BigBlueButton-Meeting, Teams meeting etc.
        if (preg_match('/^((zoom)|(big.*blue.*button)|(teams)).*meeting$/i', $field->cfgname)) {
            // If the session is not yet about to begin, we show placeholder.
            return $this->render_meeting_fields($sessionid, $field, $descriptionparam, $forbookeduser);
        }

        switch ($field->cfgname) {
            case 'addcomment':
                return [
                    'name' => "",
                    'value' => $field->value,
                ];
            default:
                return [
                    'name' => "$field->cfgname: ",
                    'value' => $field->value,
                ];
        }
    }

    /**
     * Render meeting fields
     *
     * @param int $sessionid
     * @param stdClass $field
     * @param int $descriptionparam
     * @param bool $forbookeduser
     *
     * @return array
     */
    private function render_meeting_fields(int $sessionid, stdClass $field, int $descriptionparam,
        bool $forbookeduser = false): array {

        global $CFG;

        $baseurl = $CFG->wwwroot;

        switch ($descriptionparam) {

            case MOD_BOOKING_DESCRIPTION_WEBSITE:
            case MOD_BOOKING_DESCRIPTION_OPTIONVIEW:
                // We don't want to show these Buttons at all if the user is not booked.
                if (!$forbookeduser) {
                    return [];
                } else {
                    // We are booked on the web site, we check if we show the real link.
                    if (!$this->show_conference_link($sessionid)) {
                        // User is booked, if the user is booked, but event not yet open, we show placeholder with time to start.
                        return [
                                'name' => null,
                                'value' => get_string('linknotavailableyet', 'mod_booking'),
                        ];
                    }
                    // User is booked and event open, we return the button with the link to access, this is for the website.
                    return [
                            'name' => null,
                            'value' => "<a href=$field->value class='btn btn-secondary booking-meetinglink-btn'>"
                                . $field->cfgname . "</a>",
                    ];
                };
            case MOD_BOOKING_DESCRIPTION_CALENDAR:
                // Calendar is static, so we don't have to check for booked or not.
                // In all cases, we return the Teams-Button, going by the link.php.
                if ($forbookeduser) {
                    // User is booked, we show a button (for Moodle calendar ie).
                    $cm = $this->booking->cm;
                    $moodleurl = new moodle_url($baseurl . '/mod/booking/link.php',
                            ['id' => $cm->id,
                                    'optionid' => $this->optionid,
                                    'action' => 'join',
                                    'sessionid' => $sessionid,
                                    'fieldid' => $field->id,
                            ]);
                    $encodedlink = booking::encode_moodle_url($moodleurl);

                    return [
                            'name' => null,
                            'value' => "<a href=$encodedlink class='btn btn-secondary booking-meetinglink-btn'>" .
                                $field->cfgname . "</a>",
                    ];
                } else {
                    return [];
                }
            case MOD_BOOKING_DESCRIPTION_ICAL:
                // User is booked, for ical no button but link only.
                // For ical, we don't check for booked as it's always booked only.
                $cm = $this->booking->cm;
                $link = new moodle_url($baseurl . '/mod/booking/link.php',
                        ['id' => $cm->id,
                                'optionid' => $this->optionid,
                                'action' => 'join',
                                'sessionid' => $sessionid,
                                'fieldid' => $field->id,
                        ]);
                $link = $link->out(false);
                return [
                        'name' => null,
                        'value' => "$field->cfgname: $link",
                ];
            case MOD_BOOKING_DESCRIPTION_MAIL:
                // For the mail placeholder {bookingdetails} no button but link only.
                // However, we can use HTML links in mails.
                $cm = $this->booking->cm;
                $link = new moodle_url($baseurl . '/mod/booking/link.php',
                    ['id' => $cm->id,
                        'optionid' => $this->optionid,
                        'action' => 'join',
                        'sessionid' => $sessionid,
                        'fieldid' => $field->id,
                    ]);
                $link = $link->out(false);
                return [
                    'name' => null,
                    'value' => "$field->cfgname: <a href='$link' target='_blank'>$link</a>",
                ];
            default:
                return [];
        }
    }

    /**
     * Function to return false if user has not yet the right to access conference
     * Returns the link if the user has the right
     * time before course start is hardcoded to 15 minutes
     *
     * @param ?int $sessionid
     *
     * @return bool
     */
    public function show_conference_link(?int $sessionid = null): bool {

        global $USER;

        // First check if user is really booked.
        $bookinganswers = booking_answers::get_instance_from_optionid($this->optionid);

        if ($bookinganswers->user_status($USER->id) != MOD_BOOKING_STATUSPARAM_BOOKED) {
                return false;
        }

        $now = time();
        $openingtime = strtotime("+15 minutes", $now);

        if (!$sessionid) {
            $start = $this->settings->coursestarttime;
            $end = $this->settings->courseendtime;
        } else {
            $start = $this->settings->sessions[$sessionid]->coursestarttime;
            $end = $this->settings->sessions[$sessionid]->courseendtime;
        }

        // If now plus 15 minutes is smaller than coursestarttime, we return the link.
        if ($start < $openingtime
            && $end > $now) {
            return true;
        } else {
            // If we return false here, we first have to calculate secondstostart.
            $delta = $start - $now;

            if ($delta < 0) {
                $this->secondspassed = - $delta;
            } else {
                $this->secondstostart = $delta;
            }
            return false;
        }
    }

    /**
     * Takes the user on the notification list or off it, depending on the actual status at the moment.
     * Returns the status and error, if there is any.
     *
     * @param int $userid
     * @param int $optionid
     * @return array
     */
    public static function toggle_notify_user(int $userid, int $optionid) {

        global $USER, $DB;

        $error = '';
        $status = null;

        $booking = singleton_service::get_instance_of_booking_by_optionid($optionid);

        $context = context_module::instance($booking->cmid);

         // If the given user tries this for somebody else, the user has to have the rights of access.
        if ($USER->id != $userid
            && !has_capability('mod/booking:subscribeusers', $context)) {
            $status = 0;
            $optionid = 0;
            $error = get_string('accessdenied', 'mod_booking');
        } else {
            // If the user does this for herself or she has the right to do it for others, we toggle the state.

            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* booking_bookit::answer_booking_option('option', $optionid, MOD_BOOKING_STATUSPARAM_NOTIFYMELIST, $userid); */
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

            if (!$bookinganswer->user_on_notificationlist($userid)) {
                self::write_user_answer_to_db($booking->id,
                                                    0,
                                                    $userid,
                                                    $optionid,
                                                    MOD_BOOKING_STATUSPARAM_NOTIFYMELIST);
                $status = 1;
            } else {
                // As the deletion here has no further consequences, we can do it directly in DB.
                $DB->delete_records('booking_answers',
                                    ['userid' => $userid,
                                    'optionid' => $optionid,
                                    'waitinglist' => MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
                                    ]);

                // Do not forget to purge cache afterwards.
                self::purge_cache_for_option($optionid);

                $status = 0;
            }
        }

        return [
            'status' => $status,
            'optionid' => $optionid,
            'error' => $error,
        ];
    }

    /**
     * Function to cancel a booking option.
     * This does not delete, but only makes in unbookable and specially marked.
     *
     * @param int $optionid
     * @param string $cancelreason
     * @param bool $undo
     * @return void
     */
    public static function cancelbookingoption(int $optionid, string $cancelreason = '', bool $undo = false) {

        global $DB, $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $now = time();

        $record = $DB->get_record('booking_options', ['id' => $optionid]);

        // Add reason to internal notes.

        if (!$undo) {
            $record->status = 1;
            list($date) = explode(' - ', dates_handler::prettify_optiondates_start_end($now, 0, current_language()));
            $userstring = "$USER->firstname $USER->lastname";
            $record->annotation .= " <br> " . $date;
            $record->annotation .= " <br> " . get_string('usergavereason', 'mod_booking', $userstring);
            $record->annotation .= " <br>  " . $cancelreason . "<br>";
        } else {
            $record->status = 0;
        }

        // Update booking settings.

        $DB->update_record('booking_options', $record);

        $context = context_module::instance($settings->cmid);

        /* Fixed: We do not trigger bookingoption_updated here, because we do no want to trigger
        both change notifications and cancelling notifications at once. */

        if (!$undo) {
            $context = context_module::instance($settings->cmid);
            $event = \mod_booking\event\bookingoption_cancelled::create([
                                                                        'context' => $context,
                                                                        'objectid' => $optionid,
                                                                        'userid' => $USER->id,
                                                                        ]);
            $event->trigger();
            // Deletion of booking answers and user events needs to happen in event observer.
        }

        // At the end, we have to invalidate caches!
        self::purge_cache_for_option($optionid);
    }

    /**
     * Helper function to get the quota of consumed sessions
     * or of consumed time (for events with no sessions or with one session only).
     * For events with neither sessions nor coursestarttime nor endtime,
     * the consumed quota will always be 0.
     *
     * @param int $optionid
     * @return float $consumedquota 0.0 = nothing consumed, 0.5 half consumed, 1.0 completely consumed
     *
     */
    public static function get_consumed_quota(int $optionid) {

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $now = time();
        $consumedquota = 0.0;

        if (empty($optionsettings->sessions)) {
            return $consumedquota;
        }

        if (count($optionsettings->sessions) == 1) {
            // Single-session.
            $session = array_pop($optionsettings->sessions);

            // If there's only one session and it's already over, then we count it as consumed.
            if ($session->courseendtime < $now) {
                $consumedquota = 1.0;
            } else if ($session->coursestarttime > $now) {
                // If it has not yet started, the quota is 0.
                $consumedquota = 0;
            } else {
                // Overall duration of the single session.
                $sessionduration = $session->courseendtime - $session->coursestarttime;
                // Consumed duration of the single session.
                $consumedduration = $now - $session->coursestarttime;
                // Now calculate consumed quota.
                $consumedquota = (float) round($consumedduration / $sessionduration, 2);
            }
        } else {
            // Multi-session.
            $sessioncount = count($optionsettings->sessions);
            // Count consumed sessions.
            $consumedsessioncount = 0;
            foreach ($optionsettings->sessions as $session) {
                if ($session->courseendtime < $now) {
                    $consumedsessioncount++;
                }
            }
            // Now calculate consumed quota.
            $consumedquota = (float) round($consumedsessioncount / $sessioncount, 2);
        }

        return $consumedquota;
    }

    /**
     * Helper function to get HTML for a progressbar showing the consumed quota.
     *
     * @param int $optionid option id
     * @param string $barcolor the bootstrap color for the progress bar, e.g. "primary", "success", "info", "danger"...
     * @param string $percentagecolor the bootstrap color for the percentage label, e.g. "primary", "success", "info", "danger"...
     * @param bool $collapsible if true the progress bar can be collapsed, default is true
     *
     * @return string $html the HTML containing the progress bar
     */
    public static function get_progressbar_html(int $optionid, string $barcolor = "primary",
        string $percentagecolor = "white", bool $collapsible = true) {

        $html = '';
        $icon = "<i class='fa fa-hourglass' aria-hidden='true'></i>";

        $alreadypassed = get_string('alreadypassed', 'mod_booking');
        $consumedpercentage = self::get_consumed_quota($optionid) * 100;
        if ($consumedpercentage > 0 && $consumedpercentage <= 100) {

            $progressbar =
                "<div class='progress'>
                    <div class='progress-bar progress-bar-striped bg-$barcolor' role='progressbar'
                    style='width: $consumedpercentage%' aria-valuenow='$consumedpercentage'
                    aria-valuemin='0' aria-valuemax='100'>
                        <span class='text-$percentagecolor'>$consumedpercentage%</span>
                    </div>
                </div>";

            if ($collapsible) {
                // Show collapsible progressbar.
                $html .=
                    "<p class='mb-0 mt-1'>
                        $icon <a data-toggle='collapse' href='#progressbarContainer$optionid' role='button'
                        aria-expanded='false' aria-controls='progressbarContainer$optionid'>$alreadypassed: $consumedpercentage%</a>
                    </p>
                    <div class='collapse' id='progressbarContainer$optionid'>
                        $progressbar
                    </div>";
            } else {
                // Show progressbar with a label.
                $html .=
                    "<div class='progressbar-label mb-0 mt-1'>
                        $icon $alreadypassed:
                    </div>
                    $progressbar";
            }
        }

        return $html;
    }

    /**
     * Helper function to purge cache for a booking option.
     * @param int $optionid
     */
    public static function purge_cache_for_option(int $optionid) {

        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::invalidate_by_event('setbackoptionsettings', [$optionid]);

        // We also need to destroy outdated singletons.
        singleton_service::destroy_booking_option_singleton($optionid);

        // We also purge the answers cache.
        self::purge_cache_for_answers($optionid);
    }

    /**
     * Helper function to purge cache for a booking option.
     * @param int $optionid
     */
    public static function purge_cache_for_answers(int $optionid) {

        cache_helper::invalidate_by_event('setbackoptionsanswers', [$optionid]);
        // When we set back the booking_answers...
        // ... we have to make sure it's also deleted in the singleton service.
        singleton_service::destroy_booking_answers($optionid);

        // We also need to destroy the booked_user_information.
        cache_helper::purge_by_event('setbackbookedusertable');

        // We also need to destroy the booked_user_information.
        cache_helper::purge_by_event('setbackmyoptionstable');
    }

    /**
     * Return the cancel until date for an option.
     * This is calculated by the corresponding setting in booking instance
     * and the coursestarttime.
     *
     * If the config setting booking/canceldependenton is set to "semesterstart"
     * then we use the semester start instead of coursestarttime.
     *
     * @param int $optionid
     * @return int
     */
    public static function return_cancel_until_date($optionid) {

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);

        // If the option itself has a canceluntil date, we always use this one.
        if (!empty($optionsettings->canceluntil)) {
            return (int)$optionsettings->canceluntil;
        }

        $canceluntil = 0;

        // Default: We use the booking option coursestarttime field.
        $starttime = $optionsettings->coursestarttime;

        if (empty($starttime)) {
            return 0;
        }

        // We check on which date field it's dependent on.
        if (get_config('booking', 'canceldependenton') == "semesterstart") {
            if (!empty($bookingsettings->semesterid)) {
                $semester = new semester($bookingsettings->semesterid);
                $starttime = $semester->startdate;
                if (empty($starttime)) {
                    throw new moodle_exception("Setting 'booking/canceldependenton' is dependent on semester start " .
                        "but no semester could be found.");
                }
            } else {
                throw new moodle_exception("Setting 'booking/canceldependenton' is dependent on semester start " .
                        "but no semester could be found.");
            }
        } else if (get_config('booking', 'canceldependenton') == "bookingopeningtime"
            && !empty($optionsettings->bookingopeningtime)) {
            $starttime = $optionsettings->bookingopeningtime;
        } else if (get_config('booking', 'canceldependenton') == "bookingclosingtime"
            && !empty($optionsettings->bookingclosingtime)) {
            $starttime = $optionsettings->bookingclosingtime;
        }

        $allowupdatedays = $bookingsettings->allowupdatedays;
        if (isset($allowupdatedays) && $allowupdatedays != 10000 && !empty($starttime)) {
            // Different string depending on plus or minus.
            if ($allowupdatedays >= 0) {
                $datestring = " - $allowupdatedays days";
            } else {
                $allowupdatedays = abs($allowupdatedays);
                $datestring = " + $allowupdatedays days";
            }
            $canceluntil = strtotime($datestring, $starttime);
        } else {
            $canceluntil = 0;
        }

        return $canceluntil;
    }

    /**
     * Helper function to check if an option allows overbooking.
     *
     * @param int $optionid
     * @return bool true if overbooking is allowed
     */
    public static function option_allows_overbooking_for_user(int $optionid): bool {

        /* If the global setting to allow overbooking is on, we still need to check
        if the current user has the capability to overbook. */
        if (get_config('booking', 'allowoverbooking')
            && has_capability('mod/booking:canoverbook', context_system::instance())) {
            return true;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (!empty($settings->availability)) {
            foreach (json_decode($settings->availability) as $ac) {
                /* When the fullybooked condition is present as an override condition in combination
                with an "OR" operator, we want to allow overbooking. */
                if (isset($ac->id)
                    && isset($ac->overrideoperator) && $ac->overrideoperator === 'OR'
                    && isset($ac->overrides) && in_array("" . MOD_BOOKING_BO_COND_FULLYBOOKED . "", $ac->overrides)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Function to lazyload a list of booking options for autocomplete.
     *
     * @param string $query
     * @return array
     */
    public static function load_booking_options(string $query) {

        global $DB;

        $values = explode(' ', $query);

        $fullsql = $DB->sql_concat(
            '\' \'', 'bo.id', '\' \'', 'bo.titleprefix', '\' \'', 'bo.text', '\' \'', 'b.name', '\' \''
        );

        $sql = "SELECT * FROM (
                    SELECT bo.id, bo.titleprefix, bo.text, b.name instancename, $fullsql AS fulltextstring
                    FROM {booking_options} bo
                    LEFT JOIN {booking} b
                    ON bo.bookingid = b.id
                ) AS fulltexttable";

        if (!empty($query)) {
            // We search for every word extra to get better results.
            $firstrun = true;
            $counter = 1;
            foreach ($values as $value) {

                $sql .= $firstrun ? ' WHERE ' : ' AND ';
                $sql .= " " . $DB->sql_like('fulltextstring', ':param' . $counter, false) . " ";
                // If it's numeric, we search for the full number - so we need to add blanks.
                $params['param' . $counter] = is_numeric($value) ? "% $value %" : "%$value%";
                $firstrun = false;
                $counter++;
            }
        }

        // We don't return more than 100 records, so we don't need to fetch more from db.
        $sql .= " limit 102";

        $rs = $DB->get_recordset_sql($sql, $params);
        $count = 0;
        $list = [];

        foreach ($rs as $record) {
            $optiondata = (object)[
                'id' => $record->id,
                'titleprefix' => $record->titleprefix,
                'text' => $record->text,
                'instancename' => $record->instancename,
            ];

            $count++;
            $list[$record->id] = $optiondata;
        }

        $rs->close();

        return [
            'warnings' => count($list) > 100 ? get_string('toomanytoshow', 'mod_booking', '> 100') : '',
            'list' => count($list) > 100 ? [] : $list,
        ];
    }

    /**
     * Helper function to generate mailto-Link for all booked users of a booking option.
     * TO: The teacher sending the mail (logged in user by default).
     * BCC: The booked participants of the booking option.
     *
     * @param int $optionid
     * @return string the mailto link - will be empty if there are no booked users
     */
    public static function get_mailto_link_for_partipants(int $optionid): string {
        global $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $bookedusers = $answers->usersonlist;

        // Use the booking option title as subject.
        $subject = str_replace(' ', '%20', $settings->get_title_with_prefix());

        if (empty($bookedusers)) {
            return '';
        }

        $teachersstring = '';
        if (!empty($settings->teachers)) {
            foreach ($settings->teachers as $t) {
                if (!empty($t->email) && ($t->email != $USER->email)) {
                    $teachersstring .= "$t->email;";
                }
            }
            if ($teachersstring) {
                $teachersstring = trim($teachersstring, ';');
                $teachersstring = "cc=$teachersstring&";
            }
        }

        $emailstring = '';
        foreach ($bookedusers as $bu) {
            $user = singleton_service::get_instance_of_user($bu->userid);
            if (!empty($user->email)) {
                $emailstring .= "$user->email;";
            }
        }
        $emailstring = trim($emailstring, ';');

        if (empty($emailstring)) {
            return '';
        }

        // We put all teachers in CC and all participants in BCC.
        return str_replace('amp;', '',
            htmlspecialchars("mailto:$USER->email?$teachersstring" . "bcc=$emailstring&subject=$subject",
            ENT_QUOTES));
    }

    /**
     * Function to load all params used by {placeholders} (e.g. for mail templates).
     * @param int $optionid option id
     * @param int $userid optional user id, if not provided the logged in $USER will be used
     */
    public static function get_placeholder_params(int $optionid, int $userid = 0) {

        global $CFG, $USER;

        $params = new stdClass();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        if (empty($userid)) {
            $userid = $USER->id;
        }
        $user = singleton_service::get_instance_of_user($userid);

        $timeformat = get_string('strftimetime', 'langconfig');
        $dateformat = get_string('strftimedate', 'langconfig');

        $courselink = '';
        if ($settings->courseid) {
            $courselink = new \moodle_url('/course/view.php', ['id' => $settings->courseid]);
            $courselink = \html_writer::link($courselink, $courselink->out());
        }
        $bookinglink = new \moodle_url('/mod/booking/view.php', ['id' => $cmid]);
        $bookinglink = \html_writer::link($bookinglink, $bookinglink->out());

        // We add the URLs for the user to subscribe to user and course event calendar.
        $bu = new booking_utils();

        // These links will not be clickable (beacuse they will be copied by users).
        $params->usercalendarurl = '<a href="#" style="text-decoration:none; color:#000">' .
        $bu->booking_generate_calendar_subscription_link($user, 'user') .
        '</a>';

        $params->coursecalendarurl = '<a href="#" style="text-decoration:none; color:#000">' .
        $bu->booking_generate_calendar_subscription_link($user, 'courses') .
        '</a>';

        // Add a placeholder with a link to go to the current booking option.
        $gotobookingoptionlink = new \moodle_url($CFG->wwwroot . '/mod/booking/view.php', [
            'id' => $cmid,
            'optionid' => $optionid,
            'whichview' => 'showonlyone',
        ]);
        $params->gotobookingoption = \html_writer::link($gotobookingoptionlink, $gotobookingoptionlink->out());

        // Important: We have to delete answers cache before calling $bookinganswer->user_status.
        $cache = \cache::make('mod_booking', 'bookingoptionsanswers');
        $data = $cache->delete($optionid);
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
        $params->status = $bookingoption->get_user_status_string($userid, $bookinganswer->user_status($userid));

        $params->qrid = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
            rawurlencode($userid) . '&choe=UTF-8" title="Link to Google.com" />';
        $params->qrusername = isset($user->username) ?
            '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
            rawurlencode($user->username) . '&choe=UTF-8" title="QR encoded username" />' : '';

        $params->participant = fullname($user);
        $params->email = $user->email ?? '';
        $params->title = format_string($settings->get_title_with_prefix());
        $params->duration = $bookingsettings->duration;
        $params->starttime = $settings->coursestarttime ?
            userdate($settings->coursestarttime, $timeformat) : '';
        $params->endtime = $settings->courseendtime ?
            userdate($settings->courseendtime, $timeformat) : '';
        $params->startdate = $settings->coursestarttime ?
            userdate($settings->coursestarttime, $dateformat) : '';
        $params->enddate = $settings->courseendtime ?
            userdate($settings->courseendtime, $dateformat) : '';
        $params->courselink = $courselink;
        $params->bookinglink = $bookinglink;
        $params->location = $settings->location;
        $params->institution = $settings->institution;
        $params->address = $settings->address;
        $params->eventtype = $bookingsettings->eventtype;
        $params->pollstartdate = $settings->coursestarttime ?
            userdate((int) $settings->coursestarttime, get_string('pollstrftimedate', 'booking')) : '';
        if (empty($settings->pollurl)) {
            $params->pollurl = $bookingsettings->pollurl;
        } else {
            $params->pollurl = $settings->pollurl;
        }
        if (empty($settings->pollurlteachers)) {
            $params->pollurlteachers = $bookingsettings->pollurlteachers;
        } else {
            $params->pollurlteachers = $settings->pollurlteachers;
        }

        // Placeholder for the number of booked users.
        $params->numberparticipants = strval(count($bookingoption->get_all_users_booked()));

        // Placeholder for the number of users on the waiting list.
        $params->numberwaitinglist = strval(count($bookingoption->get_all_users_on_waitinglist()));

        // Add placeholders for additional user fields.
        if (isset($user->username)) {
            $params->username = $user->username;
        }
        if (isset($user->firstname)) {
            $params->firstname = $user->firstname;
        }
        if (isset($user->lastname)) {
            $params->lastname = $user->lastname;
        }
        if (isset($user->department)) {
            $params->department = $user->department;
        }

        // Get bookingoption_description instance for rendering certain data.
        $params->teachers = $settings->render_list_of_teachers();

        // Params for individual teachers.
        $i = 1;
        foreach ($settings->teachers as $teacher) {
            $params->{"teacher" . $i} = $teacher->firstname . ' ' . $teacher->lastname;
            $i++;
        }
        // If there's only one teacher, we can use either {teacher} or {teacher1}.
        if (!empty($params->teacher1)) {
            $params->teacher = $params->teacher1;
        } else {
            $params->teacher = '';
        }

        // Add user profile fields to e-mail params.
        // If user profile fields are missing, we need to load them correctly.
        if (empty($user->profile)) {
            $user->profile = [];
            profile_load_data($user);
            foreach ($user as $userkey => $uservalue) {
                if (substr($userkey, 0, 14) == "profile_field_") {
                    $profilefieldkey = str_replace('profile_field_', '', $userkey);
                    $user->profile[$profilefieldkey] = $uservalue;
                }
            }
        }
        foreach ($user->profile as $profilefieldkey => $profilefieldvalue) {
            // Ignore fields that use a param name that is already in use.
            if (!isset($params->{$profilefieldkey})) {
                // Example: There is a user profile field called "Title".
                // We can now use the placeholder {Title}. (Keep in mind that this is case-sensitive!).
                $params->{$profilefieldkey} = $profilefieldvalue;
            }
        }

        // Add a param to the option's teachers report (training journal).
        $teachersreportlink = new \moodle_url('/mod/booking/optiondates_teachers_report.php', [
            'cmid' => $cmid,
            'optionid' => $optionid,
        ]);
        $params->journal = \html_writer::link($teachersreportlink, $teachersreportlink->out());

        return $params;
    }

    /**
     * Helper function to create a truly unique identifier.
     * @return string $identifier a truly unique identifier
     */
    public static function create_truly_unique_option_identifier() {
        global $DB;
        // First try.
        $temporaryidentifier = substr(str_shuffle(md5(microtime())), 0, 8);
        // Make sure it is really unique!
        while ($DB->get_records('booking_options', ['identifier' => $temporaryidentifier])) {
            $temporaryidentifier = substr(str_shuffle(md5(microtime())), 0, 8);
        }
        return $temporaryidentifier;
    }

    /**
     * A helper class to add data to the json of a booking option.
     *
     * @param stdClass $data reference to a data object containing the json key
     * @param string $key - for example: "disablecancel"
     * @param int|string|stdClass|array|null $value - for example: 1
     */
    public static function add_data_to_json(stdClass &$data, string $key, $value) {
        $jsonobject = new stdClass();
        if (!empty($data->json)) {
            $jsonobject = json_decode($data->json);
        }
        $jsonobject->{$key} = $value;
        $data->json = json_encode($jsonobject);
    }

    /**
     * A helper class to remove a data field from the json of a booking option.
     *
     * @param stdClass $data reference to a data object containing the json key to remove
     * @param string $key - the key to remove, for example: "disablecancel"
     */
    public static function remove_key_from_json(stdClass &$data, string $key) {
        if (!empty($data->json)) {
            $jsonobject = json_decode($data->json);
            if (isset($jsonobject->{$key})) {
                unset($jsonobject->{$key});
                $data->json = json_encode($jsonobject);
            }
        } else {
            $data->json = "{}";
        }
    }

    /**
     * A helper class to get the value of a certain key stored in the json DB field of a booking option.
     *
     * @param int $optionid
     * @param string $key - the key to remove, for example: "disablecancel"
     * @return mixed|null the value found, false if nothing found
     */
    public static function get_value_of_json_by_key(int $optionid, string $key) {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $json = $settings->json;
        if (!empty($json)) {
            $jsonobject = json_decode($json);
            if (isset($jsonobject->{$key})) {
                return $jsonobject->{$key};
            }
        }
        return null;
    }

    /**
     * Helper function to get $cmid for $optionid.
     * @param int $optionid
     * @return int|null $cmid
     */
    public static function get_cmid_from_optionid(int $optionid) {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (!empty($settings->cmid)) {
            return (int) $settings->cmid;
        } else {
            return null;
        }
    }

    /**
     * Returns an array with status and a label to be displayed on the booking button.
     * @param booking_option_settings $settings
     */
    public static function is_blocked_by_campaign(booking_option_settings $settings): array {

        foreach ($settings->campaigns as $campaign) {

            $result = $campaign->is_blocking($settings);
            if ($result['status'] === true) {
                return $result;
            }
        }

        return [
            'status' => false,
            'label' => '',
        ];
    }

    /**
     * Helper function to check if a booking option has a price set or not.
     * @param int $optionid
     * @param int $userid
     * @return bool true if a price is set, else false
     */
    public static function has_price_set(int $optionid, int $userid) {
        $user = singleton_service::get_instance_of_user($userid);
        $optionprice = price::get_price('option', $optionid, $user);
        return !empty($optionprice);
    }

    /**
     * The only way to update a booking option.
     * When calling an update with values from form, csv or webservice...
     * ... we need to make sure to update values correctly.
     * For once, values from form or CSV may not be present.
     * We don't want to accidentally delete values in the process of updating.
     * On the other hand, connected tables and values need to be updated after an optionid is there.
     * This concerns customfields, entities, prices, optiondates etc.
     *
     * @param array|stdClass $data // New transmitted values via form, csv or webservice.
     * @param null|context $context // Context class.
     * @param int $updateparam // The update param allows for fine tuning.
     *
     * @return int
     *
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function update($data, ?context $context = null,
        int $updateparam = MOD_BOOKING_UPDATE_OPTIONS_PARAM_DEFAULT) {

        global $DB, $CFG, $USER;

        // When we come here, we have the following possibilities:
        // A) Normal saving via Form of an existing option.
        // B) Normal saving via Form of a new option.
        // C) CSV update of an existing option
        // D) CSV creation of a new option
        // E) Webservice update of an existing option
        // F) Webservice creation of a new option.

        // While A) & B) will already have called set_data...
        // ... that's not the case for C to F.

        // Get the old option. We need to compare it with the new one to get the changes.
        // If no ID provided we treat record as new and set id to "0".
        $optionid = is_array($data) ? ($data['id'] ?? 0) : ($data->id ?? 0);
        $originaloption = singleton_service::get_instance_of_booking_option_settings($optionid);

        // If $formdata is an array, we need to run set_data.
        if (is_array($data) || isset($data->importing)) {
            $data = (object)$data;

            $data->importing = true;

            // If we encounter an error in set data, we need to exit here.
            $errormessage = fields_info::set_data($data);
            if (!empty($errormessage)) {
                throw new moodle_exception('erroronsetdata', 'mod_booking', '', $data, $errormessage);
            }

            $errors = [];

            // This is a possibility to return validation errors to the importer.
            fields_info::validation((array)$data, [], $errors);
        }

        $newoption = new stdClass();
        $feedbackformchanges = fields_info::prepare_save_fields($data, $newoption, $updateparam);

        if (!empty($newoption->id)) {
            // Save the changes to DB.
            if (!$DB->update_record("booking_options", $newoption)) {
                throw new moodle_exception('updateofoptionwentwrong', 'mod_booking');
            }
        } else {
            // Save the changes to DB.
            if (!$optionid = $DB->insert_record("booking_options", $newoption)) {
                throw new moodle_exception('creationofoptionwentwrong', 'mod_booking');
            }
            // Some legacy weight still left.
            $newoption->id = $optionid;
            $data->id = $optionid;
        }

        $feedbackpostchanges = fields_info::save_fields_post($data, $newoption, $updateparam);

        // We need to keep the previous values (before purging caches).
        $oldsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        // Only now, we can purge.
        self::purge_cache_for_option($newoption->id);

        $option = singleton_service::get_instance_of_booking_option($data->cmid, $optionid);

        // Sync the waiting list and send status change mails.
        if ($oldsettings->maxanswers < $newoption->maxanswers) {
            // We have more places now, so we can sync without danger.
            $option->sync_waiting_list();
        } else if ($oldsettings->maxanswers > $newoption->maxanswers &&
            !get_config('booking', 'keepusersbookedonreducingmaxanswers')) {
            // We have less places now, so we only sync if the setting to keep users booked is turned off.
            $option->sync_waiting_list();
        }
        try {
            // Now check, if there are rules to execute.
            rules_info::execute_rules_for_option($newoption->id);
        } catch (Exception $e) {
            if ($CFG->debug == DEBUG_DEVELOPER) {
                throw $e;
            } else {
                $message = $e->getMessage();
                // Log cancellation of user.
                $event = booking_rulesexecutionfailed::create([
                    'objectid' => $newoption->id,
                    'context' => context_system::instance(),
                    'userid' => $USER->id, // The user triggered the action.
                    'other' => [
                        'error' => $message,
                    ],
                ]);
                $event->trigger(); // This will trigger the observer function.
            }
        }

        // If there have been changes to significant fields, we react on changes.
        // Change notification will be sent (if active).
        // Action logs will be stored ("Shwo recent updates..." link on bottom of option form).
        $bu = new booking_utils();

        // New way of handling changes.
        $feedbackpost = array_filter($feedbackpostchanges, function($value) {
            return !empty($value);
        });
        $changes = array_merge($feedbackpost, $feedbackformchanges);
        $cmid = $originaloption->cmid ?? $data->cmid ?? 0;
        if (!empty($changes)) {

            // If we have no cmid, it's most possibly a template.
            if (!empty($cmid) && $newoption->bookingid != 0) {
                // We only react on changes, if a cmid exists.

                if (empty($context)) {
                    $context = context_module::instance($cmid);
                }
                $bu->react_on_changes($cmid, $context, $newoption->id, $changes);
            }
        }
        // Make sure, users are enroled to booking option when course is added after users already booked.
        $bo = singleton_service::get_instance_of_booking_option($cmid, $newoption->id);
        $ba = singleton_service::get_instance_of_booking_answers($bo->settings);
        foreach ($ba->usersonlist as $bookeduser) {
            $bo->enrol_user_coursestart($bookeduser->id);
        }

        return $newoption->id;
    }

    /**
     * Recreate date series.
     * @param int $semesterid
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function recreate_date_series(int $semesterid) {

        // New code.
        $data = (object)[
            'cmid' => $this->cmid,
            'id' => $this->id, // In the context of option_form class, id always refers to optionid.
            'optionid' => $this->id, // Just kept on for legacy reasons.
            'bookingid' => $this->bookingid,
            'copyoptionid' => 0,
            'returnurl' => '',
        ];

        fields_info::set_data($data);

        $data->addoptiondateseries = "Create date series";
        $data->semesterid = $semesterid;
        $data->datesmarker = 1;

        fields_info::set_data($data);

        $context = context_module::instance($data->cmid);

        $this->update($data, $context);

    }

    /**
     * Helper function to render a list of attachments for a booking option.
     * @param int $optionid
     * @param string $classes optional classes for the enclosing div
     * @return string the rendered attachments as links (with paperclip icons)
     */
    public static function render_attachments(int $optionid, string $classes = ''): string {
        $ret = '';
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $attachedfiles = $settings->attachedfiles;
        if (!empty($attachedfiles)) {
            $ret .= html_writer::start_div($classes);
            foreach ($attachedfiles as $attachedfile) {
                $content = html_writer::tag('span', '<i class="fa fa-fw fa-sm fa-paperclip" aria-hidden="true"></i> ',
                    ['class' => 'bold text-gray']) .
                    html_writer::tag('span', $attachedfile, ['class' => 'pt-0 pb-0']);
                $ret .= html_writer::div($content, '');
            }
            $ret .= html_writer::end_div();
        }
        return $ret;
    }
}
