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

    /** @var object $bundle  */
    public $bundle = [];

    /** @var array itemsconsumed  */
    public $itemsconsumed = [];

    /** @var int freeseats  */
    public $freeseats = 0;

    /** @var string erlid  */
    public $erlid = 0;

    /** @var string errorinfo  */
    public $errorinfo = "";


    /**
     * Construct and set values.
     *
     * @param string $erlid
     *
     */
    public function __construct(string $erlid) {
        $this->set_values($erlid);
    }

    /**
     * Set values.
     *
     * @param string $erlid
     *
     * @return [type]
     *
     */
    public function set_values(string $erlid) {
        global $DB;

        $this->erlid = $erlid;
        try {
            $this->bundle = $DB->get_record('booking_enrollink_bundles', ['erlid' => $erlid], '*', MUST_EXIST);
            $this->itemsconsumed = $DB->get_records('booking_enrollink_items', ['erlid' => $erlid, 'consumed' => 1]);
            $this->freeseats = $this->bundle->places - count($this->itemsconsumed);
        } catch (\Exception $e) {
            $this->erlid = false;
            $this->errorinfo = 'invalidenrollink';
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
     * @return string
     *
     */
    public function enrol_user(int $userid): string {

        $context = context_course::instance($this->bundle->courseid);
        // Make sure, the user isn't booked yet.
        if (
            is_enrolled($context, $userid)
        ) {
            return "alreadyenrolled";
        }

        $cmid = $this->get_bo_contextid();
        $bo = new booking_option($cmid, $this->bundle->optionid);

        try {
            $bo->enrol_user(
                $userid,
                false,
                0,
                false,
                $this->bundle->courseid,
                true
            );
            $this->add_consumed_item($userid);
            return "enrolled";
        } catch (\Exception $e) {
            return "enrolmentexception";
        }
    }

    /**
     * [Description for update_consumed_items]
     *
     * @param int $userid
     *
     * @return bool
     *
     */
    private function add_consumed_item(int $userid): bool {
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

        return true;
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
     * Check if enrolment is blocked.
     *
     *
     * @return string
     *
     */
    public function enrolment_blocking(): string {

        if (!empty($this->errorinfo)) {
            return $this->errorinfo;
        }

        if (!$this->erlid) {
            return 'invalidenrollink';
        }

        if (empty($this->free_places_left())) {
            return "nomoreseats";
        }
        return "";
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

        $places = $answer->$key;
        // Update table.
        $data = new stdClass();
        $data->courseid = $settings->courseid;
        $data->userid = $USER->id;
        $data->usermodified = $USER->id;
        $data->timecreated = time();
        $data->timemodified = $data->timecreated;
        $data->places = $places;
        $data->erlid = md5(random_string());
        $data->baid = $baid;
        $data->optionid = $optionid;
        $id = $DB->insert_record('booking_enrollink_bundles', $data);

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

        return true;
    }

    /**
     * Check if enrolusersaction from customform applies.
     * If so, return key of string in bookinganswer. Otherwise return empty stirng.
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


}
