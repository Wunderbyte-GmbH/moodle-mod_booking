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
namespace mod_booking\option;

use dml_exception;
use local_entities\entitiesrelation_handler;
use mod_booking\singleton_service;


/**
 * Optiondate class
 * Provides all the functionality linked to optiondates.
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
     * @param int $optionid
     * @param int $coursestarttime
     * @param int $courseendtime
     * @param int $daystonotify
     * @param int $eventid
     * @param int $sent
     * @param string $reason
     * @param int $reviewed
     * @return optiondate
     * @throws dml_exception
     */
    public static function save(
        int $optionid,
        int $coursestarttime,
        int $courseendtime,
        int $daystonotify = 0,
        int $eventid = 0,
        int $sent = 0,
        string $reason = '',
        int $reviewed = 0):optiondate {

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
            'reviewed' => $reviewed
        ];

        $id = $DB->insert_record('booking_optiondates', $data);

        $data = array_merge(['id' => $id], $data);

        return new self(...$data);
    }

    /**
     * Function returns true, if they are the same, false if not.
     * @param array $oldoptiondate
     * @param array $newoptiondate
     * @return bool
     */
    public static function compare_optiondates(array $oldoptiondate, array $newoptiondate):bool {

        // For the old option date, we might need the entity ids.

        if (class_exists('local_entities\entitiesrelation_handler')) {
            $handler = new entitiesrelation_handler('mod_booking', 'optiondate');
            if ($data = $handler->get_instance_data($oldoptiondate['optiondateid'])) {
                $entityid = $data->id;
                $entityarea = $data->area;
            }
        }

        if (($oldoptiondate['optiondateid'] != $newoptiondate['optiondateid'])
            || ($oldoptiondate['coursestarttime'] != $newoptiondate['coursestarttime'])
            || $oldoptiondate['courseendtime'] != $newoptiondate['courseendtime']
            || $oldoptiondate['daystonotify'] != $newoptiondate['daystonotify']
            || $entityid != $newoptiondate['entityid']
            || $entityarea != $newoptiondate['entityarea']) {
            // If one of the dates is not exactly the same, we need to delete the current option and add a new one.
            return false;
        }
        return true;
    }
}
