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
 * Search area for subbookings of booking options.
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
use mod_booking\local\search\searchconfig;
use moodle_url;
use stdClass;

/**
 * Search area for subbookings of booking options.
 *
 * Subbookings are separate results which link to the booking option they belong to. They are an
 * area of their own so that they can be switched off without losing the booking options.
 */
class subbooking extends \core_search\base_mod {
    /** @var array Cache of the subbooking records used within one request. */
    protected $subbookingrecords = [];

    /**
     * Returns the recordset of all subbookings to be indexed.
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

        $sql = "SELECT sub.*, bo.bookingid, bo.invisible, b.course AS instancecourseid
                  FROM {booking_subbooking_options} sub
                  JOIN {booking_options} bo ON bo.id = sub.optionid
                  JOIN {booking} b ON b.id = bo.bookingid
                  $contextjoin
                 WHERE sub.timemodified >= :timemodified
                   AND bo.bookingid > 0
                   $invisiblewhere
              ORDER BY sub.timemodified ASC";

        return $DB->get_recordset_sql($sql, array_merge($contextparams, ['timemodified' => $modifiedfrom]));
    }

    /**
     * Returns the document associated with one subbooking.
     *
     * @param stdClass $record record of booking_subbooking_options with instance data
     * @param array $options
     * @return document|bool
     */
    public function get_document($record, $options = []) {
        try {
            $cm = $this->get_cm('booking', $record->bookingid, $record->instancecourseid);
            $context = context_module::instance($cm->id);
        } catch (\dml_missing_record_exception $ex) {
            debugging(
                'Error retrieving mod_booking subbooking ' . $record->id . ' document: ' . $ex->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        }

        $doc = document_factory::instance($record->id, $this->componentname, $this->areaname);
        $doc->set('title', content_to_text($record->name ?? '', false));
        $doc->set('content', content_to_text($record->name ?? '', false));
        $doc->set('contextid', $context->id);
        $doc->set('courseid', $record->instancecourseid);
        $doc->set('owneruserid', manager::NO_OWNER_ID);
        $doc->set('modified', $record->timemodified);

        if (isset($options['lastindexedtime']) && $options['lastindexedtime'] < $record->timecreated) {
            $doc->set_is_new(true);
        }

        return $doc;
    }

    /**
     * Whether the current user may see this subbooking.
     *
     * A subbooking is visible exactly when its booking option is visible.
     *
     * @param int $id id of the subbooking
     * @return int
     */
    public function check_access($id) {
        if (isguestuser() || !isloggedin()) {
            return manager::ACCESS_DENIED;
        }

        $record = $this->get_subbooking_record($id);

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
     * Link to the booking option the subbooking belongs to.
     *
     * @param document $doc
     * @return moodle_url
     */
    public function get_doc_url(document $doc) {
        $record = $this->get_subbooking_record($doc->get('itemid'));
        $context = context::instance_by_id($doc->get('contextid'));

        return new moodle_url('/mod/booking/optionview.php', [
            'optionid' => empty($record) ? 0 : $record->optionid,
            'cmid' => $context->instanceid,
        ]);
    }

    /**
     * Link to the booking instance the subbooking belongs to.
     *
     * @param document $doc
     * @return moodle_url
     */
    public function get_context_url(document $doc) {
        $context = context::instance_by_id($doc->get('contextid'));

        return new moodle_url('/mod/booking/view.php', ['id' => $context->instanceid]);
    }

    /**
     * Load the record of a subbooking including the data of its option and instance.
     *
     * @param int $subbookingid
     * @return stdClass|false
     */
    protected function get_subbooking_record(int $subbookingid) {
        global $DB;

        if (!isset($this->subbookingrecords[$subbookingid])) {
            $sql = "SELECT sub.*, bo.bookingid, bo.invisible, b.course AS instancecourseid
                      FROM {booking_subbooking_options} sub
                      JOIN {booking_options} bo ON bo.id = sub.optionid
                      JOIN {booking} b ON b.id = bo.bookingid
                     WHERE sub.id = :subbookingid";

            $this->subbookingrecords[$subbookingid] = $DB->get_record_sql(
                $sql,
                ['subbookingid' => $subbookingid],
                IGNORE_MISSING
            );
        }

        return $this->subbookingrecords[$subbookingid];
    }
}
