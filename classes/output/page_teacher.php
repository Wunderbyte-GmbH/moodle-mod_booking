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
        global $PAGE, $CFG;

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

        $returnarray['teacher'] = [
            'teacherid' => $this->teacher->id,
            'firstname' => $this->teacher->firstname,
            'lastname' => $this->teacher->lastname,
            'description' => format_text($this->teacher->description, $this->teacher->descriptionformat),
            'optiontables' => $teacheroptiontables,
        ];

        // If the user has set to hide e-mails, we won't show them.
        // However, a site admin will always see e-mail addresses.
        // If the plugin setting to show all teacher e-mails (teachersshowemails) is turned on...
        // ... then teacher e-mails will always be shown to anyone.
        if (!empty($this->teacher->email) &&
            ($this->teacher->maildisplay == 1 ||
                has_capability('mod/booking:updatebooking', $context) ||
                get_config('booking', 'teachersshowemails')
            )) {
            $returnarray['teacher']['email'] = $this->teacher->email;
        }

        if ($this->teacher->picture) {
            $picture = new \user_picture($this->teacher);
            $picture->size = 150;
            $imageurl = $picture->get_url($PAGE);
            $returnarray['image'] = $imageurl;
        }

        if (self::teacher_messaging_is_possible($this->teacher->id)) {
            $returnarray['messagingispossible'] = true;
        }

        // Add a link to the report of performed teaching units.
        // But only, if the user has the appropriate capability.
        if ((has_capability('mod/booking:updatebooking', $PAGE->context))) {
            $url = new moodle_url('/mod/booking/teacher_performed_units_report.php', ['teacherid' => $this->teacher->id]);
            $returnarray['linktoperformedunitsreport'] = $url->out();
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
    private function get_option_tables_for_teacher(int $teacherid, $perpage = 1000) {

        global $DB, $USER;

        $teacheroptiontables = [];

        $bookingidrecords = $DB->get_records_sql(
            "SELECT DISTINCT bookingid FROM {booking_teachers} WHERE userid = :teacherid ORDER By bookingid ASC",
            ['teacherid' => $teacherid]
        );

        $firsttable = true;
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
                $lazy = $firsttable ? '' : ' lazy="1" ';

                $view = new view($booking->cmid);
                $out = $view->get_rendered_table_for_teacher($teacherid, false, false, false);

                $class = $firsttable ? 'active show' : '';
                $firsttable = false;

                $tablename = preg_replace("/[^a-z]/", '', $booking->settings->name);

                $newtable = [
                    'bookingid' => $bookingid,
                    'bookinginstancename' => $booking->settings->name,
                    'tablename' => $tablename,
                    'table' => $out,
                    'class' => $class,
                ];

                // This is a special setting for a special project. Only when this project is installed...
                // ... the set semester will get precedence over all the other ones.
                if (class_exists('local_musi\observer')
                    && ($booking->cmid == get_config('local_musi', 'shortcodessetinstance'))) {

                    // If there is already a table in our array, we make it inactive.
                    if (isset($teacheroptiontables[0])) {
                        $teacheroptiontables[0]['class'] = '';
                    }
                    $newtable['class'] = 'active show';
                    array_unshift($teacheroptiontables, $newtable);

                } else {

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
