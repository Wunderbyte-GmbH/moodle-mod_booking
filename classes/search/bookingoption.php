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
 * Search area for booking options.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\search;

use context;
use context_module;
use core_search\document;
use core_search\document_factory;
use core_search\manager;
use mod_booking\local\search\optioncontent;
use mod_booking\local\search\searchconfig;
use moodle_url;
use stdClass;

/**
 * Search area for booking options.
 *
 * One document per booking option. The content of the document is assembled from the option
 * record and from related data, see mod_booking\local\search\optioncontent.
 */
class bookingoption extends \core_search\base_mod {
    /** @var array Cache of the option records used within one request. */
    protected $optionrecords = [];

    /**
     * Returns the recordset of all booking options to be indexed.
     *
     * Template options (bookingid = 0) do not belong to an instance and are never indexed.
     *
     * @param int $modifiedfrom timestamp
     * @param context|null $context restrict the scope of the returned results
     * @return \moodle_recordset|null
     */
    public function get_document_recordset($modifiedfrom = 0, ?context $context = null) {
        global $DB;

        [$contextjoin, $contextparams] = $this->get_context_restriction_sql($context, 'booking', 'b', SQL_PARAMS_NAMED);

        if ($contextjoin === null) {
            return null;
        }

        $invisiblewhere = '';
        if (!searchconfig::index_invisible_options()) {
            $invisiblewhere = ' AND (bo.invisible = 0 OR bo.invisible IS NULL)';
        }

        $sql = "SELECT bo.*, b.course AS instancecourseid
                  FROM {booking_options} bo
                  JOIN {booking} b ON b.id = bo.bookingid
                  $contextjoin
                 WHERE bo.timemodified >= :timemodified
                   AND bo.bookingid > 0
                   $invisiblewhere
              ORDER BY bo.timemodified ASC";

        return $DB->get_recordset_sql($sql, array_merge($contextparams, ['timemodified' => $modifiedfrom]));
    }

    /**
     * Returns the document associated with one booking option.
     *
     * @param stdClass $record record of booking_options, with instancecourseid
     * @param array $options
     * @return document|bool
     */
    public function get_document($record, $options = []) {
        try {
            $cm = $this->get_cm('booking', $record->bookingid, $record->instancecourseid);
            $context = context_module::instance($cm->id);
        } catch (\dml_missing_record_exception $ex) {
            debugging(
                'Error retrieving mod_booking ' . $record->id . ' document, not all required data is available: ' .
                    $ex->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        } catch (\dml_exception $ex) {
            debugging('Error retrieving mod_booking ' . $record->id . ' document: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            return false;
        }

        $content = optioncontent::build($record);

        $doc = document_factory::instance($record->id, $this->componentname, $this->areaname);
        $doc->set('title', $content['title']);
        $doc->set('content', $content['content']);
        $doc->set('description1', $content['description1']);
        $doc->set('description2', $content['description2']);
        $doc->set('contextid', $context->id);
        $doc->set('courseid', $record->instancecourseid);
        $doc->set('owneruserid', manager::NO_OWNER_ID);
        $doc->set('modified', $record->timemodified);

        if (isset($options['lastindexedtime']) && $options['lastindexedtime'] < $record->timecreated) {
            // The option was created after the last indexing run, so it is a new document.
            $doc->set_is_new(true);
        }

        return $doc;
    }

    /**
     * Whether the current user may see this booking option.
     *
     * The availability conditions of mod_booking gate booking, not seeing, so they must not
     * restrict search results. Only the visibility of the course module and the invisible flag
     * of the option do.
     *
     * @param int $id id of the booking option
     * @return int
     */
    public function check_access($id) {
        if (isguestuser() || !isloggedin()) {
            return manager::ACCESS_DENIED;
        }

        $record = $this->get_option_record($id);

        if (empty($record) || empty($record->bookingid)) {
            return manager::ACCESS_DELETED;
        }

        try {
            $cm = $this->get_cm('booking', $record->bookingid, $record->instancecourseid);
            $context = context_module::instance($cm->id);
        } catch (\dml_missing_record_exception $ex) {
            return manager::ACCESS_DELETED;
        }

        if (!$cm->uservisible) {
            return manager::ACCESS_DENIED;
        }

        if (!empty($record->invisible) && !has_capability('mod/booking:canseeinvisibleoptions', $context)) {
            return manager::ACCESS_DENIED;
        }

        return manager::ACCESS_GRANTED;
    }

    /**
     * Link to the booking option.
     *
     * @param document $doc
     * @return moodle_url
     */
    public function get_doc_url(document $doc) {
        $context = context::instance_by_id($doc->get('contextid'));

        return new moodle_url('/mod/booking/optionview.php', [
            'optionid' => $doc->get('itemid'),
            'cmid' => $context->instanceid,
        ]);
    }

    /**
     * Link to the booking instance the option belongs to.
     *
     * @param document $doc
     * @return moodle_url
     */
    public function get_context_url(document $doc) {
        $context = context::instance_by_id($doc->get('contextid'));

        return new moodle_url('/mod/booking/view.php', ['id' => $context->instanceid]);
    }

    /**
     * Booking options can carry attached files.
     *
     * @return bool
     */
    public function uses_file_indexing() {
        return searchconfig::is_source_enabled(searchconfig::SOURCE_ATTACHMENTS);
    }

    /**
     * The file areas of a booking option which hold indexable files.
     *
     * Images of the booking option carry no text and are not indexed.
     *
     * @return array
     */
    public function get_search_fileareas() {
        return ['myfilemanageroption'];
    }

    /**
     * Attach the files of the booking option to the document.
     *
     * @param document $doc
     * @return void
     */
    public function attach_files($doc) {
        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_ATTACHMENTS)) {
            return;
        }

        $optionid = $doc->get('itemid');
        $fs = get_file_storage();

        foreach ($this->get_search_fileareas() as $filearea) {
            $files = $fs->get_area_files(
                $doc->get('contextid'),
                'mod_booking',
                $filearea,
                $optionid,
                'filename',
                false
            );

            foreach ($files as $file) {
                $doc->add_stored_file($file);
            }
        }
    }

    /**
     * Load the record of a booking option including the course of its instance.
     *
     * @param int $optionid
     * @return stdClass|false
     */
    protected function get_option_record(int $optionid) {
        global $DB;

        if (!isset($this->optionrecords[$optionid])) {
            $sql = "SELECT bo.*, b.course AS instancecourseid
                      FROM {booking_options} bo
                      JOIN {booking} b ON b.id = bo.bookingid
                     WHERE bo.id = :optionid";

            $this->optionrecords[$optionid] = $DB->get_record_sql($sql, ['optionid' => $optionid], IGNORE_MISSING);
        }

        return $this->optionrecords[$optionid];
    }
}
