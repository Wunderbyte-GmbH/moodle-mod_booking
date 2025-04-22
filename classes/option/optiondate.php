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
use context_module;
use local_entities\entitiesrelation_handler;
use mod_booking\calendar;
use mod_booking\customfield\optiondate_cfields;
use mod_booking\event\bookingoptiondate_created;
use mod_booking\singleton_service;
use mod_booking\teachers_handler;
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
        array $customfields = []
    ): optiondate {

        global $DB, $USER;

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
        $insertnew = false;
        if (!empty($id)) {
            $data = array_merge(['id' => $id], $data);

            // Now we check for the old record.
            if ($oldrecord = $DB->get_record('booking_optiondates', ['id' => $id])) {
                $newdata = $data;
                $newdata['optiondateid'] = $id;
                $oldrecord->optiondateid = $id;
                $newdata['eventid'] = !empty($data['eventid']) ? $data['eventid'] : $oldrecord->eventid;

                // Now we compare the old record and the new record.
                if (!self::compare_optiondates((array)$oldrecord, $newdata, 1)) {
                    // We found a difference to the old record, so we need to update it.
                    $DB->update_record('booking_optiondates', $newdata);
                }
            } else {
                // If we don't find the record, we insert it.
                unset($data['id']);
                $insertnew = true;
            }
        } else {
            $insertnew = true;
        }

        if ($insertnew) {
            $id = $DB->insert_record('booking_optiondates', $data);

            // Add teachers of the booking option to newly created optiondate.
            teachers_handler::subscribe_existing_teachers_to_new_optiondate($id);

            // When we create a template, we may not have a cmid.

            if (!empty($settings->cmid) && !empty($optionid)) {
                // We trigger the event, where we take care of events in calendar etc. First we get the context.
                $event = bookingoptiondate_created::create([
                    'context' => context_module::instance($settings->cmid),
                    'objectid' => $id,
                    'userid' => $USER->id,
                    'other' => ['optionid' => $optionid],
                ]);
                $event->trigger();

                // Also create new user events (user calendar entries) for all booked users.
                $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
                $users = $option->get_all_users();
                foreach ($users as $user) {
                    new calendar($settings->cmid, $optionid, $user->id, calendar::MOD_BOOKING_TYPEOPTIONDATE, $id, 1);
                }
            }

            if (class_exists('local_entities\entitiesrelation_handler')) {
                if (empty($entityid)) {
                    // If a new optiondate is inserted and we have no entityid set...
                    // ...then we use the entity of the parent option as default.
                    $erhandleroption = new entitiesrelation_handler('mod_booking', 'option');
                    $entityid = $erhandleroption->get_entityid_by_instanceid($optionid);
                }
                $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
                $erhandler->save_entity_relation($id, $entityid);
            }
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
    public static function compare_optiondates(array $oldoptiondate, array $newoptiondate, int $mode = 0): bool {

        if ($mode <= 1) {
            if (
                ($oldoptiondate['optiondateid'] != $newoptiondate['optiondateid'])
                || ($oldoptiondate['coursestarttime'] != $newoptiondate['coursestarttime'])
                || $oldoptiondate['courseendtime'] != $newoptiondate['courseendtime']
            ) {
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

        if (!$optiondate = $DB->get_record('booking_optiondates', ['id' => $optiondateid])) {
            return;
        }
        $optionid = $optiondate->optionid;

        // Delete calendar course event for the optiondate.
        if ($optiondate->eventid !== null && $optiondate->eventid !== 0) {
            $DB->delete_records('event', ['id' => $optiondate->eventid]);
        } else {
            // If eventid is missing, we can still try another way of deleting.
            // Optionid and optiondateid are stored in uuid column like this: optionid-optiondateid.
            $DB->delete_records_select(
                'event',
                "eventtype = 'course'
                AND courseid <> 0
                AND component = 'mod_booking'
                AND uuid = :pattern",
                ['pattern' => "{$optionid}-{$optiondateid}"]
            );
        }

        // Besides the calendar course event, also clean all associated user events.
        $usereventrecords = $DB->get_records('booking_userevents', ['optiondateid' => $optiondateid]);
        if (!empty($usereventrecords)) {
            foreach ($usereventrecords as $uerecord) {
                $DB->delete_records('event', ['id' => $uerecord->eventid]);
                $DB->delete_records('booking_userevents', ['id' => $uerecord->id]);
            }
        }

        // We also need to delete the associated records in booking_optiondates_teachers.
        teachers_handler::remove_teachers_from_deleted_optiondate($optiondateid);

        // We might need to delete entities relation.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
            $erhandler->delete_relation($optiondateid);
        }

        optiondate_cfields::delete_cfields_for_optiondate($optiondateid);

        // At the very end, we delete the optiondate itself.
        $DB->delete_records('booking_optiondates', ['id' => $optiondateid]);
    }
}
