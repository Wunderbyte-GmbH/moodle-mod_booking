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
 * This class prepares data for displaying the teacher page.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\output;

use context_system;
use context_module;
use context_user;
use mod_booking\booking_answers;
use mod_booking\singleton_service;
use moodle_url;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * This class prepares data for displaying the teacher page.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_teacher implements renderable, templatable {
    /** @var stdClass $teacher */
    public $teacher = null;

    /** @var bool $error */
    public $error = null;

    /** @var string $errormessage */
    public $errormessage = null;

    /**
     * In the Constructor, we gather all the data we need ans store it in the data property.
     *
     * @param int $teacherid
     * @return void
     *
     */
    public function __construct(int $teacherid) {
        global $DB;
        // We get the user object of the provided teacher.
        if (!$this->teacher = $DB->get_record('user', ['id' => $teacherid])) {
            $this->error = true;
            $this->errormessage = get_string('teachernotfound', 'mod_booking');
            return;
        }

        // Now get a list of all teachers...
        // Now get all teachers that we're interested in.
        $sqlteachers = "SELECT DISTINCT userid FROM {booking_teachers}";
        if ($teacherrecords = $DB->get_records_sql($sqlteachers)) {
            foreach ($teacherrecords as $teacherrecord) {
                $teacherids[] = $teacherrecord->userid;
            }
        }
        // ... and check if the selected teacherid is part of it.
        if (!in_array($teacherid, $teacherids)) {
            $this->error = true;
            $this->errormessage = get_string('notateacher', 'mod_booking');
            return;
        }
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $PAGE, $CFG, $USER;

        $context = context_system::instance();
        if (!isset($PAGE->context)) {
            $PAGE->set_context($context);
        }

        $returnarray = [];

        // If we had an error, we return the error message.
        if ($this->error) {
            $returnarray['error'] = $this->error;
            $returnarray['errormessage'] = $this->errormessage;
            return $returnarray;
        }

        // Here we can load custom userprofile fields and add the to the array to render.
        // Right now, we just use a few standard pieces of information.

        // Get all booking options where the teacher is teaching and sort them by instance.
        $teacheroptiontables = $this->get_option_tables_for_teacher($this->teacher->id);

        $context = context_user::instance($this->teacher->id, MUST_EXIST);
        $descriptiontext = file_rewrite_pluginfile_urls(
            $this->teacher->description,
            'pluginfile.php',
            $context->id,
            'user',
            'profile',
            null,
        );

        $returnarray['teacher'] = [
            'teacherid' => $this->teacher->id,
            'firstname' => $this->teacher->firstname,
            'lastname' => $this->teacher->lastname,
            'description' => format_text($descriptiontext, $this->teacher->descriptionformat),
            'optiontables' => $teacheroptiontables,
            'canedit' => has_capability('mod/booking:editteacherdescription', $context),
        ];

        // If the user has set to hide e-mails, we won't show them.
        // However, a site admin will always see e-mail addresses.
        // If the plugin setting to show all teacher e-mails (teachersshowemails) is turned on...
        // ... then teacher e-mails will always be shown to anyone.
        if (
            !empty($this->teacher->email) &&
            ($this->teacher->maildisplay == 1 ||
                has_capability('mod/booking:updatebooking', $context) ||
                get_config('booking', 'teachersshowemails') ||
                (get_config('booking', 'bookedteachersshowemails') &&
                    (booking_answers::number_actively_booked($USER->id, $this->teacher->id) > 0))
            )
        ) {
            $returnarray['teacher']['email'] = $this->teacher->email;
        }

        if ($this->teacher->picture) {
            $picture = new \user_picture($this->teacher);
            $picture->size = 150;
            $imageurl = $picture->get_url($PAGE);
            $returnarray['image'] = $imageurl;
        }
        if (!empty($CFG->messaging)) {
            if (self::teacher_messaging_is_possible($this->teacher->id)) {
                $returnarray['messagingispossible'] = true;
            }
        } else {
            $returnarray['messagesdeactivated'] = true;
        }

        // Add a link to the report of performed teaching units.
        // But only, if the user has the appropriate capability.
        if ((has_capability('mod/booking:updatebooking', $PAGE->context))) {
            $url = new moodle_url('/mod/booking/teacher_performed_units_report.php', ['teacherid' => $this->teacher->id]);
            $returnarray['linktoperformedunitsreport'] = $url->out();
        }
        if ((has_capability('mod/booking:seepersonalteacherinformation', $PAGE->context))) {
            // Add given phonenumbers.
            if (!empty($this->teacher->phone1)) {
                $returnarray['teacher']['phones'][] = $this->teacher->phone1;
            }
            if (!empty($this->teacher->phone2)) {
                $returnarray['teacher']['phones'][] = $this->teacher->phone2;
            }
        }
        // Include wwwroot for links.
        $returnarray['wwwroot'] = $CFG->wwwroot;
        return $returnarray;
    }

    /**
     * Helper function to create wunderbyte_tables for all options of a specific teacher.
     *
     * @param int $teacherid userid of a specific teacher
     * @param int $perpage
     * @return array an array of tables as string
     */
    private function get_option_tables_for_teacher(int $teacherid, $perpage = 100) {

        global $DB, $USER;

        $teacheroptiontables = [];

        /* If the booking instances are connected with semesters,
        we use the start of semester to sort the instances.
        Else, we just sort by id DESC (so in the order they were created)
        showing the newest ones first.*/
        $bookingidrecords = $DB->get_records_sql(
            "SELECT DISTINCT t.bookingid, CASE
                WHEN s.startdate IS NOT NULL THEN s.startdate
                ELSE 0
            END AS orderdate
            FROM {booking_teachers} t
            JOIN {booking} b ON b.id = t.bookingid
            LEFT JOIN {booking_semesters} s ON b.semesterid = s.id
            WHERE t.userid = :teacherid
            ORDER BY orderdate DESC, t.bookingid DESC",
            ['teacherid' => $teacherid]
        );

        $firsttable = true;

        // This is a special setting for a special project. Only when this project is installed...
        // ... the set semester will get precedence over all the other ones.
        if (class_exists('local_musi\observer')) {
            $firstbookingcmid = get_config('local_musi', 'shortcodessetinstance');

            foreach ($bookingidrecords as $key => $bookingidrecord) {
                $booking = singleton_service::get_instance_of_booking_by_bookingid($bookingidrecord->bookingid);
                if ($firstbookingcmid == $booking->cmid) {
                    $record = $bookingidrecord;
                    unset($bookingidrecords[$key]);
                    array_unshift($bookingidrecords, $record);
                }
            }
        }

        // In settings, booking instances can be hidden from the teacher pages.
        $hiddenbookingids = explode(',', get_config('booking', 'teacherpageshiddenbookingids') ?? '');
        if (!empty($hiddenbookingids)) {
            foreach ($bookingidrecords as $key => $bookingidrecord) {
                if (in_array($bookingidrecord->bookingid, $hiddenbookingids)) {
                    unset($bookingidrecords[$key]);
                }
            }
        }

        foreach ($bookingidrecords as $bookingidrecord) {
            $bookingid = $bookingidrecord->bookingid;

            if ($booking = singleton_service::get_instance_of_booking_by_bookingid($bookingid)) {
                // If a booking option is set to invisible, we just wont show the instance right away.
                // This can not replace the check if the user actually has the rights to see it.
                $modinfo = get_fast_modinfo($booking->course, $USER->id);
                $cm = $modinfo->get_cm($booking->cmid);

                if (!$cm->visible) {
                    continue;
                }

                // We load only the first table directly, the other ones lazy.
                $lazy = $firsttable ? false : true;

                $view = new view($booking->cmid, 'shownothing', 0, true);
                $out = $view->get_rendered_table_for_teacher($teacherid, false, false, false, $lazy);

                $class = $firsttable ? 'active show' : '';
                $firsttable = false;

                // Keep only lowercaseletters and digits.
                $tablename = preg_replace("/[^a-z0-9]/", '', strtolower($booking->settings->name));

                $newtable = [
                    'bookingid' => $bookingid,
                    'bookinginstancename' => $booking->settings->name,
                    'tablename' => $tablename,
                    'table' => $out,
                    'class' => $class,
                ];

                 // Todo: Only show booking options from instance that is available.
                // Right now, we don't use it. needs a setting.
                if (1 == 2) {
                    $context = context_module::instance($booking->cmid);
                    if (!has_capability('mod/booking:choose', $context)) {
                        continue;
                    }
                }

                $teacheroptiontables[] = $newtable;
            }
        }

        return $teacheroptiontables;
    }

    /**
     * Helper functions which checks if the teacher
     * and current user are at least in one common course,
     * which is a prerequisite for messaging to work with Moodle.
     * If yes, it returns true, else it returns false.
     *
     * @param int $teacherid id of the teacher to check
     * @return bool true if possible, else false
     */
    public static function teacher_messaging_is_possible(int $teacherid) {
        global $DB, $USER;

        // SQL to check if teacher has common courses with the logged in $USER.
        $sql = "SELECT e.courseid
                FROM {user_enrolments} ue
                LEFT JOIN {enrol} e
                ON e.id = ue.enrolid
                WHERE ue.status = 0
                AND userid = :currentuserid

                INTERSECT

                SELECT e.courseid
                FROM {user_enrolments} ue
                LEFT JOIN {enrol} e
                ON e.id = ue.enrolid
                WHERE ue.status = 0
                AND userid = :teacherid";

        $params = [
            'currentuserid' => $USER->id,
            'teacherid' => $teacherid,
        ];

        if ($commoncourses = $DB->get_records_sql($sql, $params)) {
            // There is at least one common course.
            return true;
        }

        // No common courses, which means messaging is impossible.
        return false;
    }
}
