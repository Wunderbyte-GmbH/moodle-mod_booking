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
 * Optiondate class. Provides all the functionality linked to optiondates.
 *
 * @package mod_booking
 * @copyright 2023 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option;

use dml_exception;
use local_entities\entitiesrelation_handler;
use mod_booking\customfield\optiondate_cfields;
use mod_booking\singleton_service;
use stdClass;

/**
 * Class to handle optiondate. Provides all the functionality linked to optiondates.
 *
 * @package mod_booking
 * @copyright 2023 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondate {

    /** @var array instances */
    public static array $instances = [];

    /** @var ?int id */
    public ?int $id = null;

    /** @var int bookingid */
    public int $bookingid;

    /** @var int optionid */
    public int $optionid;

    /** @var int eventid */
    public int $eventid = 0;

    /** @var int coursestarttime */
    public int $coursestarttime;

    /** @var int courseendtime */
    public int $courseendtime;

    /** @var int daystonotify */
    public int $daystonotify = 0;

    /** @var int sent */
    public int $sent = 0;

    /** @var string reason */
    public string $reason = '';

    /** @var int reviewed */
    public int $reviewed = 0;

    /**
     * Construct optiondate class
     *
     * @param int $id
     * @param int $bookingid
     * @param int $optionid
     * @param int $eventid
     * @param int $coursestarttime
     * @param int $courseendtime
     * @param int $daystonotify
     * @param int $sent
     * @param string $reason
     * @param int $reviewed
     *
     */
    public function __construct(
        $id,
        $bookingid,
        $optionid,
        $eventid,
        $coursestarttime,
        $courseendtime,
        $daystonotify,
        $sent,
        $reason,
        $reviewed
        ) {
        $this->id = $id;
        $this->optionid = $optionid;
        $this->coursestarttime = $coursestarttime;
        $this->courseendtime = $courseendtime;
        $this->daystonotify = $daystonotify;
        $this->bookingid = $bookingid;
        $this->eventid = $eventid;
        $this->sent = $sent;
        $this->reason = $reason;
        $this->reviewed = $reviewed;
    }

    /**
     * Get all the optiondates from one bookting option as classes.
     * @param int $optionid
     * @return mixed
     */
    public static function getoptiondates(int $optionid) {

        global $DB;

        $records = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);
        $returnarray = [];

        foreach ($records as $record) {
            $returnarray[] = new self(...$record);
        }
        return $returnarray;
    }

    /**
     * Save a specific optiondate by providing all necessary values.
     * The instantiated optiondate class is returned.
     * Also saves entities, if there are any.
     *
     * @param int $id
     * @param int $optionid
     * @param int $coursestarttime
     * @param int $courseendtime
     * @param int $daystonotify
     * @param int $eventid
     * @param int $sent
     * @param string $reason
     * @param int $reviewed
     * @param int $entityid
     * @param array $customfields
     *
     * @return optiondate
     * @throws dml_exception
     */
    public static function save(
        int $id = 0,
        int $optionid = 0,
        int $coursestarttime = 0,
        int $courseendtime = 0,
        int $daystonotify = 0,
        int $eventid = 0,
        int $sent = 0,
        string $reason = '',
        int $reviewed = 0,
        int $entityid = 0,
        array $customfields = []):optiondate {

        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $data = [
            'optionid' => $optionid,
            'bookingid' => $settings->bookingid,
            'coursestarttime' => $coursestarttime,
            'courseendtime' => $courseendtime,
            'daystonotify' => $daystonotify,
            'eventid' => $eventid,
            'sent' => $sent,
            'reason' => $reason,
            'reviewed' => $reviewed,
        ];

        // Before we insert a record, we want to know if we can also update.
        if (!empty($id)) {
            $data = array_merge(['id' => $id], $data);

            // Now we check for the old record.
            if ($oldrecord = $DB->get_record('booking_optiondates', ['id' => $id])) {

                $newdata = $data;
                $newdata['optiondateid'] = $id;
                $oldrecord->optiondateid = $id;

                // Now we compare the old record and the new record.
                if (!self::compare_optiondates((array)$oldrecord, $newdata, 1)) {

                    // We found a difference to the old record, so we need to update it.
                    $DB->update_record('booking_optiondates', $newdata);
                }
            } else {
                // If we don't find the record, we insert it.
                unset($data['id']);
                $id = $DB->insert_record('booking_optiondates', $data);
            }
        } else {
            $id = $DB->insert_record('booking_optiondates', $data);
        }

        $oldrecord = empty($oldrecord->optiondateid) ? new stdClass() : $oldrecord;
        // Now we compare the old record and the new record for entites.
        $newdata['entityid'] = $entityid;
        $newdata['entityarea'] = 'optiondate';
        if (!self::compare_optiondates((array)$oldrecord, $newdata, 2)) {

            // We need to save entities relation.
            if (class_exists('local_entities\entitiesrelation_handler')) {
                $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
                $erhandler->save_entity_relation($id, $entityid);
            }
        }

        $newdata['customfields'] = $customfields;
        // Last we compare the cfields to see if we need to update them.
        if (!self::compare_optiondates((array)$oldrecord, $newdata, 3)) {
            optiondate_cfields::save_fields($settings->id, $id, $customfields);
        }

        $data = array_merge(['id' => $id], $data);

        // Splat opreaton does not work with associative arrays in php < 8.
        if (PHP_MAJOR_VERSION < 8) {
            $data = array_values($data);
        }

        return new self(...$data);
    }

    /**
     * Function returns true, if they are the same, false if not.
     * @param array $oldoptiondate
     * @param array $newoptiondate
     * @param int $mode // Mode 0 is all the fields, 1 is only optiondates, 2 is only entities, 3 is only cfields.
     * @return bool
     */
    public static function compare_optiondates(array $oldoptiondate, array $newoptiondate, int $mode = 0):bool {

        if ($mode <= 1) {
            if (($oldoptiondate['optiondateid'] != $newoptiondate['optiondateid'])
                || ($oldoptiondate['coursestarttime'] != $newoptiondate['coursestarttime'])
                || $oldoptiondate['courseendtime'] != $newoptiondate['courseendtime']
                || $oldoptiondate['daystonotify'] != $newoptiondate['daystonotify']) {
                // If one of the dates is not exactly the same, we need to delete the current option and add a new one.
                return false;
            }
        }

        if ($mode == 0 || $mode == 2) {
            if (class_exists('local_entities\entitiesrelation_handler') && !empty($oldoptiondate['optiondateid'])) {
                $handler = new entitiesrelation_handler('mod_booking', 'optiondate');
                if ($data = $handler->get_instance_data($oldoptiondate['optiondateid'])) {
                    $oldoptiondate['entityid'] = $data->id ?? 0;
                    $oldoptiondate['entityarea'] = $data->area ?? '';
                }
                if (!entitiesrelation_handler::compare_items($oldoptiondate, $newoptiondate)) {
                    return false;
                }
            }
        }

        if ($mode == 0 || $mode == 3) {
            if (!empty($oldoptiondate['optiondateid'])) {
                $oldoptiondate['customfields']
                    = optiondate_cfields::return_customfields_for_optiondate(
                        $oldoptiondate['optiondateid']
                    );
            }

            if (!optiondate_cfields::compare_items($oldoptiondate, $newoptiondate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete function, takes care of entities as well.
     * @param mixed $optiondateid
     * @return void
     * @throws dml_exception
     */
    public static function delete($optiondateid) {
        global $DB;

        $optionid = $DB->get_field('booking_optiondates', 'optionid', ['id' => $optiondateid]);

        // Delete course events for the optiondate.
        // Optionid and optiondateid are stored in uuid column like this: optionid-optiondateid.
        $DB->delete_records_select('event',
            "eventtype = 'course'
            AND courseid <> 0
            AND component = 'mod_booking'
            AND uuid = :pattern",
            ['pattern' => "{$optionid}-{$optiondateid}"]
        );

        $DB->delete_records('booking_optiondates', ['id' => $optiondateid]);

        // We might need to delete entities relation.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
            $erhandler->delete_relation($optiondateid);
        }

        optiondate_cfields::delete_cfields_for_optiondate($optiondateid);
    }
}
