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
 * Automatically create a booking option for every new course in a linked course category.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use cache;
use context_module;
use mod_booking\booking_option;
use mod_booking\local\sync\enrolmentsync;
use mod_booking\option\fields_info;
use mod_booking\singleton_service;
use mod_booking\task\create_option_for_new_course;
use mod_booking\utils\wb_payment;
use moodle_exception;
use stdClass;

/**
 * Links a course category to a booking instance: every course created in that category gets a booking option.
 *
 * The settings live in the JSON column of the booking instance. The observers for the core course events run
 * synchronously inside every course creation, so they only consult a cached map (category id => booking ids) and
 * queue an adhoc task. All real work happens in the task.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class autocreateoption {
    /** @var string JSON key (booking.json): id of the linked course category, 0 or absent = feature off. */
    const JSONKEY_CATEGORY = 'autocreateoptioncategoryid';

    /** @var string JSON key (booking.json): id of the option template to create the option from, 0 = none. */
    const JSONKEY_TEMPLATE = 'autocreateoptiontemplateid';

    /** @var string JSON key (booking.json): 1 = connect the created option to the new course, 0 = do not. */
    const JSONKEY_LINKCOURSE = 'autocreateoptionlinkcourse';

    /** @var string JSON key (booking.json): 1 = book the users enrolled in the course into the created option. */
    const JSONKEY_SYNCENROLMENTS = 'autocreateoptionsyncenrolments';

    /** @var string JSON key (booking_options.json): the id of the course the option was created for. */
    const OPTIONJSONKEY_SOURCECOURSE = 'autocreatedfromcourseid';

    /** @var string The single key used in the map cache. */
    const CACHEKEY = 'map';

    /**
     * Returns the map of linked course categories to booking instance ids.
     *
     * The map is cached in a MUC application cache and rebuilt lazily with a single query over the (small)
     * booking table. An empty array is stored explicitly, so sites without a linked category never hit the DB.
     *
     * @return array categoryid => int[] booking ids
     */
    public static function get_category_map(): array {
        global $DB;

        $cache = cache::make('mod_booking', 'cachedautocreatecategorymap');
        $map = $cache->get(self::CACHEKEY);
        if (is_array($map)) {
            return $map;
        }

        $map = [];
        $sql = "SELECT id, json
                  FROM {booking}
                 WHERE " . $DB->sql_isnotempty('booking', 'json', true, true);
        $records = $DB->get_records_sql($sql);
        foreach ($records as $record) {
            $json = json_decode($record->json);
            $categoryid = (int)($json->{self::JSONKEY_CATEGORY} ?? 0);
            if ($categoryid > 0) {
                $map[$categoryid][] = (int)$record->id;
            }
        }

        $cache->set(self::CACHEKEY, $map);
        return $map;
    }

    /**
     * Drops the cached map. Called whenever a booking instance is added, updated or deleted.
     *
     * @return void
     */
    public static function purge_map(): void {
        cache::make('mod_booking', 'cachedautocreatecategorymap')->delete(self::CACHEKEY);
    }

    /**
     * Called by the course_created / course_restored observers for a new course.
     *
     * Cheap by design: returns without touching the DB when no instance links a category, and otherwise costs one
     * indexed read of the course's category plus one adhoc task insert per linked instance.
     *
     * @param int $courseid the id of the new course
     * @param int $originalcourseid the id of the source course when the course is a copy, 0 otherwise
     * @param bool $iscopy true when the course was created by an asynchronous course copy
     * @return int[] the ids of the booking instances a task was queued for
     */
    public static function handle_new_course(int $courseid, int $originalcourseid = 0, bool $iscopy = false): array {
        global $DB;

        if ($courseid <= 0 || $courseid == SITEID) {
            return [];
        }

        $map = self::get_category_map();
        if (empty($map)) {
            return [];
        }

        if (!wb_payment::pro_version_is_activated()) {
            return [];
        }

        $categoryid = (int)$DB->get_field('course', 'category', ['id' => $courseid]);
        if ($categoryid <= 0 || empty($map[$categoryid])) {
            return [];
        }

        $queued = [];
        foreach ($map[$categoryid] as $bookingid) {
            $bookingid = (int)$bookingid;

            // A course copy that booking itself started for one of its options (connected course duplication) is
            // queued when the option form loads, before the option is saved. So when the copy finishes, no option
            // references the new course yet - but one references the course that was copied.
            if (
                $iscopy
                && $originalcourseid > 0
                && $DB->record_exists('booking_options', ['bookingid' => $bookingid, 'courseid' => $originalcourseid])
            ) {
                continue;
            }

            $task = new create_option_for_new_course();
            // Keep the key order stable: the dedup check compares the serialized custom data.
            $task->set_custom_data(['courseid' => $courseid, 'bookingid' => $bookingid]);
            // No user is set on purpose: cron then runs the task as admin, who always holds the expert option
            // form capability, so the full set of option fields is available when the option is created.
            \core\task\manager::queue_adhoc_task($task, true);
            $queued[] = $bookingid;
        }

        return $queued;
    }

    /**
     * Creates the booking option for a new course in a booking instance. Called from the adhoc task.
     *
     * Re-validates everything at execution time, so settings changed between queueing and execution are honoured,
     * and never creates a second option for the same course in the same instance.
     *
     * @param int $bookingid
     * @param int $courseid
     * @return int the id of the created option, 0 if nothing was created
     * @throws moodle_exception when the option data could not be prepared
     */
    public static function create_option_for_course(int $bookingid, int $courseid): int {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            mtrace("autocreateoption: course $courseid no longer exists, nothing to do.");
            return 0;
        }

        $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        if (empty($settings) || empty($settings->cmid)) {
            mtrace("autocreateoption: booking instance $bookingid no longer exists, nothing to do.");
            return 0;
        }

        $json = $settings->jsonobject ?? new stdClass();
        $linkedcategoryid = (int)($json->{self::JSONKEY_CATEGORY} ?? 0);
        if ($linkedcategoryid <= 0 || $linkedcategoryid !== (int)$course->category) {
            mtrace("autocreateoption: booking instance $bookingid is not linked to the category of course $courseid.");
            return 0;
        }
        $templateid = (int)($json->{self::JSONKEY_TEMPLATE} ?? 0);
        $linkcourse = isset($json->{self::JSONKEY_LINKCOURSE}) ? (int)$json->{self::JSONKEY_LINKCOURSE} : 1;

        // Never create a second option for the same course: covers a double delivery of the events as well as
        // courses booking created itself as connected courses of an option.
        if ($DB->record_exists('booking_options', ['bookingid' => $bookingid, 'courseid' => $courseid])) {
            mtrace("autocreateoption: an option for course $courseid already exists in booking instance $bookingid.");
            return 0;
        }
        if (!$linkcourse && self::unlinked_option_exists($bookingid, $courseid)) {
            mtrace("autocreateoption: an option for course $courseid already exists in booking instance $bookingid.");
            return 0;
        }

        // Loop prevention: an option of this instance may create its own connected course in the category of the
        // course the instance lives in. That category can not be linked, so never act on it.
        $instancecourse = get_course($settings->course);
        if ((int)$instancecourse->category === (int)$course->category) {
            mtrace("autocreateoption: booking instance $bookingid lives in the linked category itself, skipping.");
            return 0;
        }

        $context = context_module::instance($settings->cmid);
        $data = self::prepare_option_data($bookingid, (int)$settings->cmid, $templateid);

        $data->text = trim((string)$course->fullname);
        unset(
            $data->coursetemplateid,
            $data->createnewmoodlecoursefromtemplatewithusers,
            $data->addastemplate,
            $data->templatename,
            $data->identifier
        );
        // The automated path never creates courses: it links the existing one or none at all.
        if ($linkcourse) {
            $data->courseid = (int)$course->id;
            $data->chooseorcreatecourse = 1;
        } else {
            $data->courseid = 0;
            $data->chooseorcreatecourse = 0;
        }
        booking_option::add_data_to_json($data, self::OPTIONJSONKEY_SOURCECOURSE, (int)$course->id);

        $optionid = (int)booking_option::update($data, $context);
        mtrace("autocreateoption: created option $optionid for course $courseid in booking instance $bookingid.");

        // The users enrolled in the course are booked into the option and removed again when unenrolled. The course is
        // stored in the sync rule, so this works for options without a connected course too. The task books the users
        // that are enrolled already (e.g. restored or copied with users).
        if ($optionid > 0 && !empty($json->{self::JSONKEY_SYNCENROLMENTS})) {
            enrolmentsync::ensure_rule($optionid, (int)$course->id);
            enrolmentsync::queue_course_sync((int)$course->id);
        }

        return $optionid;
    }

    /**
     * Prepares the option data, either pre-filled from an option template or as a minimal new option.
     *
     * @param int $bookingid
     * @param int $cmid
     * @param int $templateid id of a booking_options row with bookingid 0, or 0 for no template
     * @return stdClass
     * @throws moodle_exception
     */
    private static function prepare_option_data(int $bookingid, int $cmid, int $templateid): stdClass {
        global $DB;

        if ($templateid > 0 && $DB->record_exists('booking_options', ['id' => $templateid, 'bookingid' => 0])) {
            // Same mechanism as the template selector of the option form: load the template through the field
            // classes and take over its values. oldcopyoptionid stays 0 so the template's connected course is
            // never duplicated.
            $templateoption = (object)[
                'fromtemplate' => true,
                'cmid' => $cmid,
                'id' => $templateid,
                'optionid' => $templateid,
                'bookingid' => $bookingid,
                'copyoptionid' => 0,
                'oldcopyoptionid' => 0,
                'returnurl' => '',
            ];
            $error = fields_info::set_data($templateoption);
            if (!empty($error)) {
                throw new moodle_exception('erroronsetdata', 'mod_booking', '', $error);
            }

            $data = (object)[
                'id' => 0,
                'optionid' => 0,
                'bookingid' => $bookingid,
                'cmid' => $cmid,
                'returnurl' => '',
            ];
            $excluded = ['id', 'optionid', 'cmid', 'bookingid', 'returnurl', 'identifier', 'sesskey', 'fromtemplate'];
            foreach ($templateoption as $key => $value) {
                if (strpos($key, MOD_BOOKING_FORM_OPTIONDATEID) !== false) {
                    $data->{$key} = 0;
                } else if (!in_array($key, $excluded, true)) {
                    $data->{$key} = $value;
                }
            }
            // The template's JSON is copied as a whole, the template name must not travel with it.
            booking_option::remove_key_from_json($data, 'templatename');
            return $data;
        }

        // No template: the minimal set of values for a new option, the rest comes from the defaults.
        return (object)[
            'id' => 0,
            'optionid' => 0,
            'bookingid' => $bookingid,
            'cmid' => $cmid,
            'importing' => 1,
            'addtocalendar' => 0,
            'maxanswers' => 0,
            'teachersforoption' => [],
            'responsiblecontact' => [],
        ];
    }

    /**
     * Whether an option that was auto-created for the course, but not connected to it, exists in the instance.
     *
     * Only needed when the option is not linked to the course, as then the courseid column can not be used.
     *
     * @param int $bookingid
     * @param int $courseid
     * @return bool
     */
    private static function unlinked_option_exists(int $bookingid, int $courseid): bool {
        global $DB;

        $select = "bookingid = :bookingid AND " . $DB->sql_like('json', ':marker');
        $params = [
            'bookingid' => $bookingid,
            'marker' => '%"' . self::OPTIONJSONKEY_SOURCECOURSE . '":' . $courseid . '%',
        ];
        return $DB->record_exists_select('booking_options', $select, $params);
    }
}
