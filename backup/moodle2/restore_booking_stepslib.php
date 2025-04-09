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
 * Defines all the restore steps that will be used by the restore_booking_activity_task
 *
 * @package mod_booking
 * @copyright 2012 onwards David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_option;
use mod_booking\teachers_handler;

/**
 * Structure step to restore one booking activity
 */
class restore_booking_activity_structure_step extends restore_activity_structure_step {

    /**
     * Function that will return the structure to be processed by this restore_step.
     * Must return one array of @restore_path_element elements
     *
     * @return mixed
     * @throws base_step_exception
     * @throws dml_exception
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('booking', '/activity/booking');

        $paths[] = new restore_path_element(
            'booking_category',
            '/activity/booking/categories/caegory'
        );
        $paths[] = new restore_path_element(
            'booking_tag',
            '/activity/booking/tags/tag'
        );
        $paths[] = new restore_path_element(
            'booking_history',
            '/activity/booking/history/historyitem'
        );
        $paths[] = new restore_path_element(
            'booking_other',
            '/activity/booking/options/option/others/other'
        );

        $paths[] = new restore_path_element(
            'booking_customfield',
            '/activity/booking/customfields/customfield'
        );

        // If we don't have booking options, of course we don't have any of the below settings.
        if (get_config('booking', 'duplicationrestorebookings')) {
            $paths[] = new restore_path_element(
                'booking_option',
                '/activity/booking/options/option'
            );
            $paths[] = new restore_path_element(
                'booking_optiondate',
                '/activity/booking/optiondates/optiondate'
            );

            // Only restore teachers, if config setting is set.
            if (get_config('booking', 'duplicationrestoreteachers')) {
                $paths[] = new restore_path_element(
                    'booking_teacher',
                    '/activity/booking/teachers/teacher'
                );
            }

            // Only restore prices, if config setting is set.
            if (get_config('booking', 'duplicationrestoreprices')) {
                $paths[] = new restore_path_element(
                    'booking_price',
                    '/activity/booking/options/option/prices/price'
                );
            }

            // Only restore entitiesrelations if config setting is set.
            if (get_config('booking', 'duplicationrestoreentities')) {
                // For options.
                $paths[] = new restore_path_element(
                    'booking_option_entity',
                    '/activity/booking/options/option/entitiesrelationsforoptions/entitiesrelationforoption'
                );
                // For optiondates.
                $paths[] = new restore_path_element(
                    'booking_optiondate_entity',
                    '/activity/booking/optiondates/optiondate/entitiesrelationsforoptiondates/entitiesrelationforoptiondate'
                );
            }

            // Only restore subbookingoptions (aka subbookings), if config setting is set.
            if (get_config('booking', 'duplicationrestoresubbookings')) {
                $paths[] = new restore_path_element(
                    'booking_subbookingoption',
                    '/activity/booking/options/option/subbookingoptions/subbookingoption'
                );
            }
        }

        if ($userinfo) {
            $paths[] = new restore_path_element('booking_answer', '/activity/booking/answers/answer');
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Processes the instance.
     *
     * @param array $data The instance data from the backup file.
     * @throws base_step_exception
     * @throws dml_exception
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
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
                'oldbookingid' => $oldbookingid,
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
                    'license' => $oldimagefile->license,
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

    /**
     * This is executed every time we have one /MOODLE_BACKUP/COURSE/MODULES/MOD/CHOICE/OPTIONS/OPTION
     * data available
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     * @throws file_exception
     * @throws restore_step_exception
     * @throws stored_file_creation_exception
     */
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
        if (empty($data->identifier) || $DB->record_exists('booking_options', ['identifier' => $data->identifier])) {
            // If the identifier already exists, we need to create a new one.
            $data->identifier = booking_option::create_truly_unique_option_identifier();
        }

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
            'oldid' => $oldid,
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
            'oldoptionid' => $oldid,
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
                'license' => $oldimagefile->license,
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

    /**
     * Processes booking answer data.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
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

    /**
     * Processes booking option date data.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     * @throws restore_step_exception
     */
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

    /**
     * Processes booking teacher data.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function process_booking_teacher($data) {
        global $DB;

        $data = (object) $data;
        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        // Only change userid, if a mapped id could be found.
        $data->userid = $this->get_mappingid('user', $data->userid, $data->userid);

        // If one ID is missing, we show a debug message and return.
        if (empty($data->bookingid)) {
            debugging('process_booking_teacher - bookingid missing for $data: ' . json_encode($data));
            return;
        }
        if (empty($data->optionid)) {
            debugging('process_booking_teacher - optionid missing for $data: ' . json_encode($data));
            return;
        }
        if (empty($data->userid)) {
            debugging('process_booking_teacher - userid missing for $data: ' . json_encode($data));
            return;
        }

        $DB->insert_record('booking_teachers', $data);

        // When inserting a new teacher, we also need to insert the teacher for each optiondate.
        teachers_handler::subscribe_teacher_to_all_optiondates($data->optionid, $data->userid);

        // No need to save this mapping as far as nothing depends on it.
    }

    /**
     * Processes booking category data.
     *
     * @param array $data The instance data from the backup file.
     * @throws base_step_exception
     * @throws dml_exception
     */
    protected function process_booking_category($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $DB->insert_record('booking_category', $data);
        // No need to save this mapping as far as nothing depend on it.
    }

    /**
     * Processes booking tag data.
     *
     * @param array $data The instance data from the backup file.
     * @throws base_step_exception
     * @throws dml_exception
     */
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

    /**
     * Processes booking other data.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
    protected function process_booking_other($data) {
        global $DB;

        $data = (object) $data;
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $DB->insert_record('booking_other', $data);
        // No need to save this mapping as far as nothing depends on it.
    }
    /**
     * Processes booking history data.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
    protected function process_booking_history($data) {
        global $DB;
        $data = (object)$data;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $data->answerid = $this->get_mappingid('booking_answers', $data->answerid);
        $data->userid = $this->get_mappingid('user', $data->userid);

        $DB->insert_record('booking_history', $data);
    }


    /**
     * Processes booking entity data for booking options.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
    protected function process_booking_option_entity($data) {
        global $DB;

        // Make sure, we have local_entities installed.
        if (get_config('booking', 'duplicationrestoreentities') && class_exists('local_entities\entitiesrelation_handler')) {
            $data = (object) $data;
            if ($data->area == 'optiondate') {
                return;
            }
            if ($data->area != 'option') {
                throw new moodle_exception('entityrelationhasinvalidarea');
            }
            $data->instanceid = $this->get_mappingid('booking_option', $data->instanceid);
            $data->timecreated = time();
            $DB->insert_record('local_entities_relations', $data);
            // No need to save this mapping as far as nothing depends on it.
        }
    }

    /**
     * Processes booking entity data for booking optiondates.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
    protected function process_booking_optiondate_entity($data) {
        global $DB;

        // Make sure, we have local_entities installed.
        if (get_config('booking', 'duplicationrestoreentities') && class_exists('local_entities\entitiesrelation_handler')) {
            $data = (object) $data;
            if ($data->area == 'option') {
                return;
            }
            if ($data->area != 'optiondate') {
                throw new moodle_exception('entityrelationhasinvalidarea');
            }
            $data->instanceid = $this->get_mappingid('booking_optiondate', $data->instanceid);
            $data->timecreated = time();
            $DB->insert_record('local_entities_relations', $data);
            // No need to save this mapping as far as nothing depends on it.
        }
    }

    /**
     * Processes subbooking options.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
    protected function process_booking_subbookingoption($data) {
        global $DB, $USER;

        if (get_config('booking', 'duplicationrestoresubbookings')) {
            $data = (object) $data;
            $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
            $data->timecreated = time();
            $data->timemodified = time();
            $data->usermodified = $USER->id;
            $DB->insert_record('booking_subbooking_options', $data);
        }
    }

    /**
     * Processes booking custom field data.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
    protected function process_booking_customfield($data) {
        global $DB;

        $data = (object)$data;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);

        $data->optiondateid = $this->get_mappingid('booking_optiondate', $data->optiondateid);

        $DB->insert_record('booking_customfields', $data);
        // No need to save this mapping as far as nothing depends on it.
    }

    /**
     * Processes booking price data.
     *
     * @param array $data The instance data from the backup file.
     * @throws dml_exception
     */
    protected function process_booking_price($data) {
        global $DB;

        $data = (object) $data;

        if ($data->area == 'option') {
            $data->itemid = $this->get_mappingid('booking_option', $data->itemid);
            $DB->insert_record('booking_prices', $data);
            // No need to save this mapping as far as nothing depends on it.
        }
        // NOTE: In the future we might want to support additional price areas!
    }

    /**
     * Post-execution processing.
     */
    protected function after_execute() {
        // Add booking related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_booking', 'intro', null);
        $this->add_related_files('mod_booking', 'bookingpolicy', null);
        $this->add_related_files('mod_booking', 'description', 'booking_option');
    }
}
