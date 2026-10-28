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
use mod_booking\local\sync\completionsync;
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

    /** @var string JSON key (booking.json): 1 = complete the booking when the user completes the course. */
    const JSONKEY_SYNCCOMPLETION = 'autocreateoptionsynccompletion';

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
        $records = $DB->get_records_select(
            'booking',
            $DB->sql_isnotempty('booking', 'json', true, true),
            [],
            '',
            'id, json'
        );
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
     * The numbers shown before a linked category is synced by hand, see {@see self::sync_category()}.
     *
     * @param int $bookingid
     * @return array{categoryid:int, courses:int, missing:int, syncenrolments:bool}
     */
    public static function get_category_sync_preview(int $bookingid): array {
        $preview = ['categoryid' => 0, 'courses' => 0, 'missing' => 0, 'syncenrolments' => false];

        $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        $json = $settings->jsonobject ?? new stdClass();
        $categoryid = (int)($json->{self::JSONKEY_CATEGORY} ?? 0);
        if ($categoryid <= 0) {
            return $preview;
        }

        $preview['categoryid'] = $categoryid;
        $preview['syncenrolments'] = !empty($json->{self::JSONKEY_SYNCENROLMENTS});
        $courseids = self::get_category_course_ids($categoryid);
        $preview['courses'] = count($courseids);
        $covered = self::get_covered_course_ids($bookingid);
        $preview['missing'] = count(array_diff($courseids, array_keys($covered)));
        return $preview;
    }

    /**
     * Syncs the courses that already exist in the linked category with the booking instance. Called from the adhoc
     * task that the "Sync the linked course category now" button queues.
     *
     * Every course without an option gets one, exactly as if it had just been created (the observers only cover
     * courses created after the category was linked). When the enrolment sync is on, every auto-created option of
     * the instance gets its course sync rule and the users enrolled in its course are booked by the course sync
     * tasks. The settings are read at execution time, and a course that can not be handled does not stop the rest.
     *
     * @param int $bookingid
     * @return array{created:int, skipped:int, syncqueued:int, completed:int}
     */
    public static function sync_category(int $bookingid): array {
        $result = ['created' => 0, 'skipped' => 0, 'syncqueued' => 0, 'completed' => 0];

        $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        if (empty($settings) || empty($settings->cmid)) {
            mtrace("autocreateoption: booking instance $bookingid no longer exists, nothing to sync.");
            return $result;
        }
        $json = $settings->jsonobject ?? new stdClass();
        $categoryid = (int)($json->{self::JSONKEY_CATEGORY} ?? 0);
        if ($categoryid <= 0) {
            mtrace("autocreateoption: booking instance $bookingid links no course category, nothing to sync.");
            return $result;
        }
        if (!wb_payment::pro_version_is_activated()) {
            mtrace("autocreateoption: the PRO version is not activated, nothing to sync.");
            return $result;
        }
        // Loop prevention, see create_option_for_course(): the own category is never acted on.
        if ((int)get_course($settings->course)->category === $categoryid) {
            mtrace("autocreateoption: booking instance $bookingid lives in the linked category itself, nothing to sync.");
            return $result;
        }

        $courseids = self::get_category_course_ids($categoryid);
        foreach ($courseids as $courseid) {
            try {
                $optionid = self::create_option_for_course($bookingid, $courseid);
            } catch (moodle_exception $e) {
                mtrace("autocreateoption: could not create the option for course $courseid: " . $e->getMessage());
                $optionid = 0;
            }
            if ($optionid > 0) {
                $result['created']++;
            } else {
                $result['skipped']++;
            }
        }

        // Bookings of users who completed their course already are completed with the date of the course completion.
        // Bookings the enrolment sync makes later are handled by the enrolment sync task itself.
        if (!empty($json->{self::JSONKEY_SYNCCOMPLETION})) {
            foreach ($courseids as $courseid) {
                $result['completed'] += completionsync::sync_course($courseid, $bookingid);
            }
        }

        // Options created above got their rule already; this covers the options auto-created before the enrolment
        // sync was switched on, and re-queues the course syncs, which are deduplicated.
        if (!empty($json->{self::JSONKEY_SYNCENROLMENTS})) {
            $result['syncqueued'] = enrolmentsync::apply_instance_setting($bookingid);
        }

        mtrace(
            "autocreateoption: synced category $categoryid with booking instance $bookingid, created "
            . "{$result['created']} options, skipped {$result['skipped']} courses, queued {$result['syncqueued']} "
            . "course enrolment syncs, completed {$result['completed']} bookings."
        );
        return $result;
    }

    /**
     * The ids of the courses directly in a category, in category order. Subcategories are not taken into account,
     * like with the observers.
     *
     * @param int $categoryid
     * @return int[]
     */
    private static function get_category_course_ids(int $categoryid): array {
        global $DB;

        $courseids = array_keys($DB->get_records('course', ['category' => $categoryid], 'sortorder', 'id'));
        return array_map('intval', $courseids);
    }

    /**
     * The ids of the courses an option of the instance exists for: connected as its course or auto-created for it.
     *
     * @param int $bookingid
     * @return array courseid => true
     */
    private static function get_covered_course_ids(int $bookingid): array {
        global $DB;

        $covered = [];
        $options = $DB->get_records('booking_options', ['bookingid' => $bookingid], '', 'id, courseid, json');
        foreach ($options as $option) {
            if ((int)$option->courseid > 0) {
                $covered[(int)$option->courseid] = true;
            }
            $json = empty($option->json) ? null : json_decode($option->json);
            $sourcecourseid = (int)($json->{self::OPTIONJSONKEY_SOURCECOURSE} ?? 0);
            if ($sourcecourseid > 0) {
                $covered[$sourcecourseid] = true;
            }
        }
        return $covered;
    }

    /**
     * Removes the auto-creation marker from option data that is copied into a new option.
     *
     * The marker travels with the JSON of the option. Only the option the task created for the course may carry it,
     * so that it reliably identifies auto-created options: duplicates, templates, recurring children, options split
     * from the dates and restored options are made by people (or from a backup), not for the course.
     *
     * @param stdClass $data option data with a json property, modified in place
     * @return void
     */
    public static function strip_marker(stdClass $data): void {
        if (!empty($data->json)) {
            booking_option::remove_key_from_json($data, self::OPTIONJSONKEY_SOURCECOURSE);
        }
    }

    /**
     * The course an existing option was auto-created for, 0 if the option was not auto-created.
     *
     * @param int $optionid
     * @return int
     */
    public static function get_source_course(int $optionid): int {
        if ($optionid <= 0) {
            return 0;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        return (int)($settings->jsonobject->{self::OPTIONJSONKEY_SOURCECOURSE} ?? 0);
    }

    /**
     * Whether an auto-created option may get this connected course.
     *
     * An auto-created option may only be connected to the course it was created for, or to no course. Any other
     * course would let the course enrolment sync close a cycle: the sync books from course A, the booking enrols
     * into course B, whose own synced option may enrol back into A - and nobody can be removed any more. This holds
     * whether the sync is on or not, as switching it on later creates the sync rule for course A.
     *
     * @param int $optionid the existing option, 0 for a new one
     * @param int $chooseorcreatecourse 0 = no course, 1 = existing course, 2 / 3 = create a new course
     * @param int $courseid the course chosen with 1
     * @return bool
     */
    public static function connected_course_allowed(int $optionid, int $chooseorcreatecourse, int $courseid): bool {
        $sourcecourseid = self::get_source_course($optionid);
        if ($sourcecourseid <= 0 || $chooseorcreatecourse === 0) {
            return true;
        }
        if ($chooseorcreatecourse === 1) {
            return $courseid <= 0 || $courseid === $sourcecourseid;
        }
        return false;
    }

    /**
     * The message for a connected course that is not allowed, see {@see self::connected_course_allowed()}.
     *
     * @param int $optionid
     * @return string
     */
    public static function connected_course_locked_message(int $optionid): string {
        return get_string('autocreateoption:connectedcourselocked', 'mod_booking', self::get_source_course_name($optionid));
    }

    /**
     * The name of the course an option was auto-created for, its id if the course is gone.
     *
     * @param int $optionid
     * @return string
     */
    public static function get_source_course_name(int $optionid): string {
        global $DB;

        $sourcecourseid = self::get_source_course($optionid);
        $fullname = $DB->get_field('course', 'fullname', ['id' => $sourcecourseid]);
        return format_string($fullname ?: (string)$sourcecourseid);
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
     * The options of a booking instance that belong to a course: connected to it, or auto-created for it (and then
     * maybe not connected). Both lookups are limited to the instance, so they use the bookingid index.
     *
     * @param int $bookingid
     * @param int $courseid
     * @return int[] option ids
     */
    public static function get_option_ids_for_course(int $bookingid, int $courseid): array {
        global $DB;

        if ($bookingid <= 0 || $courseid <= 0) {
            return [];
        }
        $optionids = array_keys(
            $DB->get_records('booking_options', ['bookingid' => $bookingid, 'courseid' => $courseid], '', 'id')
        );
        $select = "bookingid = :bookingid AND " . $DB->sql_like('json', ':marker');
        $params = [
            'bookingid' => $bookingid,
            'marker' => '%"' . self::OPTIONJSONKEY_SOURCECOURSE . '":' . $courseid . '%',
        ];
        foreach ($DB->get_records_select('booking_options', $select, $params, '', 'id, json') as $option) {
            // The LIKE also matches e.g. course 12 for course 1, so the decoded value decides.
            $json = json_decode($option->json);
            if ((int)($json->{self::OPTIONJSONKEY_SOURCECOURSE} ?? 0) === $courseid) {
                $optionids[] = (int)$option->id;
            }
        }
        return array_values(array_unique(array_map('intval', $optionids)));
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
