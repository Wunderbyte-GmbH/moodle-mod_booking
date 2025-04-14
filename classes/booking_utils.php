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
 * Booking utils.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič, 2021 onwards - Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

use cache_helper;
use html_writer;
use mod_booking\event\bookingoption_updated;
use moodle_url;
use stdClass;

require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Class for booking utils.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič, 2021 onwards - Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_utils {
    /**
     * @var stdClass
     */
    public $booking = null;

    /**
     * @var stdClass
     */
    public $bookingoption = null;

    /**
     * [Description for Constructor
     *
     * @param ?object $booking
     * @param ?object $bookingoption
     *
     */
    public function __construct(?object $booking = null, ?object $bookingoption = null) {

        if ($booking) {
            $this->booking = $booking;
        }
        if ($bookingoption) {
            $this->bookingoption = $bookingoption;
        }
    }

    /**
     * [Description for Get pretty duration
     *
     * @param mixed $seconds
     *
     * @return string
     *
     */
    public function get_pretty_duration($seconds) {
        return $this->pretty_duration($seconds);
    }

    /**
     * [Description for Pretty duration
     *
     * @param mixed $seconds
     *
     * @return string
     *
     */
    private function pretty_duration($seconds) {
        $measures = ['days' => 24 * 60 * 60, 'hours' => 60 * 60, 'minutes' => 60];
        $durationparts = [];
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
     * @param stdClass $settings
     * @param ?stdClass $option
     * @return stdClass data to be sent via mail
     */
    public function generate_params(stdClass $settings, ?stdClass $option = null): stdClass {
        global $DB, $CFG;

        $params = new stdClass();

        $params->duration = $settings->duration;
        $params->eventtype = $settings->eventtype;

        if (!is_null($option)) {
            $teacher = $DB->get_records('booking_teachers', ['optionid' => $option->id]);

            $i = 1;

            foreach ($teacher as $value) {
                if (
                    $user = $DB->get_record(
                        'user',
                        ['id' => $value->userid],
                        'firstname, lastname',
                        IGNORE_MULTIPLE
                    )
                ) {
                         // The user might not actually exist.
                        // This can be the case when das was backup restored or the user was deleted.
                        $params->{"teacher" . $i} = $user->firstname . ' ' . $user->lastname;
                        $i++;
                }
            }

            if (isset($params->teacher1)) {
                $params->teacher = $params->teacher1;
            } else {
                $params->teacher = '';
            }

            $timeformat = get_string('strftimetime', 'langconfig');
            $dateformat = get_string('strftimedate', 'langconfig');

            $duration = '';
            if ($option->coursestarttime && $option->courseendtime) {
                $seconds = $option->courseendtime - $option->coursestarttime;
                $duration = $this->pretty_duration($seconds);
            }
            $courselink = '';
            if ($option->courseid) {
                $baseurl = $CFG->wwwroot;
                $courselink = new moodle_url($baseurl . '/course/view.php', ['id' => $option->courseid]);
                $courselink = html_writer::link($courselink, $courselink->out());
            }

            $params->title = s($option->text);
            $params->starttime = $option->coursestarttime ? userdate(
                $option->coursestarttime,
                $timeformat
            ) : '';
            $params->endtime = $option->courseendtime ? userdate(
                $option->courseendtime,
                $timeformat
            ) : '';
            $params->startdate = $option->coursestarttime ? userdate(
                $option->coursestarttime,
                $dateformat
            ) : '';
            $params->enddate = $option->courseendtime ? userdate(
                $option->courseendtime,
                $dateformat
            ) : '';
            $params->courselink = $courselink;
            $params->location = $option->location;
            $params->institution = $option->institution;
            $params->address = $option->address;
            $params->pollstartdate = $option->coursestarttime ? userdate(
                (int) $option->coursestarttime,
                get_string('pollstrftimedate', 'booking'),
                '',
                false
            ) : '';
            if (!empty($option->pollurl)) {
                $params->pollurl = $option->pollurl;
            } else {
                $params->pollurl = $settings->pollurl;
            }
            if (!empty($option->pollurlteachers)) {
                $params->pollurlteachers = $option->pollurlteachers;
            } else {
                $params->pollurlteachers = $settings->pollurlteachers;
            }

            $val = '';
            if (!empty($option->optiontimes)) {
                $additionaltimes = explode(',', $option->optiontimes);
                if (!empty($additionaltimes)) {
                    foreach ($additionaltimes as $t) {
                        $slot = explode('-', $t);
                        $tmpdate = new stdClass();
                        $tmpdate->leftdate = userdate(
                            $slot[0],
                            get_string('strftimedatetime', 'langconfig')
                        );
                        $tmpdate->righttdate = userdate(
                            $slot[1],
                            get_string('strftimetime', 'langconfig')
                        );
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
     * @param bool $urlencode
     *
     * @return string
     */
    public function get_body($booking, $fieldname, $params, $urlencode = false) {
        $text = $booking->$fieldname;
        foreach ($params as $name => $value) {
            if (!empty($value) && !empty($text)) {
                if ($urlencode) {
                    $text = str_replace('{' . $name . '}', urlencode($value), $text);
                } else {
                    $text = str_replace('{' . $name . '}', $value, $text);
                }
            }
        }
        return $text;
    }

    /**
     * Function to define reaction on changes of booking options and its sessions.
     *
     * @param int $cmid
     * @param stdClass $context
     * @param int $optionid
     * @param mixed $changes
     *
     * @return void
     *
     * @throws \coding_exception
     */
    public function react_on_changes($cmid, $context, $optionid, $changes) {
        global $DB, $USER;

        // If we have no $cmid, we don't react on changes because it's most likely a template.
        if (empty($cmid)) {
            return;
        }

        $bo = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $sendmail = $bookingsettings->sendmail ?? false;

        // If we still have changes, we can send the confirmation mail.
        if (count($changes) > 0 && $sendmail) {
            $bookinganswers = $bo->get_all_users_booked();
            if (!empty($bookinganswers)) {
                foreach ($bookinganswers as $bookinganswer) {
                    $bookeduser = $DB->get_record('user', ['id' => $bookinganswer->userid]);
                    $bo->send_confirm_message($bookeduser, true, $changes);
                }
            }
        }

        $changes = $this->prepare_changes_array($changes);

        // We trigger the event only if we have changes.
        if (count($changes) > 0) {
            // Also, we need to trigger the bookingoption_updated event, in order to update calendar entries.
            $event = bookingoption_updated::create(
                [
                        'context' => $context,
                        'objectid' => $optionid,
                        'userid' => $USER->id,
                        'relateduserid' => $USER->id,
                        'other' => [
                            'changes' => $changes ?? '',
                        ],
                ]
            );
            $event->trigger();

            cache_helper::purge_by_event('setbackeventlogtable');
        }
    }

    /**
     * Convert the array to the expected format.
     *
     * @param array $changes
     *
     * @return array
     *
     */
    private function prepare_changes_array(array $changes): array {

        $newchanges = [];
        foreach ($changes as $class => $change) {
            if (empty($change)) {
                continue;
            }
            if (isset($change['changes'])) {
                foreach ($change['changes'] as $field => $subchange) {
                    // For classes that return values of multiple fields.
                    if (isset($subchange['changes'])) {
                        // Is there something to check for?
                        $newchanges[] = $subchange['changes'];
                    } else {
                        $newchanges[] = $change['changes'];
                        break;
                    }
                }
            }
        }
        return $newchanges;
    }

    /**
     * Helper function to check if a booking option has associated sessions (optiondates).
     *
     * @param int $optionid int The id of a booking option.
     *
     * @return bool
     *
     * @throws \dml_exception
     */
    public static function booking_option_has_optiondates(int $optionid) {
        global $DB;
        $sql = "SELECT * FROM {booking_optiondates} WHERE optionid = :optionid";
        $sessions = $DB->get_records_sql($sql, ['optionid' => $optionid]);

        if (empty($sessions)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Helper function to hide all option user events.
     *
     * We need this if we switch from option to multisession.
     *
     * @param int $optionid
     *
     * @return bool
     */
    public function booking_hide_option_userevents($optionid) {
        global $DB;
        $userevents = $DB->get_records('booking_userevents', ['optionid' => $optionid, 'optiondateid' => null]);

        foreach ($userevents as $userevent) {
            if ($event = $DB->get_record('event', ['id' => $userevent->eventid])) {
                $event->visible = 0;
                $DB->update_record('event', $event);
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Helper function to show all option user events.
     * We need this if we switch from multisession to option.
     *
     * @param int $optionid
     *
     * @return bool
     */
    public function booking_show_option_userevents($optionid) {
        global $DB;
        $userevents = $DB->get_records('booking_userevents', ['optionid' => $optionid, 'optiondateid' => null]);

        foreach ($userevents as $userevent) {
            if ($event = $DB->get_record('event', ['id' => $userevent->eventid])) {
                $event->visible = 1;
                $DB->update_record('event', $event);
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Helper function to generate a subscription link to the Moodle calendar.
     *
     * The calendar export time range can be set in Site_admin > Appearance > Calendar.
     * Use $eventparam to specify the event type to be exported (user events are the default).
     *
     * @param stdClass $user the user the calendar link is for
     * @param string $eventparam ('all' | 'categories' | 'courses' | 'groups' | 'user')
     *
     * @return string the subscription link
     */
    public function booking_generate_calendar_subscription_link($user, $eventparam = 'user') {

        if (!$user) {
            return '';
        }

        $authtoken = $this->calendar_get_export_token($user);

        $linkparams = [
            'userid' => $user->id,
            'authtoken' => $authtoken,
            'preset_what' => $eventparam,
            'preset_time' => 'custom',
        ];
        $subscriptionlink = new moodle_url('/calendar/export_execute.php', $linkparams);

        return $subscriptionlink->__toString();
    }

    /**
     * Function to book cohort or group members(users).
     * Result as an object containing the following numbers:
     * - sumcohortmembers All cohort members that have been tried to subscribe.
     * - sumgroupmembers All group members that have been tried to subscribe.
     * - subscribedusers Users that were subscribed successfully.
     * - sumgroupmembers All group members that have been tried to subscribe.
     * - notenrolledusers Users that could not be subscribed because of missing course enrolment.
     * - notsubscribedusers Users that could not be subscribed for all reasons else.
     *
     * @param stdClass $fromform
     * @param booking_option $bookingoption
     * @param mixed $context
     *
     * @return stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function book_cohort_or_group_members(
        stdClass $fromform,
        booking_option $bookingoption,
        $context
    ): stdClass {

        global $DB;

        // Create the return object.
        $result = new stdClass();

        $cohortmembersarray = [];
        $groupmembersarray = [];
        $notenrolledusersarray = [];
        $notsubscribedusersarray = [];
        $subscribedusersarray = [];

        $result->sumcohortmembers = 0;
        $result->sumgroupmembers = 0;
        $result->notenrolledusers = 0;
        $result->notsubscribedusers = 0;
        $result->subscribedusers = 0;

        // Part 1: Book cohort members.
        foreach ($fromform->cohortids as $cohortid) {
            // Retrieve all users of this cohort.
            $sql = "SELECT u.*
                    FROM {user} u
                    JOIN {cohort_members} cm
                    ON u.id = cm.userid
                    WHERE cm.cohortid = :cohortid";
            $cohortmembers = $DB->get_records_sql($sql, ['cohortid' => $cohortid]);
            $cohortmembersarray = array_merge($cohortmembersarray, $cohortmembers);

            // Verify if the editing user can see the cohorts.
            if (!cohort_get_cohort($cohortid, $context)) {
                // Members of cohorts with no permission.
                $notsubscribedusersarray = array_merge($notsubscribedusersarray, $cohortmembers);
                continue;
            }

            if (
                has_capability('mod/booking:subscribeusers', $context) ||
                (booking_check_if_teacher($bookingoption->option))
            ) {
                foreach ($cohortmembers as $user) {
                    // First, we only book users which are already subscribed to this course.
                    if (!is_enrolled($context, $user, null, true)) {
                        // Track users who were not subscribed because they were not enrolled in the course.
                        $notenrolledusersarray[] = $user;
                        continue;
                    }
                    if (!$bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED)) {
                        // Track users where subscription failed because of different reasons.
                        $notsubscribedusersarray[] = $user;
                    } else {
                        // Track users with successful subscription.
                        $subscribedusersarray[] = $user;
                    }
                }
            }
        }

        // Part 2: Book group members.
        foreach ($fromform->groupids as $groupid) {
            // Retrieve all users of this group.
            $sql = "SELECT u.*
                    FROM {user} u
                    JOIN {groups_members} gm
                    ON u.id = gm.userid
                    WHERE gm.groupid = :groupid";
            $groupmembers = $DB->get_records_sql($sql, ['groupid' => $groupid]);
            $groupmembersarray = array_merge($groupmembersarray, $groupmembers);

            if (
                has_capability('mod/booking:subscribeusers', $context) ||
                (booking_check_if_teacher($bookingoption->option))
            ) {
                foreach ($groupmembers as $user) {
                    // First, we only book users which are already subscribed to this course.
                    if (!is_enrolled($context, $user, null, true)) {
                        // Track users who were not subscribed because they were not enrolled in the course.
                        $notenrolledusersarray[] = $user;
                        continue;
                    }
                    if (!$bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED)) {
                        // Track users where subscription failed because of different reasons.
                        $notsubscribedusersarray[] = $user;
                    } else {
                        // Track users with successful subscription.
                        $subscribedusersarray[] = $user;
                    }
                }
            }
        }

        $result->sumcohortmembers = count(array_unique($cohortmembersarray, SORT_REGULAR));
        $result->sumgroupmembers = count(array_unique($groupmembersarray, SORT_REGULAR));
        $result->notenrolledusers = count(array_unique($notenrolledusersarray, SORT_REGULAR));
        $result->notsubscribedusers = count(array_unique($notsubscribedusersarray, SORT_REGULAR));
        $result->subscribedusers = count(array_unique($subscribedusersarray, SORT_REGULAR));

        return $result;
    }

    /**
     * Copied from core_calendar > lib.php.
     *
     * Get the auth token for exporting the given user calendar.
     *
     * @param stdClass $user The user to export the calendar for
     *
     * @return string The export token.
     */
    private function calendar_get_export_token(stdClass $user): string {
        global $CFG, $DB;
        return sha1($user->id . $DB->get_field('user', 'password', ['id' => $user->id]) . $CFG->calendar_exportsalt);
    }

    /**
     * Prepare an associative array of optionids, each with an according array of teacher names.
     * @param array $objectswithoptionids an array containing objects with optionids
     * @return array
     */
    public static function prepare_teachernames_arrays_for_optionids(array $objectswithoptionids) {

        global $DB;

        // Prepare arrays of teacher names of every option to reduce DB-queries.
        $list = [];
        $teachers = [];
        foreach ($objectswithoptionids as $objectentry) {
            $list[] = $objectentry->optionid;
            $teachers[$objectentry->optionid] = [];
        }

        if (!empty($list)) {
            [$insql, $inparams] = $DB->get_in_or_equal($list, SQL_PARAMS_NAMED, 'optionid_');

            $sql = "SELECT DISTINCT bt.id, bt.userid, u.firstname, u.lastname, u.username, bt.optionid
                    FROM {booking_teachers} bt
                    JOIN {user} u
                    ON bt.userid = u.id
                    WHERE bt.optionid $insql";

            if ($records = $DB->get_records_sql($sql, $inparams)) {
                foreach ($records as $record) {
                    $teachers[$record->optionid][] = $record->firstname . ' ' . $record->lastname;
                }
            }
        }

        return $teachers;
    }
}
