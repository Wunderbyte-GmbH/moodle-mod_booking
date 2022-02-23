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

namespace mod_booking;

use mod_booking\customfield\booking_handler;
use stdClass;

/**
 * Settings class for booking option instances.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option_settings {

    /** @var int $id The ID of the booking option. */
    public $id = null;

    /** @var int $bookingid */
    public $bookingid = null;

    /** @var string $text */
    public $text = null;

    /** @var int $maxanswers */
    public $maxanswers = null;

    /** @var int $maxoverbooking */
    public $maxoverbooking = null;

    /** @var int $bookingclosingtime */
    public $bookingclosingtime = null;

    /** @var int $courseid */
    public $courseid = null;

    /** @var int $coursestarttime */
    public $coursestarttime = null;

    /** @var int $courseendtime */
    public $courseendtime = null;

    /** @var int $enrolmentstatus */
    public $enrolmentstatus = null;

    /** @var string $description */
    public $description = null;

    /** @var int $descriptionformat */
    public $descriptionformat = null;

    /** @var int $limitanswers */
    public $limitanswers = null;

    /** @var int $timemodified */
    public $timemodified = null;

    /** @var int $addtocalendar */
    public $addtocalendar = null;

    /** @var int $calendarid */
    public $calendarid = null;

    /** @var string $pollurl */
    public $pollurl = null;

    /** @var int $groupid */
    public $groupid = null;

    /** @var int $sent */
    public $sent = null;

    /** @var string $location */
    public $location = null;

    /** @var string $institution */
    public $institution = null;

    /** @var string $address */
    public $address = null;

    /** @var string $pollurlteachers */
    public $pollurlteachers = null;

    /** @var int $howmanyusers */
    public $howmanyusers = null;

    /** @var int $pollsend */
    public $pollsend = null;

    /** @var int $removeafterminutes */
    public $removeafterminutes = null;

    /** @var string $notificationtext */
    public $notificationtext = null;

    /** @var int $notificationtextformat */
    public $notificationtextformat = null;

    /** @var int $disablebookingusers */
    public $disablebookingusers = null;

    /** @var int $sent2 */
    public $sent2 = null;

    /** @var int $sentteachers */
    public $sentteachers = null;

    /** @var string $beforebookedtext */
    public $beforebookedtext = null;

    /** @var string $beforecompletedtext */
    public $beforecompletedtext = null;

    /** @var string $aftercompletedtext */
    public $aftercompletedtext = null;

    /** @var string $shorturl */
    public $shorturl = null;

    /** @var int $duration */
    public $duration = null;

    /** @var int $parentid */
    public $parentid = null;

    /** @var array $sessions */
    public $sessions = [];

    /** @var array $teachers */
    public $teachers = [];

    /** @var array $customfields */
    public $customfields = [];

    /**
     * Constructor for the booking option settings class.
     *
     * @param int $optionid Booking option id.
     * @throws dml_exception
     */
    public function __construct(int $optionid) {

        $cache = \cache::make('mod_booking', 'bookingoptionsettings');
        $cachedoption = $cache->get($optionid);

        if (!$cachedoption) {
            $cachedoption = null;
        }

        // If we have no object to pass to set values, the function will retrieve the values from db.
        if ($data = $this->set_values($optionid, $cachedoption)) {
            // Only if we didn't pass anything to cachedoption, we set the cache now.
            if (!$cachedoption) {
                $cache->set($optionid, $data);
            }
        }
    }

    /**
     * Load values of booking_option from db, should rarely be necessary.
     *
     * @param integer $optionid
     * @return stdClass|null
     */
    private function set_values(int $optionid, object $dbrecord = null) {
        global $DB;

        // If we don't get the cached object, we have to fetch it here.
        if ($dbrecord === null) {
            $dbrecord = $DB->get_record("booking_options", array("id" => $optionid));

        }

        if ($dbrecord) {

            // Fields in DB.
            $this->id = $optionid;
            $this->bookingid = $dbrecord->bookingid;
            $this->text = $dbrecord->text;
            $this->maxanswers = $dbrecord->maxanswers;
            $this->maxoverbooking = $dbrecord->maxoverbooking;
            $this->bookingclosingtime = $dbrecord->bookingclosingtime;
            $this->courseid = $dbrecord->courseid;
            $this->coursestarttime = $dbrecord->coursestarttime;
            $this->courseendtime = $dbrecord->courseendtime;
            $this->enrolmentstatus = $dbrecord->enrolmentstatus;
            $this->description = $dbrecord->description;
            $this->descriptionformat = $dbrecord->descriptionformat;
            $this->limitanswers = $dbrecord->limitanswers;
            $this->timemodified = $dbrecord->timemodified;
            $this->addtocalendar = $dbrecord->addtocalendar;
            $this->calendarid = $dbrecord->calendarid;
            $this->pollurl = $dbrecord->pollurl;
            $this->groupid = $dbrecord->groupid;
            $this->sent = $dbrecord->sent;
            $this->location = $dbrecord->location;
            $this->institution = $dbrecord->institution;
            $this->address = $dbrecord->address;
            $this->pollurlteachers = $dbrecord->pollurlteachers;
            $this->howmanyusers = $dbrecord->howmanyusers;
            $this->pollsend = $dbrecord->pollsend;
            $this->removeafterminutes = $dbrecord->removeafterminutes;
            $this->notificationtext = $dbrecord->notificationtext;
            $this->notificationtextformat = $dbrecord->notificationtextformat;
            $this->disablebookingusers = $dbrecord->disablebookingusers;
            $this->sent2 = $dbrecord->sent2;
            $this->sentteachers = $dbrecord->sentteachers;
            $this->beforebookedtext = $dbrecord->beforebookedtext;
            $this->beforecompletedtext = $dbrecord->beforecompletedtext;
            $this->aftercompletedtext = $dbrecord->aftercompletedtext;
            $this->shorturl = $dbrecord->shorturl;
            $this->duration = $dbrecord->duration;
            $this->parentid = $dbrecord->parentid;

            // If the key "sessions" is not yet set, we need to load from DB.
            if (!isset($dbrecord->sessions)) {
                $this->load_sessions_from_db($optionid);
                $dbrecord->sessions = $this->sessions;
            } else {
                $this->sessions = $dbrecord->sessions;
            }

            // If the key "teachers" is not yet set, we need to load from DB.
            if (!isset($dbrecord->teachers)) {
                $this->load_teachers_from_db($optionid);
                $dbrecord->teachers = $this->teachers;
            } else {
                $this->teachers = $dbrecord->teachers;
            }

            // If the key "customfields" is not yet set, we need to load them via handler first.
            if (!isset($dbrecord->customfields)) {
                $this->load_customfields($optionid);
                $dbrecord->customfields = $this->customfields;
            } else {
                $this->customfields = $dbrecord->customfields;
            }

            return $dbrecord;
        } else {
            debugging('Could not create option settings class for optionid: ' . $optionid);
            return null;
        }
    }

    // Function to load Multisessions from DB.
    private function load_sessions_from_db($optionid) {
        global $DB;
        // Multi-sessions.
        if (!$this->sessions = $DB->get_records_sql(
            "SELECT id, id optiondateid, coursestarttime, courseendtime
            FROM {booking_optiondates}
            WHERE optionid = ?
            ORDER BY coursestarttime ASC", array($optionid))) {

            // If there are no multisessions, but we still have the option's ...
            // ... coursestarttime and courseendtime, then store them as if they were a session.
            if (!empty($this->coursestarttime) && !empty($this->courseendtime)) {
                $singlesession = new stdClass;
                $singlesession->id = 0;
                $singlesession->coursestarttime = $this->coursestarttime;
                $singlesession->courseendtime = $this->courseendtime;
                $this->sessions[] = $singlesession;
            } else {
                // Else we have no sessions.
                $this->sessions = [];
            }
        }
    }

    // Function to load Teachers from DB.
    private function load_teachers_from_db($optionid) {
        global $DB, $CFG;

        require_once($CFG->dirroot . "/user/lib.php");

        $teachers = $DB->get_records("booking_teachers",
                array("optionid" => $optionid));

        foreach ($teachers as $teacher) {
            $users = user_get_users_by_id([$teacher->userid]);
            $user = reset($users);
            $teacher->firstname = $user->firstname;
            $teacher->lastname = $user->lastname;
        }

        $this->teachers = $teachers;
    }


    private function load_customfields($optionid) {
        $handler = booking_handler::create();

        $datas = $handler->get_instance_data($optionid);

        foreach ($datas as $data) {

            $getfield = $data->get_field();
            $shortname = $getfield->get('shortname');

            $value = $data->get_value();

            if (!empty($value)) {
                $this->customfields[$shortname] = $value;
            }
        }
    }

    /**
     * Returns the cached settings as stClass.
     * We will always have them in cache if we have constructed an instance, but just in case...
     * ... we also deal with an empty cache object.
     *
     * @return stdClass
     */
    public function return_settings_as_stdclass(): stdClass {

        $cache = \cache::make('mod_booking', 'bookingoptionsettings');
        $cachedoption = $cache->get($this->id);

        if (!$cachedoption) {
            $cachedoption = $this->set_values($this->id);
        }

        return $cachedoption;
    }
}
