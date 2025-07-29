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
use context_user;
use core_plugin_manager;
use html_writer;
use local_entities\entitiesrelation_handler;
use mod_booking\bo_availability\bo_subinfo;
use mod_booking\bo_availability\conditions\subbooking;
use mod_booking\booking_campaigns\campaigns_info;
use mod_booking\customfield\booking_handler;
use mod_booking\option\dates_handler;
use mod_booking\subbookings\subbookings_info;
use mod_booking\booking_campaigns\booking_campaign;
use moodle_exception;
use stdClass;
use moodle_url;
use Throwable;

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

    /**
     * Id of booking instance
     *
     * @var int
     */
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
    public $maxoverbooking = 0;

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

    /** @var int $timecreated */
    public $timecreated = null;

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

    /** @var int $timemadevisible */
    public $timemadevisible = 0;

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

    /** @var array $customfieldsfortemplates */
    public $customfieldsfortemplates = [];

    /** @var string $editoptionurl */
    public $editoptionurl = null;

    /** @var string $manageresponsesurl */
    public $manageresponsesurl = null;

    /** @var string $optiondatesteachersurl */
    public $optiondatesteachersurl = null;

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

    /** @var string $imageurl url */
    public $imageurl = '';

    /** @var array $responsiblecontact array of userid(s) of the responsible contact person(s) */
    public $responsiblecontact = [];

    /** @var array $responsiblecontactuser user object for the responsible contact person */
    public $responsiblecontactuser = [];

    /** @var int $credits */
    public $credits = null;

    /** @var int $sortorder */
    public $sortorder = null;

    /** @var array $electivecombinations */
    public $electivecombinations = null;

    /** @var string $json Is used to store non performance critical data like booking actions */
    public $json = null;

    /** @var stdClass $jsonobject Is used to store non performance critical data like booking actions */
    public $jsonobject = null;

    /** @var array $boactions */
    public $boactions = null;

    /** @var stdClass $params */
    public $params = null;

    /** @var bool $campaignisset flag to apply campaigns only once */
    public $campaignisset = null;

    /** @var array $campaigns An array of campaign classes. */
    public $campaigns = [];

    /** @var string $costcenter Cost center which is stored in a booking option custom field. */
    public $costcenter = ''; // Default is an empty string.

    /** @var int $canceluntil each booking option can override the canceluntil date with its own date */
    public $canceluntil = 0;

    /** @var int $waitforconfirmation Only books to waitinglist and manually confirm every booking. */
    public $waitforconfirmation = 0;

    /** @var int $confirmationonnotification Only books to waitinglist and manually confirm every booking. */
    public $confirmationonnotification = 0;

    /** @var int $confirmationonnotificationoneatatime Only books to waitinglist and manually confirm every booking. */
    public $confirmationonnotificationoneatatime = 0;

    /** @var int $useprice flag that indicates if we use price or not */
    public $useprice = 0;

    /** @var int $selflearningcourse flag marks courses with duration but no optiondates */
    public $selflearningcourse = 0;

    /** @var int $sqlfilter defines if an element should be hidden via sql filter. hidden > 0 */
    public $sqlfilter = 0;

    /** @var array $attachedfiles The links on the attached files */
    public $attachedfiles = [];

    /** @var string $competencies The links on the attached files */
    public $competencies = '';

    /** @var array $subpluginssettings Collects Data that Subplugins need in the Settings singleton*/
    public $subpluginssettings = [];

    /**
     * Constructor for the booking option settings class.
     * The constructor can take the dbrecord stdclass which is the initial DB request for this option.
     * This permits performance increase, because we can request all the records once and then
     *
     * @param int $optionid Booking option id.
     * @param stdClass|null $dbrecord of bookig option.
     * @throws dml_exception
     */
    public function __construct(int $optionid, ?stdClass $dbrecord = null) {

        $savecache = false;
        $cachedoption = false;
        $cache = \cache::make('mod_booking', 'bookingoptionsettings');
        if (!get_config('booking', 'cacheturnoffforbookingsettings')) {
            // Even if we have a record, we still get the cache...
            // Because in the cache, we have also information from other tables.
            if (
                !$cachedoption = $cache->get($optionid)
            ) {
                $savecache = true;
            }
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
     * Helper function to get all properties of booking option settings.
     * @return array
     */
    public function get_booking_option_properties(): array {
        return array_keys(get_object_vars($this));
    }

    /**
     * Set all the values from DB, if necessary.
     * If we have passed on the cached object, we use this one.
     *
     * @param int $optionid
     * @param object|null $dbrecord
     * @return stdClass|null
     */
    private function set_values(int $optionid, ?object $dbrecord = null) {
        global $DB;

        if (empty($optionid)) {
            return;
        }

        // If we don't get the cached object, we have to fetch it here.
        if ($dbrecord === null) {
            $params['id'] = $optionid;
            $sql = "SELECT cm.id
                    FROM {booking_options} bo
                    JOIN {course_modules} cm ON bo.bookingid=cm.instance
                    JOIN {modules} m ON m.id=cm.module
                    WHERE m.name='booking'
                    AND bo.id=:id";
            $cmid = $DB->get_field_sql($sql, $params);

            if ($cmid) {
                $context = context_module::instance($cmid);
            } else {
                $context = context_system::instance();
            }

            [$select, $from, $where, $params] = booking::get_options_filter_sql(
                0,
                1,
                null,
                '*',
                $context,
                [],
                ['id' => $optionid]
            );

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
            $this->maxoverbooking = $dbrecord->maxoverbooking ?? 0;
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
            $this->timecreated = $dbrecord->timecreated;
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
            $this->duration = $dbrecord->duration;
            $this->parentid = $dbrecord->parentid;
            $this->semesterid = $dbrecord->semesterid;
            $this->dayofweektime = $dbrecord->dayofweektime;
            $this->invisible = $dbrecord->invisible;
            $this->timemadevisible = $dbrecord->timemadevisible;
            $this->annotation = $dbrecord->annotation;
            $this->dayofweek = $dbrecord->dayofweek;
            $this->availability = $dbrecord->availability;
            $this->status = $dbrecord->status;
            $this->responsiblecontact = !empty($dbrecord->responsiblecontact) ? explode(',', $dbrecord->responsiblecontact) : [];
            $this->sqlfilter = $dbrecord->sqlfilter;
            $this->competencies = $dbrecord->competencies;

            // If we have a responsible contact id, we load the corresponding user object.
            if (!isset($dbrecord->responsiblecontactuser)) {
                $this->load_responsiblecontactuser();
                $dbrecord->responsiblecontactuser = $this->responsiblecontactuser;
            } else {
                $this->responsiblecontactuser = $dbrecord->responsiblecontactuser;
            }

            // Elecitve.
            $this->credits = $dbrecord->credits;
            $this->sortorder = $dbrecord->sortorder;

            $this->json = $dbrecord->json;

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

            // Some fields are stored in JSON.
            if (!empty($dbrecord->json)) {
                $this->load_data_from_json($dbrecord);
            } else {
                $this->boactions = [];
                $this->canceluntil = 0;
                $this->useprice = null; // Important: Use null as default so it will also work with old DB records.
                $this->selflearningcourse = 0;
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

            if (!isset($dbrecord->attachedfiles)) {
                $this->load_attachments($dbrecord);
                $dbrecord->attachedfiles = !empty($this->attachedfiles) ? $this->attachedfiles : [];
            } else {
                $this->attachedfiles = $dbrecord->attachedfiles;
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
                } else {
                    $dbrecord->imageurl = '';
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
                $dbrecord->customfieldsfortemplates = $this->customfieldsfortemplates ?? [];
            } else {
                $this->customfields = $dbrecord->customfields;
                $this->customfieldsfortemplates = $dbrecord->customfieldsfortemplates ?? [];
            }

            // If a cost center is defined in plugin settings, we load it directly into the booking option settings.
            $costcenterfield = get_config('booking', 'cfcostcenter');
            if (!empty($costcenterfield) && $costcenterfield != "-1") {
                if (isset($this->customfields[$costcenterfield])) {
                    $this->costcenter = $this->customfields[$costcenterfield];
                    $dbrecord->costcenter = $this->costcenter;
                }
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

            // If the key "electivecombinations" is not yet set, we need to load them via handler first.
            if (!isset($dbrecord->electivecombinations)) {
                $this->load_elective_combinations($optionid);
                $dbrecord->electivecombinations = $this->electivecombinations;
            } else {
                $this->electivecombinations = $dbrecord->electivecombinations;
            }

            // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
            // TODO: This is a performance problem. We need to cache campaigns!
            // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
            // TODO: We need to cache get_all_campaigns too!
            // Check if there are active campaigns.
            // If yes, we need to apply the booking limit factor.
            if (!isset($dbrecord->campaignisset)) {
                $campaigns = campaigns_info::get_all_campaigns();
                foreach ($campaigns as $camp) {
                    try {
                        /** @var booking_campaign $campaign */
                        $campaign = $camp;
                        if ($campaign->campaign_is_active($this->id, $this)) {
                            $campaign->apply_logic($this, $dbrecord);
                        }
                    } catch (\Exception $e) {
                        global $CFG;
                        if ($CFG->debug = (E_ALL)) {
                            throw $e;
                        }
                    }
                }
                // Campaigns have been applied - let's cache a flag so we do not do it again.
                $this->campaignisset = true;
                $dbrecord->campaignisset = true;
            } else {
                $this->campaignisset = $dbrecord->campaignisset;
                $this->campaigns = $dbrecord->campaigns ?? [];
            }
            if (!isset($dbrecord->subpluginssettings) && empty($this->subpluginssettings)) {
                $this->load_subpluginssettings($optionid);
                $dbrecord->subpluginssettings = $this->subpluginssettings;
            } else {
                $this->subpluginssettings = $dbrecord->subpluginssettings ?? [];
            }
            return $dbrecord;
        }

        // If record is not found in DB, we return null.
        return null;
    }

    /**
     * Function to load multi-sessions from DB.
     *
     * @param int $optionid
     */
    private function load_sessions_from_db(int $optionid) {
        global $DB;
        // Multi-sessions.
        if (
            !$this->sessions = $DB->get_records_sql(
                "SELECT bod.*, bod.id AS optiondateid
                FROM {booking_optiondates} bod
                WHERE bod.optionid = ?
                ORDER BY bod.coursestarttime ASC",
                [$optionid]
            )
        ) {
            // If there are no multisessions, but we still have the option's ...
            // ... coursestarttime and courseendtime, then store them as if they were a session.
            if (!empty($this->coursestarttime) && !empty($this->courseendtime)) {
                // NOTE: This part is legacy code. We need to check if we can safely remove it.
                $singlesession = new stdClass();
                $singlesession->id = 0;
                $singlesession->coursestarttime = $this->coursestarttime;
                $singlesession->courseendtime = $this->courseendtime;
                // We don't take the daystonotify value from the booking instance anymore, as this led to confusion.
                $singlesession->daystonotify = 0;
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
     * Loads Subplugindata from DB.
     *
     * @param int $optionid
     *
     * @return void
     *
     */
    private function load_subpluginssettings(int $optionid) {
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
             $class = "\\bookingextension_{$plugin->name}\\{$plugin->name}";
            if (class_exists($class)) {
                $this->subpluginssettings[$plugin->name] = $class::load_data_for_settings_singleton($optionid);
            }
        }
    }

    /**
     * Function to load teachers from DB.
     */
    private function load_teachers_from_db() {
        global $DB;

        $teachers = $DB->get_records_sql(
            "SELECT DISTINCT
                        t.userid,
                        u.firstname,
                        u.lastname,
                        u.email,
                        u.institution,
                        u.description,
                        u.descriptionformat,
                        u.username
                    FROM {booking_teachers} t
               LEFT JOIN {user} u ON t.userid = u.id
                   WHERE t.optionid = :optionid
                   ORDER BY u.lastname, u.firstname",
            ['optionid' => $this->id]
        );

        foreach ($teachers as $key => $teacher) {
            try {
                $context = context_user::instance($teacher->userid, MUST_EXIST);
                $descriptiontext = file_rewrite_pluginfile_urls(
                    $teacher->description,
                    'pluginfile.php',
                    $context->id,
                    'user',
                    'profile',
                    null,
                );
            } catch (Throwable $e) {
                $descriptiontext = $teacher->description;
            }

            $teachers[$key]->description = $descriptiontext;
            $teachers[$key]->descriptionformat = $teacher->descriptionformat;
        }

        $this->teachers = $teachers;
    }

    /**
     * Function to load the responsible contact user object.
     */
    private function load_responsiblecontactuser() {
        if (empty($this->responsiblecontact)) {
            return null;
        }
        foreach ($this->responsiblecontact as $contact) {
            $this->responsiblecontactuser[] = singleton_service::get_instance_of_user((int) $contact);
        }
    }

    /**
     * Function to load teacherids from DB.
     */
    private function load_teacherids_from_db() {
        global $DB;

        $teacherids = $DB->get_fieldset_select(
            'booking_teachers',
            'userid',
            "optionid = :optionid",
            ['optionid' => $this->id]
        );

        $this->teacherids = $teacherids;
    }

    /**
     * Function to render a list of teachers.
     * @return string
     */
    public function render_list_of_teachers() {
        global $OUTPUT;

        $renderedlistofteachers = '';

        if (empty($this->teachers)) {
            $this->load_teachers_from_db();
        }

        $data = [];
        $teachers = array_values($this->teachers);
        // Set 'notlast' flag if it's the last item. We need this for the template.
        $lastindex = count($teachers) - 1;

        foreach ($teachers as $index => $teacher) {
            $t = [
                'firstname' => $teacher->firstname,
                'lastname' => $teacher->lastname,
                'notlast' => ($index != $lastindex) ? 1 : 0,
            ];
            $data['teachers'][] = $t;
        }

        $renderedlistofteachers =
            $OUTPUT->render_from_template('mod_booking/bookingoption_description_teachers', $data);

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
        global $CFG;

        if (!empty($this->cmid) && !empty($optionid)) {
            $manageresponsesmoodleurl = new moodle_url(
                '/mod/booking/report.php',
                ['id' => $this->cmid, 'optionid' => $optionid]
            );

            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            if ($CFG->version >= 2023042400) {
                // Moodle 4.2 needs second param.
                $this->manageresponsesurl = html_entity_decode($manageresponsesmoodleurl->out(), ENT_QUOTES);
            } else {
                // Moodle 4.1 and older.
                $this->manageresponsesurl = html_entity_decode($manageresponsesmoodleurl->out(), ENT_COMPAT);
            }
        }
    }

    /**
     * Function to generate the optiondates-teachers-report URL.
     *
     * @param int $optionid option id
     */
    private function generate_optiondatesteachers_url(int $optionid) {
        global $CFG;

        if (!empty($this->cmid) && !empty($optionid)) {
            $optiondatesteachersmoodleurl = new moodle_url(
                '/mod/booking/optiondates_teachers_report.php',
                ['cmid' => $this->cmid, 'optionid' => $optionid]
            );

            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            if ($CFG->version >= 2023042400) {
                // Moodle 4.2 needs second param.
                $this->optiondatesteachersurl = html_entity_decode($optiondatesteachersmoodleurl->out(), ENT_QUOTES);
            } else {
                // Moodle 4.1 and older.
                $this->optiondatesteachersurl = html_entity_decode($optiondatesteachersmoodleurl->out(), ENT_COMPAT);
            }
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
        if (
            $imgfile = $DB->get_record_sql("SELECT id, contextid, filepath, filename
                                 FROM {files}
                                 WHERE component = 'mod_booking'
                                 AND itemid = :optionid
                                 AND filearea = 'bookingoptionimage'
                                 AND filesize > 0
                                 AND source is not null", ['optionid' => $optionid], IGNORE_MULTIPLE)
        ) {
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
                $customfieldvalue = $DB->get_field(
                    'customfield_data',
                    'value',
                    ['fieldid' => $customfieldid, 'instanceid' => $optionid]
                );

                if (!empty($customfieldvalue)) {
                    $customfieldvalue = strtolower($customfieldvalue);

                    if (
                        !$imgfiles = $DB->get_records_sql("SELECT id, contextid, filepath, filename
                                 FROM {files}
                                 WHERE component = 'mod_booking'
                                 AND itemid = :bookingid
                                 AND filearea = 'bookingimages'
                                 AND LOWER(filename) LIKE :customfieldvaluewithextension
                                 AND filesize > 0
                                 AND source is not null", ['bookingid' => $bookingid,
                                    'customfieldvaluewithextension' => "$customfieldvalue.%",
                                    ])
                    ) {
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
        global $DB;

        $handler = booking_handler::create();

        $datas = $handler->get_instance_data($optionid, true);

        foreach ($datas as $data) {
            $field = $data->get_field();
            $shortname = $field->get('shortname');
            $label = $field->get('name');
            $type = $field->get('type');
            $fieldid = $field->get('id');
            $value = $data->get_value();

            if (!empty($value)) {
                $this->customfields[$shortname] = $value;

                if ($type === 'select') {
                    $options = singleton_service::get_customfields_select_options($fieldid);
                    $value = $options[$value];
                }

                // We also return the customfieldsfortemplates where we get the real values of the selects.
                $this->customfieldsfortemplates[$shortname] = [
                    'label' => $label,
                    'key' => $shortname,
                    'value' => $value,
                    'type' => $type,
                ];
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
                'shortname' => $data->shortname,
                'parentname' => $data->parentname,
                'description' => $data->description ?? "",
                'maplink' => $data->maplink,
                'mapembed' => $data->mapembed,
            ];
        }
    }

    /**
     * Load subbookings
     *
     * @param int $optionid
     * @return void
     */
    private function load_subbookings(int $optionid) {
        $this->subbookings = subbookings_info::load_subbookings($optionid);
    }

    /**
     * Load elective combinations
     *
     * @param int $optionid
     * @return void
     */
    private function load_elective_combinations(int $optionid) {

        $this->electivecombinations = elective::load_combinations($optionid);
    }

    /**
     * Load after booking actions.
     *
     * @param stdClass $dbrecord
     * @return void
     */
    private function load_data_from_json(stdClass &$dbrecord) {

        // We might need to only now read the json object, but we want to do it only once.
        if (empty($dbrecord->jsonobject)) {
            $this->jsonobject = json_decode($dbrecord->json);
            $dbrecord->jsonobject = $this->jsonobject;

            // We only pass on the object, because the after booking action is not performance critical.
            // But we economize on the instantiation of the boaction classes.
            if (!empty($this->jsonobject->boactions)) {
                $this->boactions = (array)$this->jsonobject->boactions;
                // Just be sure they are stored as array.
                $this->jsonobject->boactions = $this->boactions;
                $dbrecord->boactions = $this->boactions;
            }

            // Canceluntil date is also stored in JSON.
            if (!empty($this->jsonobject->canceluntil)) {
                $this->canceluntil = (int)$this->jsonobject->canceluntil;
                $this->jsonobject->canceluntil = $this->canceluntil;
                $dbrecord->canceluntil = $this->canceluntil;
            }

            // Useprice flag indicates if the booking option uses a price.
            if (!empty($this->jsonobject->useprice)) {
                $this->useprice = (int)$this->jsonobject->useprice;
                $this->jsonobject->useprice = $this->useprice;
                $dbrecord->useprice = $this->useprice;
            }

            if (!empty($this->jsonobject->waitforconfirmation)) {
                $this->waitforconfirmation = (int)$this->jsonobject->waitforconfirmation;
                $this->jsonobject->waitforconfirmation = $this->waitforconfirmation;
                $dbrecord->waitforconfirmation = $this->waitforconfirmation;
            }

            if (!empty($this->jsonobject->confirmationonnotification)) {
                $this->confirmationonnotification = (int)$this->jsonobject->confirmationonnotification;
                $this->jsonobject->confirmationonnotification = $this->confirmationonnotification;
                $dbrecord->confirmationonnotification = $this->confirmationonnotification;
            }

            if (!empty($this->jsonobject->confirmationonnotificationoneatatime)) {
                $this->confirmationonnotificationoneatatime = (int)$this->jsonobject->confirmationonnotificationoneatatime;
                $this->jsonobject->confirmationonnotificationoneatatime = $this->confirmationonnotificationoneatatime;
                $dbrecord->confirmationonnotificationoneatatime = $this->confirmationonnotificationoneatatime;
            }

            // Selflearningcourse flag for course with duration but no optiondates.
            if (!empty($this->jsonobject->selflearningcourse)) {
                $this->selflearningcourse = (int)$this->jsonobject->selflearningcourse;
                $this->jsonobject->selflearningcourse = $this->selflearningcourse;
                $dbrecord->selflearningcourse = $this->selflearningcourse;
            }
        } else {
            $this->boactions = $dbrecord->boactions ?? null;
            $this->canceluntil = $dbrecord->canceluntil ?? 0;
            $this->useprice = $dbrecord->useprice ?? null;
            $this->selflearningcourse = $dbrecord->selflearningcourse ?? 0;
            $this->waitforconfirmation = $dbrecord->waitforconfirmation ?? 0;
            $this->confirmationonnotification = $dbrecord->confirmationonnotification ?? 0;
            $this->confirmationonnotificationoneatatime = $dbrecord->confirmationonnotificationoneatatime ?? 0;
            $this->jsonobject = $dbrecord->jsonobject ?? null;
        }
    }

    /**
     * Load after booking actions.
     *
     * @param stdClass $dbrecord
     * @return void
     */
    private function load_attachments(stdClass &$dbrecord) {

        if ($this->cmid) {
            $context = context_module::instance($this->cmid);
        } else {
            $context = context_system::instance();
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_booking', 'myfilemanageroption', $dbrecord->id);

        $attachedfiles = [];

        if (count($files) > 1) {
            foreach ($files as $file) {
                if ($file->get_filesize() > 0) {
                    $filename = $file->get_filename();
                    $url = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename(),
                        true
                    );
                    $attachedfiles[] = html_writer::link($url, $filename);
                }
            }
        }

        $this->attachedfiles = $attachedfiles;
    }

    /**
     * Returns the cached settings as stClass.
     * We will always have them in cache if we have constructed an instance,
     * but just in case we also deal with an empty cache object.
     *
     * @return stdClass|null
     */
    public function return_settings_as_stdclass() {

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
     * @param array $filterarray
     * @return array
     */
    public static function return_sql_for_customfield(array &$filterarray = []): array {

        global $DB;

         // Find out how many customfields are there for mod_booking.
         $customfields = booking_handler::get_customfields();

         $select = '';
         $from = '';
         $where = '';
         $params = [];
        // Now we have the names of the customfields. We can now run through them and add them as colums.

        $counter = 1;
        foreach ($customfields as $customfield) {
            $name = $customfield->shortname;

            // We need to throw an error when there is a space in the shortname.

            if (preg_match('/[^a-z0-9_]/', $name) > 0) {
                throw new moodle_exception(
                    'nospacesinshortnames',
                    'mod_booking',
                    '',
                    $name,
                    "This shortname of a booking customfield contains forbidden characters"
                );
            }

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
                ON cff.categoryid=cfc.id AND cfc.component=:" . $name . "_cn
            ) cfd$counter
            ON bo.id = cfd$counter.instanceid ";

            // Add the variables to the params array.
            $params[$name . '_cn'] = 'mod_booking';
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
     * Function to include all the values of the given custom profile fields to a table.
     * The table is joined via userinfodata.userid = bookinganswer.userid & userinfofield.id = userinfodata.fieldid
     * To be able to filter for the same param twice, we use this structure for searchparams [[$fieldnmae => $fieldvalue]]
     *
     * @param array $userinfofields
     * @return array
     */
    public static function return_sql_for_custom_profile_field($userinfofields = []): array {

        global $DB;

        if (empty($userinfofields)) {
            $userinfofields = $DB->get_records('user_info_field', []);
        }

         $select = '';
         $from = '';
         $where = '';
         $params = [];
        // Now we have the names of the customfields. We can now run through them and add them as colums.

        $counter = 1;
        if (!empty($userinfofields)) {
            $select = " , ";
        }
        foreach ($userinfofields as $userinfofield) {
            $name = $userinfofield->shortname;

            $select .= "s$counter.data as $name ";

            // After the last instance, we don't add a comma.
            $select .= $counter >= count($userinfofields) ? "" : ", ";

            $from .= " LEFT JOIN
            (
                SELECT ud.id, ud.data, ud.userid
                FROM {user_info_data} ud
                JOIN {user_info_field} uif
                ON ud.fieldid = uif.id
                WHERE uif.shortname LIKE '$name' AND ud.data <> ''
            ) s$counter
            ON s$counter.userid = ba.userid ";

            // phpcs:disable
            // Add the variables to the params array.
            // $params[$name . '_componentname'] = 'mod_booking';
            // $params["cf_$name"] = $name;
            // phpcs:enable
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
            "u.lastname",
            "', '",
            'u.firstname',
            "'\"}'",
        ]);
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

    /**
     * Returns sql for imagefiles.
     *
     * @param array $searchparams
     *
     * @return array
     *
     */
    public static function return_sql_for_imagefiles($searchparams = []): array {

        global $DB;

        $select = ' f.filename ';

        $where = '';
        $params = [];

        // We have to join images with itemid and contextid to be sure to have the right image.
        // We use contextlevel 70 as it is the contextlevel for course modules.
        $from = " LEFT JOIN (
                SELECT cm1.instance, ctx1.id FROM {course_modules} cm1
                JOIN {modules} m1 ON m1.id = cm1.module AND m1.name = 'booking'
                JOIN {context} ctx1 ON ctx1.contextlevel = 70 AND ctx1.instanceid = cm1.id
            ) ctx
            ON bo.bookingid = ctx.instance AND bo.bookingid <> 0 AND bo.bookingid IS NOT NULL
            LEFT JOIN {files} f
            ON f.itemid = bo.id
            AND f.contextid = ctx.id
            AND f.component = 'mod_booking'
            AND f.filearea = 'bookingoptionimage'
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

    /**
     * Especially to create a shopping cart and such...
     * ... we want one central function where we always get all the necessary keys.
     *
     * @param object|null $user
     * @return array
     */
    public function return_booking_option_information(?object $user = null): array {

        global $USER;

        if (empty($user)) {
            $user = $USER;
        }
        if (!empty($this->jsonobject->useprice)) {
            $price = price::get_price('option', $this->id, $user);
        } else {
            $price = [];
        }

        $canceluntil = booking_option::return_cancel_until_date($this->id);

        $returnarray = [
            'itemid' => $this->id,
            'title' => $this->get_title_with_prefix(),
            'price' => $price['price'] ?? null,
            'currency' => $price['currency'] ?? null,
            'userid' => $user->id,
            'component' => 'mod_booking',
            'area' => 'option',
            'description' => $this->description,
            'imageurl' => $this->imageurl ?? '',
            'canceluntil' => $canceluntil ?? 0,
            'coursestarttime' => $this->coursestarttime ?? 0,
            'courseendtime' => $this->courseendtime ?? 0,
            'costcenter' => $this->costcenter ?? '',
            'sessions' => array_values(array_map(fn($a) => [
                'coursestarttime' => userdate($a->coursestarttime),
                'courseendtime' => userdate($a->courseendtime),
                'concatinatedstartendtime' => dates_handler::prettify_optiondates_start_end(
                    $a->coursestarttime,
                    $a->courseendtime,
                    current_language(),
                ),
            ], $this->sessions)),
            'teachers' => array_values(array_map(fn($a) => [
                'firstname' => $a->firstname,
                'lastname' => $a->lastname,
                'email' => str_replace('@', '&#64;', $a->email ?? ''),
            ], $this->teachers)),
        ];

        return $returnarray;
    }

    /**
     * Especially to create a shopping cart and such...
     * ... we want one central function where we always get all the necessary keys.
     *
     * @param int $subbookingid
     * @param object|null $user
     * @return array
     */
    public function return_subbooking_option_information(int $subbookingid, ?object $user = null): array {

        global $USER;

        if (empty($user)) {
            $user = $USER;
        }

        $subbooking = subbookings_info::get_subbooking_by_area_and_id('subbooking', $subbookingid);

        // This is the price for the subbooking id.
        $price = $subbooking->return_price($user);
        $description = $subbooking->return_description($user);

        // But some subbookings might have a different price, eg. when you can buy one item multiple times.
        $canceluntil = booking_option::return_cancel_until_date($this->id);

        $returnarray = [
            'itemid' => $subbookingid,
            'name' => $subbooking->name,
            'price' => $price['price'] ?? "0.00",
            'currency' => $price['currency'] ?? 'EUR',
            'userid' => $user->id,
            'component' => 'mod_booking',
            'area' => 'subbooking',
            'description' => $description,
            'canceluntil' => $canceluntil ?? 0,
            'coursestarttime' => $this->coursestarttime ?? 0,
            'courseendtime' => $this->courseendtime ?? 0,
            'costcenter' => $this->costcenter ?? '',
        ];

        return $returnarray;
    }
}
