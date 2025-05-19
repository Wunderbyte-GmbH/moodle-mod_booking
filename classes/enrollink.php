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

use context_course;
use context_module;
use html_writer;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\event\enrollink_triggered;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/calendar/lib.php');

/**
 * Deal with elective
 * @package mod_booking
 * @copyright 2024 Magdalena Holczik <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrollink {

    /** @var static $instances  */
    private static $instances = [];

    /** @var object $bundle  */
    public $bundle = [];

    /** @var array itemsconsumed  */
    public $itemsconsumed = [];

    /** @var int freeseats  */
    public $freeseats = 0;

    /** @var string erlid  */
    public $erlid = 0;

    /** @var int errorinfo  */
    public $errorinfo = 0;


    /**
     * Get the singleton instance.
     *
     * @param string $erlid
     * @return enrollink
     */
    public static function get_instance(string $erlid): ?self {
        if (!isset(self::$instances[$erlid])) {
            self::$instances[$erlid] = new self($erlid);
        }
        return self::$instances[$erlid];
    }

    /**
     * Private constructor to prevent direct instantiation.
     *
     * @param string $erlid
     */
    private function __construct(string $erlid) {
        $this->set_values($erlid);
    }

    /**
     * Destroys the singleton entirely.
     *
     * @return bool
     */
    public static function destroy_instances() {
        self::$instances = [];
        return true;
    }

    /**
     * Set values.
     *
     * @param string $erlid
     * @return void
     */
    private function set_values(string $erlid): void {
        global $DB;

        $this->erlid = $erlid;
        try {
            $this->bundle = $DB->get_record('booking_enrollink_bundles', ['erlid' => $erlid], '*', MUST_EXIST);
            $this->itemsconsumed = $DB->get_records('booking_enrollink_items', ['erlid' => $erlid, 'consumed' => 1]);
            $this->freeseats = $this->bundle->places - count($this->itemsconsumed);
        } catch (\Exception $e) {
            $this->erlid = false;
            $this->errorinfo = MOD_BOOKING_AUTOENROL_STATUS_LINK_NOT_VALID;
        }
    }

    /**
     * Number of free places.
     *
     * @return int
     *
     */
    public function free_places_left(): int {
        if ($this->freeseats > 0) {
            return $this->freeseats;
        } else {
            return 0;
        }
    }

    /**
     * Context of booking instance.
     *
     * @return int
     *
     */
    public function get_bo_contextid(): int {
        $optionid = $this->bundle->optionid;
        $bosettings = singleton_service::get_instance_of_booking_by_optionid($optionid);
        return $bosettings->cmid ?? 0;
    }

    /**
     * Enrol the user to the given courseid.
     *
     * @param int $userid
     *
     * @return int
     *
     */
    public function enrol_user(int $userid): int {

        if (!empty($block = $this->enrolment_blocking())) {
            return $block;
        }

        if (isguestuser()) {
            return MOD_BOOKING_AUTOENROL_STATUS_LOGGED_IN_AS_GUEST;
        }

        $cmid = $this->get_bo_contextid();
        $bo = singleton_service::get_instance_of_booking_option($cmid, $this->bundle->optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($bo->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        foreach ($ba->users as $bauserid => $userdata) {
            if ($userid == $bauserid) {
                return MOD_BOOKING_AUTOENROL_STATUS_ALREADY_ENROLLED;
            }
        }

        try {
            $user = singleton_service::get_instance_of_user($userid);
            $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
            // Enrol to bookingoption and reduce places in bookinganswer.
            $bo->user_submit_response(
                $user,
                $booking->id,
                1,
                MOD_BOOKING_BO_SUBMIT_STATUS_AUTOENROL,
                MOD_BOOKING_VERIFIED,
                $this->erlid
            );
            // Change answer if user was enrolled only to waitinglist.
            if ($this->enrolmentstatus_waitinglist($bo->settings)) {
                $courseenrolmentstatus = MOD_BOOKING_AUTOENROL_STATUS_WAITINGLIST;
            } else {
                $courseenrolmentstatus = MOD_BOOKING_AUTOENROL_STATUS_SUCCESS;
            }
        } catch (\Exception $e) {
            $courseenrolmentstatus = MOD_BOOKING_AUTOENROL_STATUS_EXCEPTION;
        }
        return $courseenrolmentstatus;
    }

    /**
     * Add consumed item to enrollink table and update bookinganswer.
     * If the user is the initial user who bought the bundle, the consumed item should not be deduced from bookinganswer places.
     *
     * @param int $userid
     * @param bool $initialuser
     *
     * @return bool
     *
     */
    public function add_consumed_item(int $userid, bool $initialuser = false): bool {
        global $DB;
        if (
            empty($this->free_places_left())
        ) {
            return false;
        }

        foreach ($this->itemsconsumed as $key => $item) {
            if ($item->userid === $userid && $item->consumed === 1) {
                return false;
            } else if ($item->userid === $userid && $item->consumed === 0) {
                $DB->update_record('booking_enrollink_items', $item);
            }
        }

        $data = (object) [
            'erlid' => $this->erlid,
            'userid' => $userid,
            'consumed' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        // Update records.
        $id = $DB->insert_record('booking_enrollink_items', $data);
        // Update data.
        $data->id = $id;
        $this->itemsconsumed[$id] = $data;
        $this->freeseats--;

        if (!$initialuser) {
            $this->update_bookinganswer($this->erlid);
        }
        return true;
    }

    /**
     * Update bookingsanswer to make sure the place reserved from bundle is deduced...
     * From the bookinganswer that is reserving the bundle.
     *
     * @param string $erlid
     *
     * @return bool
     *
     */
    private function update_bookinganswer(string $erlid): bool {
        global $DB;

        $sql = "
            SELECT ba.*
            FROM {booking_answers} ba
            JOIN {booking_enrollink_bundles} beb ON beb.baid = ba.id
            WHERE beb.erlid = :erlid
        ";
        $params = ['erlid' => $erlid];
        $record = $DB->get_record_sql($sql, $params);

        if (
            $record
            && $record->places > 0
        ) {
            $record->places -= 1;
            $DB->update_record('booking_answers', $record);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get the enrollink url.
     *
     * @return string
     *
     */
    public function get_enrollink_url(): string {
        $url = new moodle_url('/mod/booking/enrollink.php', ['erlid' => $this->bundle->erlid]);
        return $url->out(false);
    }

    /**
     * Get the courselink url.
     *
     * @return string
     *
     */
    public function get_courselink_url(): string {
        if (empty($this->bundle->courseid)) {
            return "";
        }
        $url = new moodle_url('/course/view.php', ['id' => $this->bundle->courseid]);
        return $url->out(false);
    }

    /**
     * Get the booking details url.
     *
     * @return string
     *
     */
    public function get_bookingdetailslink_url(): string {

        $optionid = $this->bundle->optionid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $url = new moodle_url(
            '/mod/booking/optionview.php',
            [
                'cmid' => $settings->cmid,
                'optionid' => $settings->id,
            ]
        );
        return $url->out(false);
    }

    /**
     * Get the bookingoption title.
     *
     * @return string
     *
     */
    public function get_bookingoptiontitle(): string {

        $optionid = $this->bundle->optionid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        return $settings->get_title_with_prefix();
    }

    /**
     * Check if enrolment is blocked.
     *
     *
     * @return int
     *
     */
    public function enrolment_blocking(): int {

        if (!empty($this->errorinfo)) {
            return $this->errorinfo;
        }

        if (!$this->erlid) {
            return MOD_BOOKING_AUTOENROL_STATUS_LINK_NOT_VALID;
        }

        if (empty($this->free_places_left())) {
            return MOD_BOOKING_AUTOENROL_STATUS_NO_MORE_SEATS;
        }
        return 0;
    }


    /**
     * Return localized string.
     *
     * @param mixed $info
     *
     * @return string
     *
     */
    public function get_readable_info($info): string {
        $string = get_string('enrollink:'. $info, 'mod_booking');
        return $string;
    }

    /**
     * Id of course to be enrolled into.
     *
     * @return int
     *
     */
    public function get_courseid(): int {
        return $this->bundle->courseid ?? 0;
    }


    /**
     * Create and return the enrollink.
     *
     * @param mixed $erlid
     *
     * @return string
     *
     */
    public static function create_enrollink($erlid): string {
        $enrollink = new moodle_url('/mod/booking/enrollink.php', ['erlid' => $erlid]);
        return html_writer::link($enrollink, $enrollink->out());
    }

    /**
     * If data from customform enrolusersaction is given, trigger the corresponding event.
     *
     * @param int $optionid
     * @param int $userid
     * @param object $settings
     * @param object $bookinganswer
     * @param int $baid
     *
     * @return bool
     *
     */
    public static function trigger_enrolbot_actions(
        int $optionid,
        int $userid,
        object $settings,
        object $bookinganswer,
        int $baid
    ): bool {
        global $USER, $DB;

        if (!isset($bookinganswer->answers[$baid])) {
            return false;
        }

        $answer = $bookinganswer->answers[$baid];
        $key = self::enrolusersaction_applies($answer);

        if (empty($key)) {
            return false;
        };
        $freeplaces = false;
        $places = $answer->$key;
        if ($places > 0) {
            $freeplaces = true;
        }

        // Update table.
        $data = new stdClass();
        $data->courseid = $settings->courseid;
        $data->userid = $USER->id;
        $data->usermodified = $USER->id;
        $data->timecreated = time();
        $data->timemodified = $data->timecreated;
        $data->places = (string) $places;
        $data->erlid = md5(random_string());
        $data->baid = $baid;
        $data->optionid = $optionid;
        $id = $DB->insert_record('booking_enrollink_bundles', $data);

        // Check if user who bought was enrolled fo the course. If so, add item to db.
        if (
            isset($bookinganswer->answers[$baid])
            && self::enroluseraction_allows_enrolment($bookinganswer, $baid)
        ) {
            $el = self::get_instance($data->erlid);
            $el->add_consumed_item($userid, true);
            if ($places == 1) {
                $freeplaces = false;
            }
        }

        if ($freeplaces) {
            $bas = singleton_service::get_instance_of_booking_answers($settings);
            $barecord = $bas->answers[$baid];

            // Trigger event.
            $event = enrollink_triggered::create([
                'objectid' => $optionid, // Always needs to be the optionid, to make sure rules are applied correctly.
                'context' => \context_module::instance($settings->cmid),
                'userid' => $USER->id, // The user who triggered the event.
                'relateduserid' => $userid, // Affected user.
                'other' => [
                    'places' => $places,
                    'optionid' => $optionid,
                    'courseid' => $settings->courseid,
                    'erlid' => $data->erlid, // The hash of this enrollink bundle.
                    'bundleid' => $id, // The hash of this enrollink bundle.
                    'json' => $barecord->json,
                ],
            ]);
            $event->trigger();
        }
        return true;
    }

    /**
     * Check if enrolusersaction from customform applies.
     * If so, return key of string in bookinganswer. Otherwise return empty string.
     *
     * @param object $answer
     *
     * @return string
     *
     */
    public static function enrolusersaction_applies(object $answer): string {
        foreach ($answer as $key => $value) {
            if (
                strpos($key, 'customform_enrolusersaction_') === 0 &&
                !empty($value)
            ) {
                return $key;
            }
        }
        return "";
    }

    /**
     * Check if this is a enrolbotaction and if so, check if user actually should be enrolled.
     *
     * @param object $bookinganswer
     * @param int $baid
     *
     * @return bool
     *
     */
    public static function enroluseraction_allows_enrolment(
        object $bookinganswer,
        int $baid
    ): bool {

        $answer = $bookinganswer->answers[$baid];
        if (!$answer->json) {
            return true;
        }
        $data = json_decode($answer->json);
        if (!isset($data->condition_customform)) {
            return true;
        }
        foreach ($data->condition_customform as $key => $value) {
            if (
                strpos($key, 'customform_enroluserwhobookedcheckbox_enrolusersaction') === 0 &&
                empty($value)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if users should be enroled to waitinglist or booked directly.
     *
     * @param booking_option_settings $settings
     *
     * @return bool
     *
     */
    public static function enrolmentstatus_waitinglist(booking_option_settings $settings): bool {

        $formsarray = customform::return_formelements($settings);

        foreach ($formsarray as $forms) {
            if (
                $forms->formtype == 'enrolusersaction'
                && !empty($forms->enroluserstowaitinglist)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if it's the initial answer to enrol users.
     *
     * @param object $answer
     *
     * @return bool
     *
     */
    public static function is_initial_answer(
        object $answer
    ): bool {

        if (!$answer->json) {
            return false;
        }
        $data = json_decode($answer->json);
        foreach ($data->condition_customform as $key => $value) {
            if (
                strpos($key, 'customform_enroluserwhobookedcheckbox_enrolusersaction') === 0
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the id of enrollink from booking answer id.
     *
     * @param int $baid
     *
     * @return string
     *
     */
    public static function get_erlid_from_baid(int $baid): string {
        global $DB;

        return $DB->get_field(
            'booking_enrollink_bundles',
            'erlid',
            ['baid' => $baid]
        );
    }
}
