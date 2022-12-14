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
 * Handler for managing teachers of booking options or booking optiondates.
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Handler for managing teachers of booking options or booking optiondates.
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teachers_handler {

    /** @var int $optionid */
    public $optionid = 0;

    /** @var int $bookingid */
    public $bookingid = 0;

    /**
     * Constructor.
     * @param int $optionid
     * @param int $bookingid
     */
    public function __construct(int $optionid = 0, int $bookingid = 0) {
        $this->optionid = $optionid;
        $this->bookingid = $bookingid;
    }

    /**
     * Add form fields to be passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_to_mform(MoodleQuickForm &$mform) {
        global $PAGE;

        $bookingoptionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($this->bookingid);

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $mform->addElement('autocomplete', 'chooseperiod', get_string('chooseperiod', 'mod_booking'),
            $semestersarray, ['tags' => false]);
        // If a semesterid for the booking option was already set, use it.
        if (!empty($bookingoptionsettings->semesterid)) {
            $mform->setDefault('chooseperiod', $bookingoptionsettings->semesterid);
        } else if (!empty($bookingsettings->semesterid)) {
            // If not, use the semesterid from the booking instance.
            $mform->setDefault('chooseperiod', $bookingsettings->semesterid);
        }
        $mform->addHelpButton('chooseperiod', 'chooseperiod', 'mod_booking'); */
    }

    /**
     * Transform each optiondate and save.
     *
     * @param stdClass $fromform form data
     * @param array $optiondates array of optiondates as strings (e.g. "11646647200-1646650800")
     */
    public function save_from_form(stdClass $fromform) {
        global $DB;

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* if ($this->optionid && $this->bookingid) {

            // Get the currently saved optiondateids from DB.
            $olddates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid]);

            // Now, let's check, if they have not been removed by the dynamic form.
            foreach ($olddates as $olddate) {

                if (isset($fromform->stillexistingdates[(int) $olddate->id])) {

                    $stillexistingdatestring = $fromform->stillexistingdates[(int) $olddate->id];
                    list($starttime, $endtime) = explode('-', $stillexistingdatestring);

                    // Check if start time or end time has changed.
                    if ($olddate->coursestarttime != $starttime || $olddate->courseendtime != $endtime) {
                        // If so, we update the record accordingly.
                        $olddate->coursestarttime = (int)$starttime;
                        $olddate->courseendtime = (int)$endtime;
                        $DB->update_record('booking_optiondates', $olddate);
                    }

                } else {
                    $olddateid = (int) $olddate->id;

                    // An existing optiondate has been removed by the dynamic form, so delete it from DB.
                    $DB->delete_records('booking_optiondates', ['id' => $olddateid]);

                    // We also need to delete the associated records in booking_optiondates_teachers.
                    self::remove_teachers_from_deleted_optiondate($olddateid);

                    // We also need to delete associated custom fields.
                    self::optiondate_deletecustomfields($olddateid);

                    // We also need to delete any associated entities.
                    // If there is an associated entity, delete it too.
                    if (class_exists('local_entities\entitiesrelation_handler')) {
                        $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
                        $erhandler->delete_relation($olddateid);
                    }
                }
            }

            // It's important that this happens AFTER deleting the removed dates.
            foreach ($fromform->newoptiondates as $optiondatestring) {
                list($starttime, $endtime) = explode('-', $optiondatestring);

                $optiondate = new stdClass();
                $optiondate->bookingid = $this->bookingid;
                $optiondate->optionid = $this->optionid;
                $optiondate->eventid = 0; // TODO: We will implement this in a later release.
                $optiondate->coursestarttime = (int) $starttime;
                $optiondate->courseendtime = (int) $endtime;
                $optiondate->daystonotify = 0; // TODO: We will implement this in a later release..

                $optiondateid = $DB->insert_record('booking_optiondates', $optiondate);

                // Add teachers of the booking option to newly created optiondate.
                self::subscribe_existing_teachers_to_new_optiondate($optiondateid);

                // If a new optiondate is inserted, we add the entity of the parent option as default.
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    $erhandleroption = new entitiesrelation_handler('mod_booking', 'option');
                    $entityid = $erhandleroption->get_entityid_by_instanceid($this->optionid);
                    $erhandleroptiondate = new entitiesrelation_handler('mod_booking', 'optiondate');
                    $erhandleroptiondate->save_entity_relation($optiondateid, $entityid);
                }
            }

            // After updating, we invalidate caches.
            cache_helper::purge_by_event('setbackoptionstable');
            cache_helper::invalidate_by_event('setbackoptionsettings', [$this->optionid]);

            booking_updatestartenddate($this->optionid);
        } */
    }

    // TODO: diese Functions aus dates_handler rausnehmen und von hier aus verwenden!

    /**
     * Helper function to add a new teacher to every (currently existing)
     * optiondate of an option.
     * @param int $optionid the booking option id
     * @param int $userid the user id of the teacher
     */
    public static function subscribe_teacher_to_all_optiondates(int $optionid, int $userid) {
        global $DB;

        if (empty($optionid) || empty ($userid)) {
            throw new moodle_exception('Could not connect teacher to optiondates because of missing userid or optionid.');
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                $newentry = new stdClass;
                $newentry->optiondateid = $existingoptiondate->id;
                $newentry->userid = $userid;
                // 2. Insert the teacher into booking_optiondates_teachers for every optiondate.
                $DB->insert_record('booking_optiondates_teachers', $newentry);
            }
        }
    }

    /**
     * Helper function to add the option's teacher(s) to a newly created optiondate.
     * @param int $optiondateid the id of the newly created optiondate
     */
    public static function subscribe_existing_teachers_to_new_optiondate(int $optiondateid) {
        global $DB;

        if (empty($optiondateid)) {
            throw new moodle_exception(
                'Could not subscribe existing teacher(s) to the new optiondate because of missing optiondateid.'
            );
        }

        if ($optiondate = $DB->get_record('booking_optiondates', ['id' => $optiondateid])) {
            // Get all currently set teachers of the option.
            $teachers = $DB->get_records('booking_teachers', ['optionid' => $optiondate->optionid]);
            if (!empty($teachers)) {
                foreach ($teachers as $teacher) {
                    $newentry = new stdClass;
                    $newentry->optiondateid = $optiondate->id;
                    $newentry->userid = $teacher->userid;
                    // Insert the newly created optiondate with each teacher.
                    $DB->insert_record('booking_optiondates_teachers', $newentry);
                }
            }
        }
    }

    /**
     * Helper function to remove a teacher from every (currently existing)
     * optiondate of an option.
     * @param int $optionid the booking option id
     * @param int $userid the user id of the teacher
     */
    public static function remove_teacher_from_all_optiondates(int $optionid, int $userid) {
        global $DB;

        if (empty($optionid) || empty ($userid)) {
            throw new moodle_exception('Could not remove teacher from optiondates because of missing userid or optionid.');
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                // 2. Delete the teacher from every optiondate.
                $DB->delete_records('booking_optiondates_teachers', [
                    'optiondateid' => $existingoptiondate->id,
                    'userid' => $userid
                ]);
            }
        }
    }

    /**
     * Helper function to remove the option's teacher(s) from a deleted optiondate.
     * @param int $optiondateid the id of the deleted optiondate
     */
    public static function remove_teachers_from_deleted_optiondate(int $optiondateid) {
        global $DB;

        if (empty($optiondateid)) {
            throw new moodle_exception(
                'Could not delete teacher(s) from the deleted optiondate because of missing optiondateid.'
            );
        }

        // Delete all entries in booking_optiondates_teachers associated with the optiondate.
        $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $optiondateid]);
    }

    /**
     * Helper function to remove all entries in booking_optiondates_teachers
     * for a specific booking instance (by bookingid).
     * @param int $bookingid the id of the booking instance
     * @param int $userid (optional) teacher id - if set only entries for this teacher will be deleted
     */
    public static function delete_booking_optiondates_teachers_by_bookingid(int $bookingid, int $userid = null) {
        global $DB;

        if (empty($bookingid)) {
            throw new moodle_exception('Could not clear entries from booking_optiondates_teachers because of missing booking id.');
        }

        // Get all currently existing optiondates of the booking instance.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['bookingid' => $bookingid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                if (empty($userid)) {
                    $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $existingoptiondate->id]);
                } else {
                    $DB->delete_records('booking_optiondates_teachers', [
                        'optiondateid' => $existingoptiondate->id,
                        'userid' => $userid
                    ]);
                }
            }
        }
    }

    /**
     * Helper function to remove all entries in booking_optiondates_teachers
     * for a specific booking option (by optionid).
     * @param int $optionid the id of the booking option
     */
    public static function delete_booking_optiondates_teachers_by_optionid(int $optionid) {
        global $DB;

        if (empty($optionid)) {
            throw new moodle_exception('Could not clear entries from booking_optiondates_teachers because of missing option id.');
        }

        // Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $existingoptiondate->id]);
            }
        }
    }
}
