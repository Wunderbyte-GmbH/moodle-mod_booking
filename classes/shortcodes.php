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

use cache_helper;
use core_cohort\reportbuilder\local\entities\cohort;
use Exception;
use html_writer;
use local_wunderbyte_table\filters\types\intrange;
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\customfield\booking_handler;
use mod_booking\local\modechecker;
use mod_booking\output\view;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\table\bulkoperations_table;
use mod_booking\utils\wb_payment;
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

        // If shortcodes are turned off, we return the shortcode as it is.
        if (get_config('booking', 'shortcodesoff')) {
            return "<div class='alert alert-warning'>" .
                get_string('shortcodesoffwarning', 'mod_booking', $shortcode) .
            "</div>";
        }

        $course = $PAGE->course;

        if (!wb_payment::pro_version_is_activated()) {
            return get_string('infotext:prolicensenecessary', 'mod_booking');
        }

        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            $perpage = 100;
        }

        $pageurl = $course->shortname . $PAGE->url->out();

        $table = self::init_table_for_courses(null, md5($pageurl));

        $wherearray['recommendedin'] = "$course->shortname";

        [$fields, $from, $where, $params, $filter] =
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
            "attachment",
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
    public static function courselist($shortcode, $args, $content, $env, $next) {

        global $PAGE;

        // If shortcodes are turned off, we return the shortcode as it is.
        if (get_config('booking', 'shortcodesoff')) {
            return "<div class='alert alert-warning'>" .
                get_string('shortcodesoffwarning', 'mod_booking', $shortcode) .
            "</div>";
        }

        $course = $PAGE->course;

        if (!wb_payment::pro_version_is_activated()) {
            return get_string('infotext:prolicensenecessary', 'mod_booking');
        }

        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            $perpage = 100;
        }

        $pageurl = $course->shortname . $PAGE->url->out();

        if (empty($args['cmid'])) {
            return get_string('definecmidforshortcode', 'mod_booking');
        }

        $viewparam = MOD_BOOKING_VIEW_PARAM_LIST; // Default value.
        if (isset($args['type'])) {
            switch ($args['type']) {
                // Cards are currently not yet supported in shortcode.
                // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                /*case 'cards':
                    $viewparam = MOD_BOOKING_VIEW_PARAM_CARDS;
                    break;*/
                case 'imageleft':
                    $viewparam = MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT;
                    break;
                case 'imageright':
                    $viewparam = MOD_BOOKING_VIEW_PARAM_LIST_IMG_RIGHT;
                    break;
                case 'imagelefthalf':
                    $viewparam = MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT_HALF;
                    break;
                case 'list':
                default:
                    $viewparam = MOD_BOOKING_VIEW_PARAM_LIST;
                    break;
            }
        }

        $booking = singleton_service::get_instance_of_booking_settings_by_cmid((int)$args['cmid']);

        if (empty($booking->id)) {
            return get_string('definecmidforshortcode', 'mod_booking');
        }

        $table = self::init_table_for_courses(null, md5($pageurl));

        $wherearray['bookingid'] = (int)$booking->id;

        // Additional where condition for both card and list views.
        $additionalwhere = self::set_wherearray_from_arguments($args, $wherearray) ?? '';

        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(
                    0,
                    0,
                    '',
                    null,
                    null,
                    [],
                    $wherearray,
                    null,
                    [MOD_BOOKING_STATUSPARAM_BOOKED],
                    $additionalwhere
                );

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
            "attachment",
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
            true,
            $viewparam
        );

        // Possibility to add customfieldfilter.
        $customfieldfilter = explode(',', ($args['customfieldfilter'] ?? ''));
        if (!empty($customfieldfilter)) {
            self::apply_customfieldfilter($table, $customfieldfilter);
        }

        $table->showcountlabel = $showfilter ? true : false;

        if (
            isset($args['filterontop'])
            && (
                $args['filterontop'] == '1'
                || $args['filterontop'] == 'true'
            )
        ) {
            $table->showfilterontop = true;
        } else {
            $table->showfilterontop = false;
        }

        // If "rightside" is in the "exclude" array, then we do not show the rightside area (containing the "Book now" button).
        if (!empty($exclude) && in_array('rightside', $exclude)) {
            unset($table->subcolumns['rightside']);
        }

        $out = $table->outhtml($perpage, true);

        return $out;
    }

    /**
     * Add customfield filter as defined shortnames in args to table.
     *
     * @param mixed $table
     * @param array $args
     *
     * @return void
     *
     */
    private static function apply_customfieldfilter(&$table, $args) {
        if (empty($args)) {
            return;
        }
        $customfields = booking_handler::get_customfields();
        if (empty($customfields)) {
            return;
        }
        foreach ($customfields as $customfield) {
            if (!isset($customfield->shortname)) {
                continue;
            }
            if (!in_array($customfield->shortname, $args)) {
                continue;
            }
            // Check for multi fields, explode values as settings for standardfilter.
            $standardfilter = new standardfilter($customfield->shortname, format_string($customfield->name));
            $table->add_filter($standardfilter);
        }
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

        // If shortcodes are turned off, we return the shortcode as it is.
        if (get_config('booking', 'shortcodesoff')) {
            return "<div class='alert alert-warning'>" .
                get_string('shortcodesoffwarning', 'mod_booking', $shortcode) .
            "</div>";
        }

        if (!wb_payment::pro_version_is_activated()) {
            return get_string('infotext:prolicensenecessary', 'mod_booking');
        }

        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            $perpage = 100;
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

        [$inorequal, $params] = $DB->get_in_or_equal($groupnames);

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

        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', null, null, [], ['recommendedin' => $courseshortnames], null, [], '');

        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        // These are all possible options to be displayed in the bookingtable.
        $possibleoptions = [
            "description",
            "statusdescription",
            "attachment",
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

        // If "rightside" is in the "exclude" array, then we do not show the rightside area (containing the "Book now" button).
        if (!empty($exclude) && in_array('rightside', $exclude)) {
            unset($table->subcolumns['rightside']);
        }

        $table->sort_default_column = 'coursestarttime';
        $table->sort_default_order = SORT_ASC;

        $out = $table->outhtml($perpage, true);

        return $out;
    }

    /**
     * A small shortcode to add links to the booking options which link to this course.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function linkbacktocourse($shortcode, $args, $content, $env, $next) {

        global $COURSE, $USER, $DB, $CFG, $PAGE;

        // If shortcodes are turned off, we return the shortcode as it is.
        if (get_config('booking', 'shortcodesoff')) {
            return "<div class='alert alert-warning'>" .
                get_string('shortcodesoffwarning', 'mod_booking', $shortcode) .
            "</div>";
        }

        if (!wb_payment::pro_version_is_activated()) {
            return get_string('infotext:prolicensenecessary', 'mod_booking');
        }

        $out = '';

        $wherearray = ['courseid' => (int)$COURSE->id];

        // Even though this is huge and fetches way to much data, we still use it as it will take care of invisible options etc.
        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', null, null, [], $wherearray);

        $optionids = $DB->get_records_sql(" SELECT $fields FROM $from WHERE $where", $params);

        foreach ($optionids as $option) {
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

            if (!modechecker::is_ajax_or_webservice_request()) {
                $returnurl = $PAGE->url->out();
            } else {
                $returnurl = '/';
            }

            // The current page is not /mod/booking/optionview.php.
            $url = new moodle_url("/mod/booking/optionview.php", [
                "optionid" => (int)$settings->id,
                "cmid" => (int)$settings->cmid,
                "userid" => $USER->id,
                'returnto' => 'url',
                'returnurl' => $returnurl,
            ]);
            $out .= html_writer::tag(
                'a',
                $settings->get_title_with_prefix(),
                [
                    'class' => 'mod-booking-linkbacktocourse btn btn-nolabel',
                    'href' => $url->out(false),
                ]
            );
        }
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

        // If shortcodes are turned off, we return the shortcode as it is.
        if (get_config('booking', 'shortcodesoff')) {
            return "<div class='alert alert-warning'>" .
                get_string('shortcodesoffwarning', 'mod_booking', $shortcode) .
            "</div>";
        }

        if (!wb_payment::pro_version_is_activated()) {
            return get_string('infotext:prolicensenecessary', 'mod_booking');
        }

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
            $perpage = 100;
        }

        // First: determine the cohort we want to use.
        // If not specified in the shortcode, we take the one the user is subscribed to.

        if (!empty($args['cohort'])) {
            $cohortids = $DB->get_field('cohort', 'id', ['name' => $args['cohort']]);
        }

        if (empty($cohortids)) {
            require_once($CFG->dirroot . '/cohort/lib.php');

            $cohorts = cohort_get_user_cohorts($USER->id);
            if (!empty($cohorts)) {
                $cohortids = array_map(fn($a) => $a->id, $cohorts);
            }
        }

        // If we still have no cohort specified, we will output a warning.
        if (empty($cohortids)) {
            return get_string('nofieldofstudyfound', 'mod_booking');
        }

         [$inorequal, $params] = $DB->get_in_or_equal($cohortids);

        $sql = "SELECT e.courseid
                FROM {enrol} e
                WHERE e.customint1 $inorequal
                AND e.enrol = 'cohort'";

        $courses = $DB->get_fieldset_sql($sql, $params);

        // Second: Get the courses that are affected.
        // Third: Create the json to obtain the booking options.

        $table = self::init_table_for_courses(null, "courses_" . implode("_", $courses));

        $innerfrom = booking::get_sql_for_fieldofstudy(get_class($DB), $courses);

        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', null, null, [], [], null, null, '', $innerfrom);

        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        // These are all possible options to be displayed in the bookingtable.
        $possibleoptions = [
            "description",
            "statusdescription",
            "attachment",
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
     * List bookingoptions with checkboxes and buttons to trigger bulkoperations.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function bulkoperations($shortcode, $args, $content, $env, $next): string {

        global $PAGE;

        // If shortcodes are turned off, we return the shortcode as it is.
        if (get_config('booking', 'shortcodesoff')) {
            return "<div class='alert alert-warning'>" .
                get_string('shortcodesoffwarning', 'mod_booking', $shortcode) .
            "</div>";
        }

        if (!is_siteadmin()) {
            return get_string('nopermissiontoaccesscontent', 'mod_booking');
        }

        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            $perpage = 100;
        }

        cache_helper::purge_by_event('changesinwunderbytetable');

        $table = new bulkoperations_table(bin2hex(random_bytes(8)) . '_optionbulkoperationstable');
        $columns = [
            'id' => get_string('id', 'local_wunderbyte_table'),
            'text' => get_string('title', 'mod_booking'),
            'action' => get_string('edit'),
        ];
        // Add defined customfields from args to columns.
        if (isset($args['customfields'])) {
            $customfieldnames = explode(",", $args['customfields']);
            $definedcustomfields = booking_handler::get_customfields();
            foreach ($definedcustomfields as $customfield) {
                if (!in_array($customfield->shortname, $customfieldnames)) {
                    continue;
                }
                $columns[$customfield->shortname] = $customfield->name;
            }
        }

        if (isset($args['columns'])) {
            $additionalcolumns = explode(",", $args['columns']);
            foreach ($additionalcolumns as $additionalcolumn) {
                if (in_array($additionalcolumn, $columns)) {
                    continue;
                }
                $columns[$additionalcolumn] = $additionalcolumn;
            }
        }

        if (!empty($args['download'])) {
            $table->showdownloadbutton = true;
        }

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));
        $table->addcheckboxes = true;

        try {
            $filtercolumns = self::apply_bulkoperations_filter($table, $columns, $args);
        } catch (Exception $e) {
            return '<div class="alert alert-danger p-1 mt-1 text-center">' . $e->getMessage() . '</div>';
        }

        $table->showfilterontop = true;
        $table->filteronloadinactive = true;

        $table->define_fulltextsearchcolumns(array_keys($filtercolumns));
        $table->define_sortablecolumns(array_keys($filtercolumns));
        $table->sort_default_column = 'id';
        $table->sort_default_order = SORT_DESC;

        // Templates are excluded here.
        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', null, null, [], [], null, [], ' bookingid > 0');

        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        $table->actionbuttons[] = [
            'label' => get_string('editbookingoptions', 'mod_booking'),
            'class' => 'btn btn-warning',
            'href' => '#',
            'formname' => 'mod_booking\\form\\option_form_bulk',
            'nomodal' => false,
            'selectionmandatory' => true,
            'id' => '-1',
            'data' => [
                'title' => get_string('bulkoperationsheader', 'mod_booking'),
            ],
        ];
        $table->actionbuttons[] = [
            'label' => get_string('sendmailtoteachers', 'mod_booking'),
            'class' => 'btn btn-info',
            'href' => '#',
            'formname' => 'mod_booking\\form\\send_mail_to_teachers',
            'nomodal' => false,
            'selectionmandatory' => true,
            'id' => '-1',
            'data' => [
                'title' => get_string('sendmailheading', 'mod_booking'),
                'titlestring' => 'blabla',
                'bodystring' => 'adddatabody',
                'submitbuttonstring' => get_string('send', 'mod_booking'),
            ],
        ];
        $table->pageable(true);
        $table->stickyheader = true;
        $table->showcountlabel = true;
        $table->showrowcountselect = true;

        $out = $table->outhtml($perpage, true);

        return $out;
    }

    /**
     * Modifies table and returns filtercolumns
     *
     * @param wunderbyte_table $table
     * @param array $columns
     * @param array $args
     *
     * @return array
     *
     */
    private static function apply_bulkoperations_filter(wunderbyte_table &$table, array $columns, array $args) {

        // Add defined intrange filter. You might need to purge your caches to make this work.
        if (isset($args['intrangefilter'])) {
            $intrangecolumns = explode(",", $args['intrangefilter']);
            foreach ($intrangecolumns as $colname) {
                if (get_string_manager()->string_exists($colname, 'mod_booking')) {
                    $localizedstring = get_string($colname, 'mod_booking');
                } else {
                    $localizedstring = "";
                }
                $intrangefilter = new intrange($colname, $localizedstring);
                $table->add_filter($intrangefilter);
                // Since columns are used as base for filter we need to remove the intrange columns.
                if (isset($columns[$colname])) {
                    unset($columns[$colname]);
                }
            }
        }

        // Exclude column action from columns for filter, sorting, search.
        $filtercolumns = array_diff_key($columns, array_flip(['action']));
        foreach ($filtercolumns as $key => $localized) {
            $standardfilter = new standardfilter($key, $localized);
            $table->add_filter($standardfilter);
        }

        self::apply_bookinginstance_filter($table);

        $customfieldfilter = explode(',', ($args['customfieldfilter'] ?? ''));
        if (!empty($customfieldfilter)) {
            self::apply_customfieldfilter($table, $customfieldfilter);
        }
        return $filtercolumns;
    }
    /**
     * Base function for standard table configuration
     *
     * @param ?booking $booking
     * @param ?string $uniquetablename
     * @return bookingoptions_wbtable
     */
    private static function init_table_for_courses(?booking $booking = null, ?string $uniquetablename = null) {

        $tablename = $uniquetablename ?? bin2hex(random_bytes(12));

        $table = new bookingoptions_wbtable($tablename);

        // Without defining sorting won't work!
        // phpcs:ignore
        //$table->define_columns(['titleprefix']);
        return $table;
    }

    /**
     * Add filter displaying the possible instances of mod booking.
     *
     * @param mixed $table reference to table
     *
     * @return void
     *
     */
    private static function apply_bookinginstance_filter(&$table) {
        $bookinginstances = singleton_service::get_all_booking_instances();

        $filterarray = [];
        foreach ($bookinginstances as $b) {
            $filterarray[$b->id] = $b->name . " (ID: $b->id)";
        }

        $instancefilter = new standardfilter('bookingid', get_string('bookingidfilter', 'mod_booking'));
        $instancefilter->add_options($filterarray);
        $table->add_filter($instancefilter);
    }

    /**
     * Modify there wherearray via arguments.
     *
     * @param array $args reference to args
     * @param array $wherearray reference to wherearray
     * @return string
     */
    private static function set_wherearray_from_arguments(array &$args, array &$wherearray) {

        global $DB;

        $customfields = booking_handler::get_customfields();
        // Set given customfields (shortnames) as arguments.
        $fields = [];
        $additonalwhere = '';
        if (!empty($customfields) && !empty($args)) {
            foreach ($args as $key => $value) {
                foreach ($customfields as $customfield) {
                    if ($customfield->shortname == $key) {
                        $configdata = json_decode($customfield->configdata ?? '[]');

                        if (!empty($configdata->multiselect)) {
                            if (!empty($additonalwhere)) {
                                $additonalwhere .= " AND ";
                            }

                            $values = explode(',', $value);

                            if (!empty($values)) {
                                $additonalwhere .= " ( ";
                            }

                            foreach ($values as $vkey => $vvalue) {
                                $additonalwhere .= $vkey > 0 ? ' OR ' : '';
                                $vvalue = "'%$vvalue%'";
                                $additonalwhere .= " $key LIKE $vvalue ";
                            }

                            if (!empty($values)) {
                                $additonalwhere .= " ) ";
                            }
                        } else {
                            $argument = strip_tags($value);
                            $argument = trim($argument);
                            $wherearray[$key] = $argument;
                        }

                        break;
                    }
                }
            }
        }

        return $additonalwhere;
    }
}
