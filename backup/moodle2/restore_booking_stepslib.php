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

use mod_booking\dates_handler;

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

        // Only restore teachers, if config setting is set.
        if (get_config('booking', 'duplicationrestoreteachers')) {
            $paths[] = new restore_path_element('booking_teacher',
                '/activity/booking/teachers/teacher');
        }

        // Only restore prices, if config setting is set.
        if (get_config('booking', 'duplicationrestoreprices')) {
            $paths[] = new restore_path_element('booking_price',
                '/activity/booking/options/option/prices/price');
        }

        // Only restore entitiesrelations, if config setting is set.
        if (get_config('booking', 'duplicationrestoreentities')) {
            $paths[] = new restore_path_element('booking_entity',
                '/activity/booking/options/option/entitiesrelations/entitiesrelation');
        }

        if ($userinfo) {
            $paths[] = new restore_path_element('booking_answer', '/activity/booking/answers/answer');
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    protected function process_booking($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $oldbookingid = $data->id;

        // Insert the booking record.
        $newbookingid = $DB->insert_record('booking', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newbookingid);

        $cmid = null;
        $cmidsql = "SELECT cm.id AS cmid
                    FROM {course_modules} cm
                    LEFT JOIN {modules} m
                    ON m.id = cm.module
                    WHERE m.name = 'booking' AND cm.instance = :newbookingid";

        if ($cmidrecord = $DB->get_record_sql($cmidsql, ['newbookingid' => $newbookingid])) {
            $cmid = $cmidrecord->cmid;

            // Also copy associated header images (images on instance level).
            $filesql = "SELECT id, component, contextid, filepath, filename, userid, source, author, license
                        FROM {files}
                        WHERE component = 'mod_booking'
                        AND filearea = 'bookingimages'
                        AND filesize > 0
                        AND mimetype LIKE 'image%'
                        AND itemid = :oldbookingid";

            $params = [
                'oldbookingid' => $oldbookingid
            ];

            $fs = get_file_storage();
            $oldimagefiles = $DB->get_records_sql($filesql, $params);
            foreach ($oldimagefiles as $oldimagefile) {
                // Prepare file record object.
                $fileinfo = [
                    'component' => 'mod_booking',
                    'filearea' => 'bookingimages',
                    'itemid' => $oldbookingid,
                    'contextid' => $oldimagefile->contextid,
                    'filepath' => $oldimagefile->filepath,
                    'filename' => $oldimagefile->filename,
                    'userid' => $oldimagefile->userid,
                    'source' => $oldimagefile->source,
                    'author' => $oldimagefile->author,
                    'license' => $oldimagefile->license
                ];

                // Get file.
                $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                                    $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);

                // Read contents of the old image file.
                if ($file && $cmid) {
                    $contents = $file->get_content();
                    // Now store a copied image file with the new bookingid.
                    $fileinfo['itemid'] = $newbookingid; // New bookingid of the instance duplicate.
                    // Important: set the correct context of the new instance.
                    $context = context_module::instance($cmid);
                    $fileinfo['contextid'] = $context->id;
                    $fs->create_file_from_string($fileinfo, $contents);
                }
            }
        }
    }

    protected function process_booking_option($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $cmid = null;
        $cmidsql = "SELECT cm.id AS cmid
                    FROM {course_modules} cm
                    LEFT JOIN {modules} m
                    ON m.id = cm.module
                    WHERE m.name = 'booking' AND cm.instance = :newbookingid";

        if ($cmidrecord = $DB->get_record_sql($cmidsql, ['newbookingid' => $data->bookingid])) {
            $cmid = $cmidrecord->cmid;
        }

        // Calendarid should not be copied or set.
        $data->addtocalendar = 0;
        $data->calendarid = 0;

        // Unique identifier must not be copied, instead we create a new random one.
        $data->identifier = substr(str_shuffle(md5(microtime())), 0, 8);

        $newitemid = $DB->insert_record('booking_options', $data);

        // Also copy custom fields (e.g. sports).
        // Note: Do not confuse normal customfields (stored in customfield_data) with booking_customfields (used for optiondates).
        // This SQL will only select customfields for the mod_booking component.
        $sql = "SELECT cfd.*
            FROM {customfield_data} cfd
            LEFT JOIN {customfield_field} cff
            ON cff.id = cfd.fieldid
            LEFT JOIN {customfield_category} cfc
            ON cfc.id = cff.categoryid
            WHERE cfc.component = 'mod_booking'
            AND cfd.instanceid = :oldid";

        $params = [
            'oldid' => $oldid
        ];

        $oldcustomfields = $DB->get_records_sql($sql, $params);
        foreach ($oldcustomfields as $cf) {
            unset($cf->id);
            $cf->timecreated = time();
            $cf->timemodified = time();
            $cf->instanceid = $newitemid;
            $DB->insert_record('customfield_data', $cf);
        }

        // Also copy image files associated with the booking option.
        $filesql = "SELECT id, component, contextid, filepath, filename, userid, source, author, license
            FROM {files}
            WHERE component = 'mod_booking'
            AND filearea = 'bookingoptionimage'
            AND filesize > 0
            AND mimetype LIKE 'image%'
            AND itemid = :oldoptionid";

        $params = [
            'oldoptionid' => $oldid
        ];

        $fs = get_file_storage();
        $oldimagefiles = $DB->get_records_sql($filesql, $params);
        foreach ($oldimagefiles as $oldimagefile) {
            // Prepare file record object.
            $fileinfo = [
                'component' => 'mod_booking',
                'filearea' => 'bookingoptionimage',
                'itemid' => $oldid,
                'contextid' => $oldimagefile->contextid,
                'filepath' => $oldimagefile->filepath,
                'filename' => $oldimagefile->filename,
                'userid' => $oldimagefile->userid,
                'source' => $oldimagefile->source,
                'author' => $oldimagefile->author,
                'license' => $oldimagefile->license
            ];

            // Get file.
            $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                                $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);

            // Read contents of the old image file.
            if ($file && $cmid) {
                $contents = $file->get_content();
                // Now store a copied image file with the new optionid.
                // Prepare new file record object.
                $fileinfo['itemid'] = $newitemid; // New optionid of the duplicate.
                // Important: set the correct context of the new instance.
                $context = context_module::instance($cmid);
                $fileinfo['contextid'] = $context->id;
                $fs->create_file_from_string($fileinfo, $contents);
            }
        }

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
        // Only change userid, if a mapped id could be found.
        if ($this->get_mappingid('user', $data->userid)) {
            $data->userid = $this->get_mappingid('user', $data->userid);
        }
        $DB->insert_record('booking_teachers', $data);

        // When inserting a new teacher, we also need to insert the teacher for each optiondate.
        dates_handler::subscribe_teacher_to_all_optiondates($data->optionid, $data->userid);

        // No need to save this mapping as far as nothing depends on it.
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
        // No need to save this mapping as far as nothing depends on it.
    }

    protected function process_booking_entity($data) {
        global $DB;

        // Make sure, we have local_entities installed.
        if (get_config('booking', 'duplicationrestoreentities') && class_exists('local_entities\entitiesrelation_handler')) {
            $data = (object) $data;
            $data->instanceid = $this->get_mappingid('booking_option', $data->instanceid);
            $data->timecreated = time();
            $DB->insert_record('local_entities_relations', $data);
            // No need to save this mapping as far as nothing depends on it.
        }
    }

    protected function process_booking_customfield($data) {
        global $DB;

        $data = (object)$data;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);

        $data->optiondateid = $this->get_mappingid('booking_optiondate', $data->optiondateid);

        $DB->insert_record('booking_customfields', $data);
        // No need to save this mapping as far as nothing depends on it.
    }

    protected function process_booking_price($data) {
        global $DB;

        $data = (object) $data;
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $DB->insert_record('booking_prices', $data);
        // No need to save this mapping as far as nothing depends on it.
    }

    protected function after_execute() {
        // Add booking related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_booking', 'intro', null);
        $this->add_related_files('mod_booking', 'bookingpolicy', null);
        $this->add_related_files('mod_booking', 'description', 'booking_option');
    }
}
