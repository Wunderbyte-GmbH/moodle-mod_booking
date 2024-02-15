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
 * Shortcodes for mod_booking
 *
 * @package mod_booking
 * @subpackage db
 * @since Moodle 4.1
 * @copyright 2023 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use core_cohort\reportbuilder\local\entities\cohort;
use mod_booking\booking;
use mod_booking\output\view;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Deals with local_shortcodes regarding booking.
 */
class shortcodes {

    /**
     * This shortcode shows a list of booking options, which have a booking customfield...
     * ... with the shortname "recommendedin" and the value set to the shortname of the course...
     * ... in which they should appear.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function recommendedin($shortcode, $args, $content, $env, $next) {

        global $PAGE;

        $course = $PAGE->course;

        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            $perpage = 1000;
        }

        $pageurl = $course->shortname . $PAGE->url->out();

        $table = self::init_table_for_courses(null, md5($pageurl));

        $wherearray['recommendedin'] = "%$course->shortname%";

        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, null, [], $wherearray);

        // By default, we do not show booking options that lie in the past.
        // Shortcode arg values get transmitted as string, so also check for "false" and "0".
        if (empty($args['all']) || $args['all'] == "false" || $args['all'] == "0") {
            $now = time();
            $where .= " AND courseendtime > $now ";
        }

        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        if (empty($args['all'])) {
            $now = time();
            $where .= " coursestarttime > $now ";
        }

        // These are all possible options to be displayed in the bookingtable.
        $possibleoptions = [
            "description",
            "statusdescription",
            "teacher",
            "responsiblecontact",
            "showdates",
            "dayofweektime",
            "location",
            "institution",
            "minanswers",
            "bookingopeningtime",
            "bookingclosingtime",
        ];
        // When calling recommendedin in the frontend we can define exclude params to set options, we don't want to display.

        if (!empty($args['exclude'])) {
            $exclude = explode(',', $args['exclude']);
            $optionsfields = array_diff($possibleoptions, $exclude);
        } else {
            $optionsfields = $possibleoptions;
        }

        $defaultorder = SORT_ASC; // Default.
        if (!empty($args['sortorder'])) {
            if (strtolower($args['sortorder']) === "desc") {
                $defaultorder = SORT_DESC;
            }
        }
        if (!empty($args['sortby'])) {
            $table->sortable(true, $args['sortby'], $defaultorder);
        } else {
            $table->sortable(true, 'text', $defaultorder);
        }

        $showfilter = !empty($args['filter']) ? true : false;
        $showsort = !empty($args['sort']) ? true : false;
        $showsearch = !empty($args['search']) ? true : false;

        view::apply_standard_params_for_bookingtable(
            $table,
            $optionsfields,
            $showfilter,
            $showsearch,
            $showsort,
            false,
        );

        // If "rightside" is in the "exclude" array, then we do not show the rightside area (containing the "Book now" button).
        if (!empty($exclude) && in_array('rightside', $exclude)) {
            unset($table->subcolumns['rightside']);
        }

        $out = $table->outhtml($perpage, true);

        return $out;
    }

    /**
     * This shortcode covers a special case.
     * It shows all the booking options of a field of study...
     * ... regardless if they are in one or many booking instances.
     * The "field of study" has to be defined in the following way.
     * All courses of a study field use the cohort sync method with the same cohort.
     * The list depends on cohorts the user is subscribed to...
     * ... as well as on the course the user is looking at.
     * Only in course 1, all the booking options will be shown.
     * The setting of the course can be overriden via the argument course=5 etc.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function fieldofstudyoptions($shortcode, $args, $content, $env, $next) {

        global $COURSE, $USER, $DB, $CFG;

        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            $perpage = 1000;
        }

        // First: determine the cohort we want to use.
        // If not specified in the shortcode, we take the one the user is subscribed to.

        if (empty($args['group'])) {
            // We see in which the current user is subscribed in thecurrent course.

            $sql = "SELECT DISTINCT g.name, g.courseid
                    FROM {groups_members} gm
                    JOIN {groups} g
                    ON g.id = gm.groupid
                    WHERE userid = :userid
                    AND g.courseid = :course";
            $records = $DB->get_records_sql($sql, ['userid' => $USER->id, 'course' => $COURSE->id]);
            $groupnames = array_map(fn($a) => $a->name, $records);
            $courseids = array_map(fn($a) => $a->courseid, $records);

        } else {
            $courseids = $DB->get_fieldset_select('groups', 'courseid', 'name = :groupname', ['groupname' => $args['group']]);
        }

        if (empty($courseids)) {
            return get_string('definefieldofstudy', 'mod_booking');
        }

        list($inorequal, $params) = $DB->get_in_or_equal($groupnames);

        $sql = "SELECT c.id, c.shortname
                FROM {course} c
                JOIN {groups} g
                ON g.courseid = c.id
                WHERE g.name $inorequal";

        $courses = $DB->get_records_sql($sql, $params);

        $courseshortnames = array_map(fn($a) => "%$a->shortname%", $courses);

        // Second: Get the courses that are affected.
        // Third: Create the json to obtain the booking options.

        $table = self::init_table_for_courses(null, "courses_" . implode("_", $courseids));

        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, null, [], ['recommendedin' => $courseshortnames], null, null, '');

        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        // These are all possible options to be displayed in the bookingtable.
        $possibleoptions = [
            "description",
            "statusdescription",
            "teacher",
            "responsiblecontact",
            "showdates",
            "dayofweektime",
            "location",
            "institution",
            "minanswers",
            "bookingopeningtime",
            "bookingclosingtime",
        ];
        // When calling recommendedin in the frontend we can define exclude params to set options, we don't want to display.

        if (isset($args['exclude'])) {
            $exclude = explode(',', $args['exclude']);
            $optionsfields = array_diff($possibleoptions, $exclude);
        } else {
            $optionsfields = $possibleoptions;
        }

        view::apply_standard_params_for_bookingtable($table, $optionsfields, true, true, true);

        unset($table->subcolumns['rightside']);

        $out = $table->outhtml($perpage, true);

        return $out;
    }

    /**
     * This shortcode covers a special case.
     * It shows all the booking options of a field of study...
     * ... regardless if they are in one or many booking instances.
     * The "field of study" has to be defined in the following way.
     * All courses of a study field use the cohort sync method with the same cohort.
     * The list depends on cohorts the user is subscribed to...
     * ... as well as on the course the user is looking at.
     * Only in course 1, all the booking options will be shown.
     * The setting of the course can be overriden via the argument course=5 etc.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function fieldofstudycohortoptions($shortcode, $args, $content, $env, $next) {

        global $PAGE, $USER, $DB, $CFG;

        $supporteddbs = [
            'pgsql_native_moodle_database',
            'mariadb_native_moodle_database',
        ];

        if (!in_array(get_class($DB), $supporteddbs)) {
            return get_string('shortcodenotsupportedonyourdb', 'mod_booking');
        }

        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            $perpage = 1000;
        }

        // First: determine the cohort we want to use.
        // If not specified in the shortcode, we take the one the user is subscribed to.

        if (!empty($args['cohort'])) {
            $cohortids = $DB->get_field('cohort', 'id', ['name' => $args['cohort']]);
        }

        if (empty($cohortids)) {

            require_once($CFG->dirroot.'/cohort/lib.php');

            $cohorts = cohort_get_user_cohorts($USER->id);
            if (!empty($cohorts)) {
                $cohortids = array_map(fn($a) => $a->id, $cohorts);
            }
        }

        // If we still have no cohort specified, we will output a warning.
        if (empty($cohortids)) {
            return get_string('nofieldofstudyfound', 'mod_booking');
        }

        list ($inorequal, $params) = $DB->get_in_or_equal($cohortids);

        $sql = "SELECT e.courseid
                FROM {enrol} e
                WHERE e.customint1 $inorequal
                AND e.enrol = 'cohort'";

        $courses = $DB->get_fieldset_sql($sql, $params);

        // Second: Get the courses that are affected.
        // Third: Create the json to obtain the booking options.

        $table = self::init_table_for_courses(null, "courses_" . implode("_", $courses));

        $innerfrom = booking::get_sql_for_fieldofstudy(get_class($DB), $courses);

        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, null, [], [], null, null, '', $innerfrom);

        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        // These are all possible options to be displayed in the bookingtable.
        $possibleoptions = [
            "description",
            "statusdescription",
            "teacher",
            "responsiblecontact",
            "showdates",
            "dayofweektime",
            "location",
            "institution",
            "minanswers",
            "bookingopeningtime",
            "bookingclosingtime",
        ];
        // When calling recommendedin in the frontend we can define exclude params to set options, we don't want to display.

        if (isset($args['exclude'])) {
            $exclude = explode(',', $args['exclude']);
            $optionsfields = array_diff($possibleoptions, $exclude);
        } else {
            $optionsfields = $possibleoptions;
        }

        view::apply_standard_params_for_bookingtable($table, $optionsfields, true, true, true);

        unset($table->subcolumns['rightside']);

        $out = $table->outhtml($perpage, true);

        return $out;
    }

    /**
     * Base function for standard table configuration
     *
     * @param ?booking $booking
     * @param ?string $uniquetablename
     * @return bookingoptions_wbtable
     */
    private static function init_table_for_courses($booking = null, $uniquetablename = null) {

        $tablename = $uniquetablename ?? bin2hex(random_bytes(12));

        $table = new bookingoptions_wbtable($tablename);

        // Without defining sorting won't work!
        // phpcs:ignore
        //$table->define_columns(['titleprefix']);
        return $table;
    }
}
