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
use mod_booking\subbookings\subbookings_info;
use moodle_exception;
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

    /** @var string $identifier unique identifier of the booking option */
    public $identifier = null;

    /** @var string $titleprefix prefix to be shown before title of the booking option */
    public $titleprefix = null;

    /** @var string $text */
    public $text = null;

    /** @var int $maxanswers */
    public $maxanswers = null;

    /** @var int $maxoverbooking */
    public $maxoverbooking = null;

    /** @var int $minanswers */
    public $minanswers = null;

    /** @var int $bookingopeningtime */
    public $bookingopeningtime = null;

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

    /** @var int $annotation */
    public $annotation = null;

    /** @var array $sessions */
    public $sessions = [];

    /** @var array $sessioncustomfields */
    public $sessioncustomfields = [];

    /** @var array $teachers */
    public $teachers = [];

    /** @var array $teacherids */
    public $teacherids = [];

    /** @var array $customfields */
    public $customfields = [];

    /** @var string $editoptionurl */
    public $editoptionurl = null;

    /** @var string $manageresponsesurl */
    public $manageresponsesurl = null;

    /** @var string $optiondatesteachersurl */
    public $optiondatesteachersurl = null;

    /** @var string $imageurl */
    public $imageurl = null;

    /** @var array $entity for displaying enity information [id, name]*/
    public $entity = [];

    /** @var array $load_subbookings for storing subbookings  */
    public $subbookings = [];

    /** @var float $priceformulaadd */
    public $priceformulaadd = null;

    /** @var float $priceformulamultiply */
    public $priceformulamultiply = null;

    /** @var int $priceformulaoff */
    public $priceformulaoff = null;

    /** @var string $dayofweek */
    public $dayofweek = null;

    /** @var string $availability in json format */
    public $availability = null;

    /** @var int $status like 1 for cancelled */
    public $status = null;

    /**
     * Constructor for the booking option settings class.
     * The constructor can take the dbrecord stdclass which is the initial DB request for this option.
     * This permits performance increase, because we can request all the records once and then
     *
     * @param int $optionid Booking option id.
     * @param stdClass $dbrecord of bookig option.
     * @throws dml_exception
     */
    public function __construct(int $optionid, stdClass $dbrecord = null) {

        // Even if we have a record, we still get the cache...
        // Because in the cache, we have also information from other tables.
        $cache = \cache::make('mod_booking', 'bookingoptionsettings');
        if (!$cachedoption = $cache->get($optionid)) {
            $savecache = true;
        } else {
            $savecache = false;
        }

        // If there is no cache present...
        // We try to fall back on the dbrecord.
        if (!$cachedoption) {
            if (!$dbrecord) {
                $cachedoption = null;
            } else {
                $cachedoption = $dbrecord;
            }
        }

        // If we have no object to pass to set values, the function will retrieve the values from db.
        if ($data = $this->set_values($optionid, $cachedoption)) {
            // Only if we didn't pass anything to cachedoption, we set the cache now.
            if ($savecache) {
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

            // At this point, we don't now anything about any other context, so we get system.
            $context = context_system::instance();

            list($select, $from, $where, $params) = booking::get_options_filter_sql(null, 1, null, '*',
                $context, [], ['id' => $optionid]);

            $sql = "SELECT $select
                    FROM $from
                    WHERE $where";

            $dbrecord = $DB->get_record_sql($sql, $params, IGNORE_MISSING);

        }

        if ($dbrecord) {
            // Fields in DB.
            $this->id = $optionid;
            $this->bookingid = $dbrecord->bookingid;
            $this->identifier = $dbrecord->identifier;
            $this->titleprefix = $dbrecord->titleprefix;
            $this->text = $dbrecord->text;
            $this->maxanswers = $dbrecord->maxanswers;
            $this->maxoverbooking = $dbrecord->maxoverbooking;
            $this->minanswers = $dbrecord->minanswers;
            $this->bookingopeningtime = $dbrecord->bookingopeningtime;
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
            $this->annotation = $dbrecord->annotation;
            $this->dayofweek = $dbrecord->dayofweek;
            $this->availability = $dbrecord->availability;
            $this->status = $dbrecord->status;

            // Price formula: absolute value.
            if (isset($dbrecord->priceformulaadd)) {
                $this->priceformulaadd = $dbrecord->priceformulaadd;
            } else {
                $this->priceformulaadd = 0; // Default: Add 0.
            }

            // Price formula: manual factor.
            if (isset($dbrecord->priceformulamultiply)) {
                $this->priceformulamultiply = $dbrecord->priceformulamultiply;
            } else {
                $this->priceformulamultiply = 1; // Default: Multiply with 1.
            }

            // Flag if price formula is turned on or off.
            if (isset($dbrecord->priceformulaoff)) {
                $this->priceformulaoff = $dbrecord->priceformulaoff;
            } else {
                $this->priceformulaoff = 0; // Default: Turned on.
            }

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

            // If the key "manageresponsesurl" is not yet set, we need to generate it.
            if (!isset($dbrecord->manageresponsesurl)) {
                $this->generate_manageresponses_url($optionid);
                $dbrecord->manageresponsesurl = $this->manageresponsesurl;
            } else {
                $this->manageresponsesurl = $dbrecord->manageresponsesurl;
            }

            // If the key "optiondatesteachersurl" is not yet set, we need to generate it.
            if (!isset($dbrecord->optiondatesteachersurl)) {
                $this->generate_optiondatesteachers_url($optionid);
                if (isset($this->optiondatesteachersurl)) {
                    $dbrecord->optiondatesteachersurl = $this->optiondatesteachersurl;
                }
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

            // If the key "sessioncustomfields" is not yet set, we need to load from DB.
            if (!isset($dbrecord->sessioncustomfields)) {
                $this->load_sessioncustomfields_from_db($optionid);
                $dbrecord->sessioncustomfields = $this->sessioncustomfields;
            } else {
                $this->sessioncustomfields = $dbrecord->sessioncustomfields;
            }

            // If the key "teachers" is not yet set, we need to load from DB.
            if (!isset($dbrecord->teachers)) {
                $this->load_teachers_from_db();
                $dbrecord->teachers = $this->teachers;
            } else {
                $this->teachers = $dbrecord->teachers;
            }

            // If the key "teacherids" is not yet set, we need to load from DB.
            if (!isset($dbrecord->teacherids)) {
                $this->load_teacherids_from_db();
                $dbrecord->teacherids = $this->teacherids;
            } else {
                $this->teacherids = $dbrecord->teacherids;
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

            // If the key "subbookings" is not yet set, we need to load them via handler first.
            if (!isset($dbrecord->subbookings)) {
                $this->load_subbookings($optionid);
                $dbrecord->subbookings = $this->subbookings;
            } else {
                $this->subbookings = $dbrecord->subbookings;
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
     * Function to load multi-sessions customfields from DB.
     *
     * @param int $optionid
     */
    private function load_sessioncustomfields_from_db(int $optionid) {
        global $DB;
        // Multi-sessions.
        if (!$this->sessioncustomfields = $DB->get_records('booking_customfields', ['optionid' => $optionid])) {
            $this->sessioncustomfields = [];
        }
    }

    /**
     * Function to load teachers from DB.
     */
    private function load_teachers_from_db() {
        global $DB;

        $teachers = $DB->get_records_sql(
            'SELECT DISTINCT t.userid, u.firstname, u.lastname, u.email, u.institution
                    FROM {booking_teachers} t
               LEFT JOIN {user} u ON t.userid = u.id
                   WHERE t.optionid = :optionid', array('optionid' => $this->id));

        $this->teachers = $teachers;
    }

    /**
     * Function to load teacherids from DB.
     */
    private function load_teacherids_from_db() {
        global $DB;

        $teacherids = $DB->get_fieldset_select(
            'booking_teachers', 'userid', "optionid = :optionid",
            ['optionid' => $this->id]
        );

        $this->teacherids = $teacherids;
    }

    /**
     * Function to render a list of teachers.
     *
     * @param int $optionid
     */
    public function render_list_of_teachers() {
        global $PAGE;

        $output = $PAGE->get_renderer('mod_booking');
        $renderedlistofteachers = '';

        if (empty($this->teachers)) {
            $this->load_teachers_from_db();
        }

        $data = array();
        foreach ($this->teachers as $teacher) {
            $data['teachers'][] = "$teacher->firstname $teacher->lastname";
        }

        $renderedlistofteachers = $output->render_bookingoption_description_teachers($data);

        return $renderedlistofteachers;
    }

    /**
     * Function to generate the URL to edit an option.
     *
     * @param int $optionid
     */
    private function generate_editoption_url(int $optionid) {

        if (!empty($this->cmid) && !empty($optionid)) {

            /* IMPORTANT NOTICE: We CANNOT use new moodle_url here, as it is already used in the
            add_return_url function of the booking_option_settings class. */
            $this->editoptionurl = "/mod/booking/editoptions.php?id=" . $this->cmid . "&optionid=" . $optionid;
        }
    }

    /**
     * Function to generate the URL to manage responses (answers) for an option.
     *
     * @param int $optionid
     */
    private function generate_manageresponses_url(int $optionid) {

        if (!empty($this->cmid) && !empty($optionid)) {

            $manageresponsesmoodleurl = new moodle_url('/mod/booking/report.php',
                ['id' => $this->cmid, 'optionid' => $optionid]);

            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            $this->manageresponsesurl = html_entity_decode($manageresponsesmoodleurl->out());
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
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $handler = new entitiesrelation_handler('mod_booking', 'option');
            $data = $handler->get_instance_data($optionid);
        }

        if (isset($data->id) && isset($data->name)) {
            $this->entity = [
                'id' => $data->id,
                'name' => $data->name,
                'shortname' => $data->shortname
            ];
        }
    }

    private function load_subbookings(int $optionid) {
        $this->subbookings = subbookings_info::load_subbookings($optionid);
    }

    /**
     * Returns the cached settings as stClass.
     * We will always have them in cache if we have constructed an instance,
     * but just in case we also deal with an empty cache object.
     *
     * @return stdClass
     */
    public function return_settings_as_stdclass(): stdClass {

        if (empty($this->id)) {
            return null;
        }

        $cache = \cache::make('mod_booking', 'bookingoptionsettings');
        $cachedoption = $cache->get($this->id);

        if (!$cachedoption) {
            $cachedoption = $this->set_values($this->id);
        }

        return $cachedoption;
    }

    /*
        Here we define the SQL for all the fields which have to be included in the SQL for the booking option.
        This allows us to systematically build the sql to get all the relevant information.
    */


    /**
     * Function to include all the values of one given customfield to a table bo.
     * The table is joined via bo.id=cfd.instanceid.
     * To be able to filter for the same param twice, we use this structure for searchparams [[$fieldnmae => $fieldvalue]]
     *
     * @param array $searchparams
     * @return array
     */
    public static function return_sql_for_customfield(array &$filterarray = []): array {

        global $DB;

         // Find out how many customfields are there for mod_booking.

         $sql = "SELECT cff.shortname
                 FROM {customfield_field} cff
                 JOIN {customfield_category} cfc
                 ON cfc.id=cff.categoryid
                 WHERE cfc.component=:componentname";
         $params = ['componentname' => 'mod_booking'];

         $customfields = $DB->get_records_sql($sql, $params);

         $select = '';
         $from = '';
         $where = '';
         $params = [];
        // Now we have the names of the customfields. We can now run through them and add them as colums.

        $counter = 1;
        foreach ($customfields as $customfield) {
            $name = $customfield->shortname;

            $select .= "cfd$counter.value as $name ";

            // After the last instance, we don't add a comma.
            $select .= $counter >= count($customfields) ? "" : ", ";

            $from .= " LEFT JOIN
            (
                SELECT cfd.instanceid, cfd.value
                FROM {customfield_data} cfd
                JOIN {customfield_field} cff
                ON cfd.fieldid=cff.id AND cff.shortname=:cf_$name
                JOIN {customfield_category} cfc
                ON cff.categoryid=cfc.id AND cfc.component=:" . $name . "_componentname
            ) cfd$counter
            ON bo.id = cfd$counter.instanceid ";

            // Add the variables to the params array.
            $params[$name . '_componentname'] = 'mod_booking';
            $params["cf_$name"] = $name;

            foreach ($filterarray as $key => $value) {
                if ($key == $name) {
                    $where .= $DB->sql_like("s1.$name", ":$key", false);

                    // Now we have to add the values to our params array.
                    $params[$key] = $value;
                }
            }
            $counter++;
        }

        return [$select, $from, $where, $params];
    }

    /**
     * Function to match ad the teachers sql to the booking_options request.
     *
     * @param array $searchparams
     * @return array
     */
    public static function return_sql_for_teachers($searchparams = []): array {

        global $DB;

        $select = $DB->sql_group_concat('bt1.teacherobject') . ' as teacherobjects';

        // We have to create the teacher object beforehand, in order to be able to use group_concat afterwards.
        $innerselect = $DB->sql_concat_join("''", [
            "'{\"id\":'",
            "u.id",
            "', \"firstname\":\"'",
            "u.firstname",
            "'\", \"lastname\":\"'",
            "u.lastname",
            "'\", \"name\":\"'",
            "u.firstname",
            "' '",
            'u.lastname',
            "'\"}'"]);
        $where = '';
        $params = [];

        $from = 'LEFT JOIN
        (
            SELECT bt.optionid, ' . $innerselect . ' as teacherobject
            FROM {booking_teachers} bt
            JOIN {user} u
            ON bt.userid = u.id
        ) bt1
        ON bt1.optionid = bo.id';

        // As this is a complete subrequest, we have to add the "where" to the outer table, where it is already rendered.
        $counter = 0;
        foreach ($searchparams as $searchparam) {

            if (!$key = key($searchparam)) {
                throw new moodle_exception('wrongstructureofsearchparams', 'mod_booking');
            }
            $value = $searchparam[$key];

            // Only add Or if we are not in the first line.
            $where .= $counter > 0 ? ' OR ' : ' AND (';

            $value = "%\"$key\"\:%$value%";

            // Make sure we never use the param more than once.
            if (isset($params[$key])) {
                $key = $key . $counter;
            }

            $where .= $DB->sql_like('s1.teacherobjects', ":$key", false);

            // Now we have to add the values to our params array.
            $params[$key] = $value;
            $counter++;
        }
        // If we ran through the loop at least once, we close it again here.
        $where .= $counter > 0 ? ') ' : '';

        return [$select, $from, $where, $params];
    }

    public static function return_sql_for_imagefiles($searchparams = []): array {

        global $DB;

        $select = ' f.filename ';

        $where = '';
        $params = ['componentname3' => 'mod_booking',
            'bookingoptionimage' => 'bookingoptionimage'];

        $from = " LEFT JOIN {files} f
            ON f.itemid=bo.id and f.component=:componentname3
            AND f.filearea=:bookingoptionimage
            AND f.mimetype LIKE 'image%'";

        // As this is a complete subrequest, we have to add the "where" to the outer table, where it is already rendered.
        $counter = 0;
        foreach ($searchparams as $searchparam) {

            if (!$key = key($searchparam)) {
                throw new moodle_exception('wrongstructureofsearchparams', 'mod_booking');
            }
            $value = $searchparam[$key];

            // Only add Or if we are not in the first line.
            $where .= $counter > 0 ? ' OR ' : ' AND (';

            $value = "%$value%";

            // Make sure we never use the param more than once.
            if (isset($params[$key])) {
                $key = $key . $counter;
            }

            $where .= $DB->sql_like('s1.filename', ":$key", false);

            // Now we have to add the values to our params array.
            $params[$key] = $value;
            $counter++;
        }
        // If we ran through the loop at least once, we close it again here.
        $where .= $counter > 0 ? ') ' : '';

        return [$select, $from, $where, $params];
    }

    /**
     * Helper function to get the full title of a booking option,
     * including the titleprefix, e.g. "101 - Beginner's course".
     * @return string the full title including prefix
     */
    public function get_title_with_prefix(): string {
        $title = '';
        if (!empty($this->titleprefix)) {
            $title .= $this->titleprefix . ' - ';
        }
        $title .= $this->text;
        return $title;
    }
}
