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

use stdClass;

/**
 * Settings class for booking instances.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_settings {
    /** @var int $cmid of booking instance. */
    public $cmid = null;

    /** @var int $id The ID of the booking instance. */
    public $id = null;

    /** @var int $course The ID of the associated course. */
    public $course = null;

    /** @var string $name The name of the booking instance. */
    public $name = null;

    /** @var string $intro Intro text of the booking instance. */
    public $intro = null;

    /** @var int $introformat Format of the intro text. */
    public $introformat = null;

    /** @var string $bookingmanager The username of the booking manager. */
    public $bookingmanager = null;

    /** @var int $mailtemplatessource */
    public $mailtemplatessource = null;

    /** @var int $sendmail */
    public $sendmail = null;

    /** @var int $copymail */
    public $copymail = null;

    /** @var int $allowupdate */
    public $allowupdate = null;

    /** @var string $bookingpolicy */
    public $bookingpolicy = null;

    /** @var int $bookingpolicyformat */
    public $bookingpolicyformat = null;

    /** @var int $timeopen */
    public $timeopen = null;

    /** @var int $timeclose */
    public $timeclose = null;

    /** @var int $timemodified */
    public $timemodified = null;

    /** @var int $autoenrol */
    public $autoenrol = null;

    /** @var string $bookedtext */
    public $bookedtext = null;

    /** @var string $waitingtext */
    public $waitingtext = null;

    /** @var string $statuschangetext */
    public $statuschangetext = null;

    /** @var string $deletedtext */
    public $deletedtext = null;

    /** @var string $bookingchangedtext */
    public $bookingchangedtext = null;

    /** @var int $maxperuser */
    public $maxperuser = null;

    /** @var int $sendmailtobooker */
    public $sendmailtobooker = null;

    /** @var string $duration Currently duration is a string. */
    public $duration = null;

    /** @var float $points */
    public $points = null;

    /** @var string $organizatorname */
    public $organizatorname = null;

    /** @var string $pollurl */
    public $pollurl = null;

    /** @var int $addtogroup */
    public $addtogroup = null;

    /** @var array $addtogroupofcurrentcourse */
    public $addtogroupofcurrentcourse = null;

    /** @var string $categoryid One or more category ids - separated with commas. */
    public $categoryid = null;

    /** @var string $pollurltext */
    public $pollurltext = null;

    /** @var string $eventtype */
    public $eventtype = null;

    /** @var string $notificationtext */
    public $notificationtext = null;

    /** @var string $userleave */
    public $userleave = null;

    /** @var int $enablecompletion */
    public $enablecompletion = null;

    /** @var string $pollurlteachers */
    public $pollurlteachers = null;

    /** @var string $pollurlteacherstext */
    public $pollurlteacherstext = null;

    /** @var string $activitycompletiontext */
    public $activitycompletiontext = null;

    /** @var int $cancancelbook */
    public $cancancelbook = null;

    /** @var int $conectedbooking */
    public $conectedbooking = null;

    /** @var int $showinapi */
    public $showinapi = null;

    /** @var string $lblbooking */
    public $lblbooking = null;

    /** @var string $lbllocation */
    public $lbllocation = null;

    /** @var string $lblinstitution */
    public $lblinstitution = null;

    /** @var string $lblname */
    public $lblname = null;

    /** @var string $lblsurname */
    public $lblsurname = null;

    /** @var string $btncacname */
    public $btncacname = null;

    /** @var string $lblteachname */
    public $lblteachname = null;

    /** @var string $lblsputtname */
    public $lblsputtname = null;

    /** @var string $btnbooknowname */
    public $btnbooknowname = null;

    /** @var string $btncancelname */
    public $btncancelname = null;

    /** @var string $booktootherbooking */
    public $booktootherbooking = null;

    /** @var string $lblacceptingfrom */
    public $lblacceptingfrom = null;

    /** @var string $lblnumofusers */
    public $lblnumofusers = null;

    /** @var int $numgenerator */
    public $numgenerator = null;

    /** @var int $paginationnum */
    public $paginationnum = null;

    /** @var string $banusernames */
    public $banusernames = null;

    /** @var int $daystonotify */
    public $daystonotify = null;

    /** @var string $notifyemail */
    public $notifyemail = null;

    /** @var int $daystonotifyteachers */
    public $daystonotifyteachers = null;

    /** @var string $notifyemailteachers */
    public $notifyemailteachers = null;

    /** @var int $assessed */
    public $assessed = null;

    /** @var int $assesstimestart */
    public $assesstimestart = null;

    /** @var int $assesstimefinish */
    public $assesstimefinish = null;

    /** @var int $scale */
    public $scale = null;

    /** @var string $whichview */
    public $whichview = null;

    /** @var int $daystonotify2 */
    public $daystonotify2 = null;

    /** @var int $completionmodule */
    public $completionmodule = null;

    /** @var string $responsesfields */
    public $responsesfields = null;

    /** @var string $reportfields */
    public $reportfields = null;

    /** @var string $optionsfields */
    public $optionsfields = null;

    /** @var string $optionsdownloadfields */
    public $optionsdownloadfields = null;

    /** @var string $beforebookedtext */
    public $beforebookedtext = null;

    /** @var string $beforecompletedtext */
    public $beforecompletedtext = null;

    /** @var string $aftercompletedtext */
    public $aftercompletedtext = null;

    /** @var string $signinsheetfields */
    public $signinsheetfields = null;

    /** @var int $comments */
    public $comments = null;

    /** @var int $ratings */
    public $ratings = null;

    /** @var int $removeuseronunenrol */
    public $removeuseronunenrol = null;

    /** @var int $teacherroleid */
    public $teacherroleid = null;

    /** @var int $allowupdatedays */
    public $allowupdatedays = null;

    /** @var int $templateid */
    public $templateid = null;

    /** @var int $showlistoncoursepage */
    public $showlistoncoursepage = null;

    /** @var string $coursepageshortinfo */
    public $coursepageshortinfo = null;

    /** @var int bookingimagescustomfield */
    public $bookingimagescustomfield = null;

    /** @var string $defaultoptionsort */
    public $defaultoptionsort = null;

    /** @var string $defaultsortorder */
    public $defaultsortorder = null;

    /** @var string $showviews */
    public $showviews = null;

    /** @var int $customtemplateid */
    public $customtemplateid = null;

    /** @var int $autcractive */
    public $autcractive = null;

    /** @var string $autcrprofile */
    public $autcrprofile = null;

    /** @var string $autcrvalue */
    public $autcrvalue = null;

    /** @var int $autcrtemplate */
    public $autcrtemplate = null;

    /** @var int $semesterid */
    public $semesterid = null;

    /** @var int $iselective */
    public $iselective = null;

    /** @var int $consumeatonce */
    public $consumeatonce = null;

    /** @var int $maxcredits */
    public $maxcredits = null;

    /** @var int $enforceorder */
    public $enforceorder = null;

    /** @var int $enforceteacherorder */
    public $enforceteacherorder = null;

    /** @var stdClass $bookingmanageruser */
    public $bookingmanageruser = null;

    /** @var string $json is used to store non performance critical data like disablecancel, viewparam */
    public $json = null;

    /** @var object $jsonobject is used to store non performance critical data like disablecancel, viewparam */
    public $jsonobject = null;

    // Explicit declaration of params to avoid "Creation of dynamic property booking_settings::$xxxxxx is deprecated" error.

    /** @var int $disablecancel */
    public $disablecancel = null;

    /** @var int $viewparam */
    public $viewparam = null;

    /** @var int $switchtemplates checkbox (de-)activate template switcher */
    public $switchtemplates = null;

    /** @var array $switchtemplatesselection an array of templates for the template switcher */
    public $switchtemplatesselection = null;

    /** @var int $overwriteblockingwarnings */
    public $overwriteblockingwarnings = null;

    /** @var int $disablebooking */
    public $disablebooking = null;

    /** @var int $billboardtext */
    public $billboardtext = null;

    /** @var int $cancelrelativedate */
    public $cancelrelativedate = null;

    /** @var int $allowupdatetimestamp */
    public $allowupdatetimestamp = null;

    /** @var mixed $maxoptionsfromcategory */
    public $maxoptionsfromcategory = null;

    /** @var int $maxoptionsfrominstance */
    public $maxoptionsfrominstance = null;

    /** @var string $customfieldsforfilter */
    public $customfieldsforfilter = null;

    /**
     * Constructor for the booking settings class.
     *
     * @param int $cmid course module id of the booking instance
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function __construct(int $cmid) {

        // We cache all the options normally and don't do.

        $cache = \cache::make('mod_booking', 'cachedbookinginstances');
        $cachedsettings = $cache->get($cmid);

        if (!$cachedsettings) {
            $cachedsettings = null;
        }

        // If we have no object to pass to set values, the function will retrieve the values from db.
        if ($data = $this->set_values($cmid, $cachedsettings)) {
            // Only if we didn't pass anything to cachedoption, we set the cache now.
            if (!$cachedsettings) {
                $cache->set($cmid, $data);
            }
        }
    }


    /**
     * Set all the values from DB, if necessary.
     * If we have passed on the cached object, we use this one.
     *
     * @param int $cmid
     * @param object|null $dbrecord
     * @return object|null $dbrecordid
     */
    private function set_values(int $cmid, ?object $dbrecord = null) {
        global $DB;

        // If we don't get the cached object, we have to fetch it here.
        if ($dbrecord === null) {
            $sql = "SELECT b.*
                    FROM {course_modules} cm
                    JOIN {modules} m
                    ON cm.module = m.id
                    JOIN {booking} b
                    ON cm.instance = b.id
                    WHERE m.name='booking'
                    AND cm.id = :cmid";

            $dbrecord = $DB->get_record_sql($sql, ["cmid" => $cmid]);
        }

        if ($dbrecord) {
            $dbrecord->cmid = $cmid;
            $this->cmid = $cmid;
            $this->id = $dbrecord->id;
            $this->course = $dbrecord->course;
            $this->name = $dbrecord->name;
            $this->intro = $dbrecord->intro;
            $this->introformat = $dbrecord->introformat;
            $this->bookingmanager = $dbrecord->bookingmanager;
            $this->mailtemplatessource = $dbrecord->mailtemplatessource;
            $this->sendmail = $dbrecord->sendmail;
            $this->copymail = $dbrecord->copymail;
            $this->allowupdate = $dbrecord->allowupdate;
            $this->bookingpolicy = $dbrecord->bookingpolicy;
            $this->bookingpolicyformat = $dbrecord->bookingpolicyformat;
            $this->timeopen = $dbrecord->timeopen;
            $this->timeclose = $dbrecord->timeclose;
            $this->timemodified = $dbrecord->timemodified;
            $this->autoenrol = $dbrecord->autoenrol;
            $this->bookedtext = $dbrecord->bookedtext;
            $this->waitingtext = $dbrecord->waitingtext;
            $this->statuschangetext = $dbrecord->statuschangetext;
            $this->deletedtext = $dbrecord->deletedtext;
            $this->bookingchangedtext = $dbrecord->bookingchangedtext;
            $this->maxperuser = $dbrecord->maxperuser;
            $this->sendmailtobooker = $dbrecord->sendmailtobooker;
            $this->duration = $dbrecord->duration;
            $this->points = $dbrecord->points;
            $this->organizatorname = $dbrecord->organizatorname;
            $this->pollurl = $dbrecord->pollurl;
            $this->addtogroup = $dbrecord->addtogroup;
            $this->categoryid = $dbrecord->categoryid;
            $this->pollurltext = $dbrecord->pollurltext;
            $this->eventtype = $dbrecord->eventtype;
            $this->notificationtext = $dbrecord->notificationtext;
            $this->userleave = $dbrecord->userleave;
            $this->enablecompletion = $dbrecord->enablecompletion;
            $this->pollurlteachers = $dbrecord->pollurlteachers;
            $this->pollurlteacherstext = $dbrecord->pollurlteacherstext;
            $this->activitycompletiontext = $dbrecord->activitycompletiontext;
            $this->cancancelbook = $dbrecord->cancancelbook;
            $this->conectedbooking = $dbrecord->conectedbooking;
            $this->showinapi = $dbrecord->showinapi;
            $this->lblbooking = $dbrecord->lblbooking;
            $this->lbllocation = $dbrecord->lbllocation;
            $this->lblinstitution = $dbrecord->lblinstitution;
            $this->lblname = $dbrecord->lblname;
            $this->lblsurname = $dbrecord->lblsurname;
            $this->btncacname = $dbrecord->btncacname;
            $this->lblteachname = $dbrecord->lblteachname;
            $this->lblsputtname = $dbrecord->lblsputtname;
            $this->btnbooknowname = $dbrecord->btnbooknowname;
            $this->btncancelname = $dbrecord->btncancelname;
            $this->booktootherbooking = $dbrecord->booktootherbooking;
            $this->lblacceptingfrom = $dbrecord->lblacceptingfrom;
            $this->lblnumofusers = $dbrecord->lblnumofusers;
            $this->numgenerator = $dbrecord->numgenerator;
            $this->paginationnum = $dbrecord->paginationnum;
            $this->banusernames = $dbrecord->banusernames;
            $this->daystonotify = $dbrecord->daystonotify;
            $this->notifyemail = $dbrecord->notifyemail;
            $this->daystonotifyteachers = $dbrecord->daystonotifyteachers;
            $this->notifyemailteachers = $dbrecord->notifyemailteachers;
            $this->assessed = $dbrecord->assessed;
            $this->assesstimestart = $dbrecord->assesstimestart;
            $this->assesstimefinish = $dbrecord->assesstimefinish;
            $this->scale = $dbrecord->scale;
            $this->whichview = $dbrecord->whichview;
            $this->daystonotify2 = $dbrecord->daystonotify2;
            $this->completionmodule = $dbrecord->completionmodule;
            $this->responsesfields = $dbrecord->responsesfields;
            $this->reportfields = $dbrecord->reportfields;
            $this->optionsfields = $dbrecord->optionsfields;
            $this->optionsdownloadfields = $dbrecord->optionsdownloadfields;
            $this->beforebookedtext = $dbrecord->beforebookedtext;
            $this->beforecompletedtext = $dbrecord->beforecompletedtext;
            $this->aftercompletedtext = $dbrecord->aftercompletedtext;
            $this->signinsheetfields = $dbrecord->signinsheetfields;
            $this->comments = $dbrecord->comments;
            $this->ratings = $dbrecord->ratings;
            $this->removeuseronunenrol = $dbrecord->removeuseronunenrol;
            $this->teacherroleid = $dbrecord->teacherroleid;
            $this->allowupdatedays = $dbrecord->allowupdatedays;
            $this->templateid = $dbrecord->templateid;
            $this->showlistoncoursepage = $dbrecord->showlistoncoursepage;
            $this->coursepageshortinfo = $dbrecord->coursepageshortinfo;
            $this->bookingimagescustomfield = $dbrecord->bookingimagescustomfield;
            $this->defaultoptionsort = $dbrecord->defaultoptionsort;
            $this->defaultsortorder = $dbrecord->defaultsortorder;
            $this->showviews = $dbrecord->showviews;
            $this->customtemplateid = $dbrecord->customtemplateid;
            $this->autcractive = $dbrecord->autcractive;
            $this->autcrprofile = $dbrecord->autcrprofile;
            $this->autcrvalue = $dbrecord->autcrvalue;
            $this->autcrtemplate = $dbrecord->autcrtemplate;
            $this->semesterid = $dbrecord->semesterid;

            // Elective.
            $this->iselective = $dbrecord->iselective;
            $this->consumeatonce = $dbrecord->consumeatonce;
            $this->maxcredits = $dbrecord->maxcredits;
            $this->enforceorder = $dbrecord->enforceorder;
            $this->enforceteacherorder = $dbrecord->enforceteacherorder;

            // JSON.
            $this->json = $dbrecord->json;
            if (!empty($dbrecord->json)) {
                $this->jsonobject = json_decode($this->json);
                foreach ($this->jsonobject as $key => $value) {
                    if (property_exists($this, $key)) {
                        $this->$key = $value;
                    }
                }
            } else {
                $this->jsonobject = new stdClass();
            }

            // If we do not have it yet, we have to load the booking manager's user object from DB.
            if (empty($dbrecord->bookingmanageruser) && !empty($dbrecord->bookingmanager)) {
                $dbrecord->bookingmanageruser = $this->load_bookingmanageruser_from_db($dbrecord->bookingmanager);
            }
            if (!empty($dbrecord->bookingmanageruser)) {
                $this->bookingmanageruser = $dbrecord->bookingmanageruser;
            } else {
                // Make sure, it's always null if booking manager could not be found.
                $dbrecord->bookingmanageruser = null;
                $this->bookingmanageruser = null;
            }

            return $dbrecord;
        } else {
            debugging('Could not create settings class for booking with course module id (cmid): ' . $cmid);
        }
    }

    /**
     * Function to load bookingmanager as user from DB.
     * @param string $username of a booking manager
     * @return stdClass|null user object for booking manager
     */
    private function load_bookingmanageruser_from_db(string $username) {
        global $DB;
        if (!empty($username)) {
            return $DB->get_record('user', ['username' => $username]);
        } else {
            return null;
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

        $cache = \cache::make('mod_booking', 'cachedbookinginstances');
        $cachedoption = $cache->get($this->cmid);

        if (!$cachedoption) {
            $cachedoption = $this->set_values($this->cmid);
        }

        return $cachedoption;
    }
}
