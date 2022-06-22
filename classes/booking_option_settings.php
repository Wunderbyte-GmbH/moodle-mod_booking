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

use context_module;
use context_system;
use local_entities\entitiesrelation_handler;
use mod_booking\customfield\booking_handler;
use stdClass;
use moodle_url;

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

    /** @var int $cmid */
    public $cmid = null;

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

    /** @var int $semesterid */
    public $semesterid = null;

    /** @var string $dayofweektime */
    public $dayofweektime = null;

    /** @var int $invisible */
    public $invisible = null;

    /** @var array $sessions */
    public $sessions = [];

    /** @var array $teachers */
    public $teachers = [];

    /** @var array $customfields */
    public $customfields = [];

    /** @var string $editoptionurl */
    public $editoptionurl = null;

    /** @var string $editteachersurl */
    public $editteachersurl = null;

    /** @var array $entity for displaying enity information [id, name]*/
    public $entity = [];

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
     * Set all the values from DB, if necessary.
     * If we have passed on the cached object, we use this one.
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
            $this->semesterid = $dbrecord->semesterid;
            $this->dayofweektime = $dbrecord->dayofweektime;
            $this->invisible = $dbrecord->invisible;

            // If the course module id (cmid) is not yet set, we load it. //TODO: bookingid 0 bei option templates berücksichtigen!!
            if (!isset($dbrecord->cmid)) {
                $cm = get_coursemodule_from_instance('booking', $dbrecord->bookingid);
                if (!$cm) {
                    // Set cmid to 0 for option templates as they are set globally (not only for one instance).
                    $this->cmid = 0;
                    $dbrecord->cmid = 0;
                } else {
                    $this->cmid = $cm->id;
                    $dbrecord->cmid = $cm->id;
                }
            } else {
                $this->cmid = $dbrecord->cmid;
            }

            // If the key "editoptionurl" is not yet set, we need to generate it.
            if (!isset($dbrecord->editoptionurl)) {
                $this->generate_editoption_url($optionid);
                $dbrecord->editoptionurl = $this->editoptionurl;
            } else {
                $this->editoptionurl = $dbrecord->editoptionurl;
            }

            // If the key "editteachersurl" is not yet set, we need to generate it.
            if (!isset($dbrecord->editteachersurl)) {
                $this->generate_editteachers_url($optionid);
                $dbrecord->editteachersurl = $this->editteachersurl;
            } else {
                $this->editteachersurl = $dbrecord->editteachersurl;
            }

            // If the key "optiondatesteachersurl" is not yet set, we need to generate it.
            if (!isset($dbrecord->optiondatesteachersurl)) {
                $this->generate_optiondatesteachers_url($optionid);
                $dbrecord->optiondatesteachersurl = $this->optiondatesteachersurl;
            } else {
                $this->optiondatesteachersurl = $dbrecord->optiondatesteachersurl;
            }

            // If the key "imageurl" is not yet set, we need to load from DB.
            if (!isset($dbrecord->imageurl)) {
                $this->load_imageurl_from_db($optionid, $dbrecord->bookingid);
                if (!empty($this->imageurl)) {
                    $dbrecord->imageurl = $this->imageurl;
                }
            } else {
                $this->imageurl = $dbrecord->imageurl;
            }

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

            // If the key "entity" is not yet set, we need to load them via handler first.
            if (!isset($dbrecord->entity)) {
                $this->load_entity($optionid);
                $dbrecord->entity = $this->entity;
            } else {
                $this->entity = $dbrecord->entity;
            }

            return $dbrecord;
        } else {
            debugging('Could not create option settings class for optionid: ' . $optionid);
            return null;
        }
    }

    /**
     * Function to load multi-sessions from DB.
     *
     * @param int $optionid
     */
    private function load_sessions_from_db(int $optionid) {
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

    /**
     * Function to load teachers from DB.
     *
     * @param int $optionid
     */
    private function load_teachers_from_db(int $optionid) {
        global $DB;

        $teachers = $DB->get_records_sql(
            'SELECT DISTINCT t.userid, u.firstname, u.lastname, u.email, u.institution
                    FROM {booking_teachers} t
               LEFT JOIN {user} u ON t.userid = u.id
                   WHERE t.optionid = :optionid', array('optionid' => $optionid));

        $this->teachers = $teachers;
    }

    /**
     * Function to generate the URL to edit an option.
     *
     * @param int $optionid
     */
    private function generate_editoption_url(int $optionid) {

        if (!empty($this->cmid) && !empty($optionid)) {
            $editoptionmoodleurl = new moodle_url('/mod/booking/editoptions.php',
                ['id' => $this->cmid, 'optionid' => $optionid]);

            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            $this->editoptionurl = html_entity_decode($editoptionmoodleurl->out());
        }
    }

    /**
     * Function to generate the URL to edit teachers for an option.
     *
     * @param int $optionid
     */
    private function generate_editteachers_url(int $optionid) {

        if (!empty($this->cmid) && !empty($optionid)) {
            $editteachersmoodleurl = new moodle_url('/mod/booking/teachers.php',
                ['id' => $this->cmid, 'optionid' => $optionid]);

            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            $this->editteachersurl = html_entity_decode($editteachersmoodleurl->out());
        }
    }

    /**
     * Function to generate the optiondates-teachers-report URL.
     * @param int $cmid course module id
     * @param int $optionid option id
     */
    private function generate_optiondatesteachers_url(int $optionid) {

        if (!empty($this->cmid) && !empty($optionid)) {
            $optiondatesteachersmoodleurl = new moodle_url('/mod/booking/optiondates_teachers_report.php',
                ['id' => $this->cmid, 'optionid' => $optionid]);

            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            $this->optiondatesteachersurl = html_entity_decode($optiondatesteachersmoodleurl->out());
        }
    }

    /**
     * Function to load the URL of the option's image from the DB.
     *
     * @param int $optionid
     * @param int $bookingid
     */
    private function load_imageurl_from_db(int $optionid, int $bookingid) {
        global $DB, $CFG;

        $this->imageurl = null;

        $imgfile = null;
        // Let's check if an image has been uploaded for the option.
        if ($imgfile = $DB->get_record_sql("SELECT id, contextid, filepath, filename
                                 FROM {files}
                                 WHERE component = 'mod_booking'
                                 AND itemid = :optionid
                                 AND filearea = 'bookingoptionimage'
                                 AND filesize > 0
                                 AND source is not null", ['optionid' => $optionid])) {

            // If an image has been uploaded for the option, let's create the according URL.
            $this->imageurl = $CFG->wwwroot . "/pluginfile.php/" . $imgfile->contextid .
                "/mod_booking/bookingoptionimage/" . $optionid . $imgfile->filepath . $imgfile->filename;

            return;

        } else {
            // Fix: Option templates have bookingid 0 as they are global and not instance-specific.
            if (empty($bookingid)) {
                return;
            }

            // Image fallback (general images to match with custom fields).
            // First, check if there's a customfield to match images with.
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
            $customfieldid = $bookingsettings->bookingimagescustomfield;

            if (!empty($customfieldid)) {
                $customfieldvalue = $DB->get_field('customfield_data', 'value',
                    ['fieldid' => $customfieldid, 'instanceid' => $optionid]);

                if (!empty($customfieldvalue)) {
                    $customfieldvalue = strtolower($customfieldvalue);

                    if (!$imgfiles = $DB->get_records_sql("SELECT id, contextid, filepath, filename
                                 FROM {files}
                                 WHERE component = 'mod_booking'
                                 AND itemid = :bookingid
                                 AND filearea = 'bookingimages'
                                 AND LOWER(filename) LIKE :customfieldvaluewithextension
                                 AND filesize > 0
                                 AND source is not null", ['bookingid' => $bookingid,
                                    'customfieldvaluewithextension' => "$customfieldvalue.%"])) {
                                        return;
                    }

                    // There might be more than one image, so we only use the first one.
                    $imgfile = reset($imgfiles);

                    if (!empty($imgfile)) {
                        // If a fallback image has been found for the customfield value, then use this one.
                        $this->imageurl = $CFG->wwwroot . "/pluginfile.php/" . $imgfile->contextid .
                            "/mod_booking/bookingimages/" . $bookingid . $imgfile->filepath . $imgfile->filename;

                        return;
                    }
                }
            }

            // If still no image could be found, we check if there is a default image.
            $imgfile = $DB->get_record_sql("SELECT id, contextid, filepath, filename
            FROM {files}
            WHERE component = 'mod_booking'
            AND itemid = :bookingid
            AND filearea = 'bookingimages'
            AND LOWER(filename) LIKE 'default.%'
            AND filesize > 0
            AND source is not null", ['bookingid' => $bookingid]);

            if (!empty($imgfile)) {
                // If a fallback image has been found for the customfield value, then use this one.
                $this->imageurl = $CFG->wwwroot . "/pluginfile.php/" . $imgfile->contextid .
                    "/mod_booking/bookingimages/" . $bookingid . $imgfile->filepath . $imgfile->filename;

                return;
            }

            // Set to null if no image can be found in DB...
            // ... AND if no fallback image has been uploaded to the bookingimages folder.
            $this->imageurl = null;

            return;
        }
    }

    /**
     * Load custom fields.
     *
     * @param int $optionid
     */
    private function load_customfields(int $optionid) {
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
     * Load entity array from handler
     *
     * @param int $optionid
     */
    private function load_entity(int $optionid) {
        if (class_exists('local/entitiesrelation_handler')) {
            $handler = new entitiesrelation_handler('bookingoption');
            $data = $handler->get_instance_data($optionid);
        }

        if (isset($data->id) && isset($data->name)) {
            $this->entity = ['id' => $data->id, 'name' => $data->name];
        }
    }

    /**
     * Returns the cached settings as stClass.
     * We will always have them in cache if we have constructed an instance,
     * but just in case we also deal with an empty cache object.
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
