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
 *
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * Structure step to restore one booking activity
 */
class restore_booking_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('booking', '/activity/booking');
        $paths[] = new restore_path_element('booking_option', '/activity/booking/options/option');
        $paths[] = new restore_path_element('booking_category',
                '/activity/booking/categories/caegory');
        $paths[] = new restore_path_element('booking_tag', '/activity/booking/tags/tag');
        $paths[] = new restore_path_element('booking_institution',
                '/activity/booking/institutions/institution');
        $paths[] = new restore_path_element('booking_other',
                '/activity/booking/options/option/others/other');
        $paths[] = new restore_path_element('booking_optiondate',
                '/activity/booking/optiondates/optiondate');
        $paths[] = new restore_path_element('booking_customfield',
                '/activity/booking/customfields/customfield');

        if ($userinfo) {
            $paths[] = new restore_path_element('booking_answer', '/activity/booking/answers/answer');
            $paths[] = new restore_path_element('booking_teacher',
                    '/activity/booking/teachers/teacher');
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    protected function process_booking($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Insert the booking record.
        $newitemid = $DB->insert_record('booking', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    protected function process_booking_option($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Calendarid should not be copied or set.
        $data->addtocalendar = 0;
        $data->calendarid = 0;

        $newitemid = $DB->insert_record('booking_options', $data);
        $this->set_mapping('booking_option', $oldid, $newitemid);
    }

    protected function process_booking_answer($data) {
        global $DB;

        $data = (object) $data;
        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('booking_answers', $data);
        // No need to save this mapping as far as nothing depend on it.
    }

    protected function process_booking_optiondate($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);

        // Eventid should not be copied or set.
        $data->eventid = 0;

        $newitemid = $DB->insert_record('booking_optiondates', $data);
        $this->set_mapping('booking_optiondate', $oldid, $newitemid);
    }

    protected function process_booking_teacher($data) {
        global $DB;

        $data = (object) $data;
        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $DB->insert_record('booking_teachers', $data);
        // No need to save this mapping as far as nothing depend on it.
    }

    protected function process_booking_category($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $DB->insert_record('booking_category', $data);
        // No need to save this mapping as far as nothing depend on it.
    }

    protected function process_booking_tag($data) {
        global $DB;

        $data = (object) $data;
        $data->courseid = $this->get_courseid();
        // When duplicating this module instance, it duplicates also tags.
        // There is no need to duplicate, so before inserting, check, if tag exists.
        $nofrecords = $DB->count_records('booking_tags', ['courseid' => $data->courseid, 'tag' => $data->tag]);
        if ($nofrecords == 0) {
            $DB->insert_record('booking_tags', $data);
        }
        // No need to save this mapping as far as nothing depend on it.
    }

    protected function process_booking_institution($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $DB->insert_record('booking_institutions', $data);
        // No need to save this mapping as far as nothing depend on it.
    }

    protected function process_booking_other($data) {
        global $DB;

        $data = (object) $data;
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $DB->insert_record('booking_other', $data);
        // No need to save this mapping as far as nothing depend on it.
    }

    protected function process_booking_customfield($data) {
        global $DB;

        $data = (object)$data;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);

        $data->optiondateid = $this->get_mappingid('booking_optiondate', $data->optiondateid);

        $DB->insert_record('booking_customfields', $data);
        // No need to save this mapping as far as nothing depend on it.
    }

    protected function after_execute() {
        // Add booking related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_booking', 'intro', null);
        $this->add_related_files('mod_booking', 'bookingpolicy', null);
        $this->add_related_files('mod_booking', 'description', 'booking_option');
    }
}
