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
 * Adhoc task to finalize a course that was created from a template.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use core_tag_tag;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use mod_booking\teachers_handler;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Strips the inherited template tags from a course created from a template.
 *
 * The course is duplicated asynchronously by \core\task\asynchronous_copy_task, which re-adds the
 * template's tags to the copy. This task runs afterwards and removes them, so the duplicated course
 * is not itself listed as a selectable template.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalize_template_course extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskfinalizetemplatecourse', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws moodle_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {

        global $DB;

        $taskdata = $this->get_custom_data();
        $newcourseid = $taskdata->newcourseid ?? 0;

        if (empty($newcourseid)) {
            return;
        }

        // If the async copy/restore for this course is still running, retry later.
        // Same detection used in the option form's course selector (courseid::instance_form_definition).
        $sql = "SELECT c.id
                  FROM {course} c
                  JOIN {backup_controllers} bc ON c.id = bc.itemid
                  JOIN {task_adhoc} ta
                    ON ta.customdata LIKE " . $DB->sql_concat("'%backupid%'", "bc.backupid", "'%'") . "
                 WHERE bc.operation = 'restore' AND c.id = :courseid";
        if ($DB->record_exists_sql($sql, ['courseid' => $newcourseid])) {
            // Throwing reschedules this adhoc task with the standard exponential backoff.
            throw new moodle_exception(
                'templatecoursestillduplicating',
                'mod_booking',
                '',
                $newcourseid
            );
        }

        // The course might have been deleted in the meantime.
        if (!$DB->record_exists('course', ['id' => $newcourseid])) {
            mtrace("finalize_template_course: course $newcourseid no longer exists, nothing to do.");
            return;
        }

        // Core's async restore forces the fullname to be unique via
        // restore_dbops::calculate_course_names(), appending a " copy N" suffix when another course
        // already shares the name. Reset it to the intended value (duplicate fullnames are allowed in
        // Moodle - only the restore copy path enforces uniqueness).
        $desiredfullname = $taskdata->fullname ?? '';
        if (!empty($desiredfullname)) {
            $current = get_course($newcourseid);
            if ($current->fullname !== $desiredfullname) {
                update_course((object) ['id' => $newcourseid, 'fullname' => $desiredfullname]);
                mtrace("finalize_template_course: reset course $newcourseid fullname to \"$desiredfullname\".");
            }
        }

        // Strip all tags from the duplicated course so it is not treated as a template itself.
        $tags = core_tag_tag::get_item_tags('core', 'course', $newcourseid);
        if (!empty($tags)) {
            core_tag_tag::delete_instances_by_id(array_keys($tags));
        }

        // The async restore rebuilds the destination course's enrolment instances, dropping any
        // booking enrolments added to the (previously empty) shell before the copy finished. Re-run
        // the booking enrolment for every option linked to this course to restore them.
        $responsibleenrol = (bool) get_config('booking', 'responsiblecontactenroltocourse');
        $responsiblerole = (int) get_config('booking', 'definedresponsiblecontactrole');

        $options = $DB->get_records('booking_options', ['courseid' => $newcourseid], '', 'id');
        foreach ($options as $optionrecord) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionrecord->id);
            if (empty($settings->cmid)) {
                continue;
            }
            $cmid = $settings->cmid;
            // Make sure cached settings reflect the now-restored course.
            booking_option::purge_cache_for_option($optionrecord->id);
            $bo = singleton_service::get_instance_of_booking_option($cmid, $optionrecord->id);
            $settings = $bo->settings;

            // Re-enrol booked users (mirrors booking_option::update() post-save enrolment).
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            foreach ($ba->get_usersonlist() as $bookeduser) {
                $bo->enrol_user_coursestart($bookeduser->id);
            }

            // Re-enrol responsible contacts (mirrors responsiblecontact::save_data()).
            if ($responsibleenrol) {
                foreach (($settings->responsiblecontact ?? []) as $contactid) {
                    if (!empty($contactid)) {
                        $bo->enrol_user((int) $contactid, false, $responsiblerole, false, $newcourseid, true);
                    }
                }
            }

            // Re-enrol teachers (mirrors teachers_handler::subscribe_teacher_to_booking_option()).
            $teachershandler = new teachers_handler($optionrecord->id);
            foreach (($settings->teachers ?? []) as $teacher) {
                $teachershandler->subscribe_teacher_to_booking_option(
                    $teacher->userid,
                    $optionrecord->id,
                    $cmid,
                    null,
                    true,
                    $newcourseid
                );
            }
        }

        mtrace("finalize_template_course: finalized duplicated course $newcourseid.");
    }
}
