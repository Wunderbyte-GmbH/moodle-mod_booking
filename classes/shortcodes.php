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
use context_module;
use context_system;
use Exception;
use html_writer;
use local_wunderbyte_table\filters\types\datepicker;
use local_wunderbyte_table\filters\types\intrange;
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\local\shortcode_filterfield;
use mod_booking\shortcodes_handler;
use mod_booking\customfield\booking_handler;
use mod_booking\local\modechecker;
use mod_booking\output\view;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\table\bulkoperations_table;
use moodle_url;
use Throwable;

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

        global $PAGE, $CFG;
        $requiredargs = [];
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if ($error['error'] === 1) {
            return $error['message'];
        }

        $course = $PAGE->course;
        $perpage = self::check_perpage($args);
        $pageurl = $course->shortname . $PAGE->url->out();
        $table = self::init_table_for_courses(null, md5($pageurl));

        $additionalwhere = " (recommendedin = '$course->shortname'
                            OR recommendedin LIKE '$course->shortname,%'
                            OR recommendedin LIKE '%,$course->shortname'
                            OR recommendedin LIKE '%,$course->shortname,%') ";

        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', null, null, [], [], null, [], $additionalwhere);

        self::applyallarg($args, $where);

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

        if (!empty($args['exclude'])) {
            $exclude = explode(',', $args['exclude']);
            $optionsfields = array_diff($possibleoptions, $exclude);
        } else {
            $optionsfields = $possibleoptions;
        }

        // Set common table options requirelogin, sortorder, sortby.
        self::set_common_table_options_from_arguments($table, $args);

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

        try {
            $out = $table->outhtml($perpage, true);
        } catch (Throwable $e) {
            $out = get_string('shortcode:error', 'mod_booking');

            if ($CFG->debug > 0 && has_capability('moodle/site:config', context_system::instance())) {
                $out .= $e->getMessage();
            }
        }

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

        global $PAGE, $CFG;
        $requiredargs = ['cmid'];
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if ($error['error'] === 1) {
            return $error['message'];
        }
        $course = $PAGE->course;
        $perpage = self::check_perpage($args);
        $pageurl = isset($PAGE->url) ? $PAGE->url->out() : ''; // This is for unit tests.
        $pageurl = $course->shortname . $pageurl;
        $viewparam = self::get_viewparam($args);

        try {
            $booking = singleton_service::get_instance_of_booking_settings_by_cmid((int)$args['cmid']);
        } catch (Throwable $e) {
            return get_string('shortcode:cmidnotexisting', 'mod_booking', $args['cmid']);
        }

        if (empty($booking->id)) {
            return get_string('definecmidforshortcode', 'mod_booking');
        }

        $table = self::init_table_for_courses(null, md5($pageurl));

        $wherearray['bookingid'] = (int)$booking->id;

        $columnfilters = self::get_columnfilters($args);
        // Additional where condition for both card and list views.
        $foo = [];
        $additionalwhere = self::set_customfield_wherearray($args, $wherearray, $foo, $columnfilters) ?? '';

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

        self::applyallarg($args, $where);

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
            "competencies",
        ];
        // When calling recommendedin in the frontend we can define exclude params to set options, we don't want to display.

        if (!empty($args['exclude'])) {
            $exclude = explode(',', $args['exclude']);
            $optionsfields = array_diff($possibleoptions, $exclude);
        } else {
            $optionsfields = $possibleoptions;
        }

        // Set common table options requirelogin, sortorder, sortby.
        self::set_common_table_options_from_arguments($table, $args);

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

        try {
            $out = $table->outhtml($perpage, true);
        } catch (Throwable $e) {
            $out = get_string('shortcode:error', 'mod_booking');

            if ($CFG->debug > 0 && has_capability('moodle/site:config', context_system::instance())) {
                $out .= $e->getMessage();
            }
        }

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
     * Create potential columnfilters to be handled along with customfield filters.
     * Each desired column filter should be defined as an argument in the shortcode like: bofilter_columnname=value.
     * For example columnfilter_prefix=123.
     *
     * @param array $args
     * @param bool $verify
     *
     * @return array
     *
     */
    private static function get_columnfilters($args, $verify = true): array {
        if (empty($args)) {
            return [];
        }

        // Make sure to add the column you want to filter to the accepted filters.
        $acceptedfilters = [
            'competencies' => ['multiple' => true],
        ];
        $returnfilters = [];
        foreach ($args as $key => $value) {
            // Match keys like "columnfilter_firstname".
            if (strpos($key, 'columnfilter_') === 0) {
                $shortname = substr($key, strlen('columnfilter_'));

                // Check if this shortname is accepted.
                if (array_key_exists($shortname, $acceptedfilters)) {
                    $multiple = !empty($acceptedfilters[$shortname]['multiple']);

                    // Create instance of shortcode_filterfield.
                    $filter = new shortcode_filterfield($shortname, $multiple);

                    // Since the verification includes a DB call, it can also be turned off.
                    if ($verify && !$filter->verify_field()) {
                        continue;
                    }
                    $returnfilters[] = $filter;
                }
            }
        }
        return $returnfilters;
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
        $requiredargs = [];
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if (($error['error'] === 1)) {
            return $error['message'];
        }
        $perpage = self::check_perpage($args);

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

        $sql = "SELECT DISTINCT c.id, c.shortname
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

        // Set common table options requirelogin, sortorder, sortby.
        self::set_common_table_options_from_arguments($table, $args);

        // If "rightside" is in the "exclude" array, then we do not show the rightside area (containing the "Book now" button).
        if (!empty($exclude) && in_array('rightside', $exclude)) {
            unset($table->subcolumns['rightside']);
        }

        $table->sort_default_column = 'coursestarttime';
        $table->sort_default_order = SORT_ASC;

        try {
            $out = $table->outhtml($perpage, true);
        } catch (Throwable $e) {
            $out = get_string('shortcode:error', 'mod_booking');

            if ($CFG->debug > 0 && has_capability('moodle/site:config', context_system::instance())) {
                $out .= $e->getMessage();
            }
        }

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
        $requiredargs = [];
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if ($error['error'] === 1) {
            return $error['message'];
        }
        $out = '';
        $optionids = $DB->get_records(
            'booking_options',
            ['courseid' => $COURSE->id]
        );

        foreach ($optionids as $option) {
            // Only if the user has the right to see the link back, we show it.
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

            if ($option->invisible == 1) {
                $context = context_module::instance($settings->cmid);
                if (!has_capability('mod/booking:view', $context)) {
                    continue;
                }
            }

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
     * Shortcode to show all Booking Options.
     *
     * @param mixed $shortcode
     * @param mixed $args
     * @param mixed $content
     * @param mixed $env
     * @param mixed $next
     *
     * @return string $out
     *
     */
    public static function allbookingoptions($shortcode, $args, $content, $env, $next) {
        global $PAGE, $DB, $CFG;
        $requiredargs = [];
        $operator = 'AND';
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if ($error['error'] === 1) {
            return $error['message'];
        }

        $course = $PAGE->course;
        $perpage = self::check_perpage($args);
        $pageurl = isset($PAGE->url) ? $PAGE->url->out() : ''; // This is for unit tests.
        $pageurl = $course->shortname . $pageurl;
        $viewparam = self::get_viewparam($args);
        $wherearray = [];

        $table = self::init_table_for_courses(null, md5($pageurl));
        $additionalparams = [];

        // Additional where condition for both card and list views.
        $tempparams = [];
        $tempwherearray = [];
        if (!empty($args['cfinclude'])) {
            $operator = "OR";
        }
        $columnfilters = self::get_columnfilters($args);
        // Additional where condition for both card and list views.
        $additionalwhere = self::set_customfield_wherearray($args, $wherearray, $tempparams, $columnfilters) ?? '';

        if ($cfwhere = self::set_customfield_wherearray($args, $wherearray, $tempparams, $columnfilters) ?? '') {
            $tempwherearray[] = $cfwhere;
        }
        $cmidsfromcourse = [];
        if (isset($args['courseid'])) {
            $courseid = $args['courseid'];
            try {
                $modinfo = get_fast_modinfo($courseid);
                if (isset($modinfo->instances['booking'])) {
                    foreach ($modinfo->instances['booking'] as $bookinginstance) {
                        $cmidsfromcourse[] = $bookinginstance->id;
                    }
                }
            } catch (Throwable $e) {
                return get_string('shortcode:courseidnotexisting', 'mod_booking', $args['courseid']);
            }
        }

        try {
            if ($cmidwhere = self::set_cmid_wherearray($args, $wherearray, $tempparams, $cmidsfromcourse)) {
                $tempwherearray[] = $cmidwhere;
            }
        } catch (Throwable $e) {
            return get_string('shortcode:cmidnotexisting', 'mod_booking', $args['cmid']);
        }

        if (!empty($tempwherearray)) {
            $additionalwhere = " ( " . implode(" $operator ", $tempwherearray) . " ) ";
        } else {
            $additionalwhere = ''; // Or null, or '1=1', depending on how your SQL logic handles empty conditions.
        }

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
                    $additionalwhere,
                    ""
                );

                $params = array_merge($tempparams, $params);
        self::applyallarg($args, $where);

        if (!empty($additionalparams)) {
            foreach ($additionalparams as $key => $value) {
                $params[$key] = $value;
            }
        }
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

        if (!empty($args['exclude'])) {
            $exclude = explode(',', $args['exclude']);
            $optionsfields = array_diff($possibleoptions, $exclude);
        } else {
            $optionsfields = $possibleoptions;
        }

        // Set common table options requirelogin, sortorder, sortby.
        self::set_common_table_options_from_arguments($table, $args);

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

        try {
            $out = $table->outhtml($perpage, true);
        } catch (Throwable $e) {
            $out = get_string('shortcode:error', 'mod_booking');

            if ($CFG->debug > 0 && has_capability('moodle/site:config', context_system::instance())) {
                $out .= $e->getMessage();
                $out .= $e->getTraceAsString();
            }
        }

        return $out;
    }

    /**
     * Shortcode to show your booked Bookingoptions.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function mycourselist($shortcode, $args, $content, $env, $next) {
        global $USER, $PAGE, $CFG;
        $requiredargs = [];
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if ($error['error'] === 1) {
            return $error['message'];
        }

        $userid = $USER->id;
        self::fix_args($args);
        $wherearray = [];
        $course = $PAGE->course;
        $perpage = self::check_perpage($args);
        $pageurl = $course->shortname . $PAGE->url->out();
        $perpage = self::check_perpage($args);

        if (!empty($args['cmid'])) {
            $booking = singleton_service::get_instance_of_booking_settings_by_cmid((int)$args['cmid']);
            $wherearray['bookingid'] = (int)$booking->id;
        }

        $viewparam = self::get_viewparam($args);
        $table = self::init_table_for_courses(null, md5($pageurl));

        // Additional where condition for both card and list views.
        $additionalwhere = self::set_customfield_wherearray($args, $wherearray) ?? '';

        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(
                    0,
                    0,
                    '',
                    null,
                    null,
                    [],
                    $wherearray,
                    $userid,
                    [MOD_BOOKING_STATUSPARAM_BOOKED],
                    $additionalwhere
                );

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

        if (!empty($args['exclude'])) {
            $exclude = explode(',', $args['exclude']);
            $optionsfields = array_diff($possibleoptions, $exclude);
        } else {
            $optionsfields = $possibleoptions;
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
            $viewparam,
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

        // Set common table options requirelogin, sortorder, sortby.
        self::set_common_table_options_from_arguments($table, $args);

        $table->define_cache('mod_booking', 'mybookingoptionstable');

        try {
            $out = $table->outhtml($perpage, true);
        } catch (Throwable $e) {
            $out = get_string('shortcode:error', 'mod_booking');

            if ($CFG->debug > 0 && has_capability('moodle/site:config', context_system::instance())) {
                $out .= $e->getMessage();
            }
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
        $requiredargs = [];
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if ($error['error'] === 1) {
            return $error['message'];
        }

        $supporteddbs = [
        'pgsql_native_moodle_database',
        'mariadb_native_moodle_database',
        ];

        if (!in_array(get_class($DB), $supporteddbs)) {
            return get_string('shortcodenotsupportedonyourdb', 'mod_booking');
        }

        $perpage = self::check_perpage($args);

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
                booking::get_options_filter_sql(
                    0,
                    0,
                    '',
                    null,
                    null,
                    [],
                    [],
                    null,
                    [MOD_BOOKING_STATUSPARAM_BOOKED],
                    '',
                    $innerfrom
                );

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

        // Set common table options requirelogin, sortorder, sortby.
        self::set_common_table_options_from_arguments($table, $args);

        unset($table->subcolumns['rightside']);

        try {
            $out = $table->outhtml($perpage, true);
        } catch (Throwable $e) {
            $out = get_string('shortcode:error', 'mod_booking');

            if ($CFG->debug > 0 && has_capability('moodle/site:config', context_system::instance())) {
                $out .= $e->getMessage();
            }
        }

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

        global $PAGE, $CFG;
        $requiredargs = [];
        $error = shortcodes_handler::validatecondition($shortcode, $args, true, $requiredargs);
        if ($error['error'] === 1) {
            return $error['message'];
        }

        if (!is_siteadmin() && !has_capability('mod/booking:executebulkoperations', context_system::instance())) {
            return get_string('nopermissiontoaccesscontent', 'mod_booking');
        }

        $perpage = self::check_perpage($args);

        cache_helper::purge_by_event('changesinwunderbytetable');
        // Add the arguments to make sure cache is built correctly.
        $argsstring = bin2hex(implode($args));
        $table = new bulkoperations_table(bin2hex(random_bytes(8)) . '_optionbulkoperationstable_' . $argsstring);
        $columns = [
        'id' => get_string('id', 'local_wunderbyte_table'),
        'text' => get_string('title', 'mod_booking'),
        'action' => get_string('edit'),
        'invisible' => get_string('invisible', 'mod_booking'),
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

        $context = context_system::instance();
        // Templates are excluded here.
        [$fields, $from, $where, $params, $filter] =
            booking::get_options_filter_sql(0, 0, '', null, $context, [], [], null, [], ' bookingid > 0');

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

        try {
            $out = $table->outhtml($perpage, true);
        } catch (Throwable $e) {
            $out = get_string('shortcode:error', 'mod_booking');

            if ($CFG->debug > 0 && has_capability('moodle/site:config', context_system::instance())) {
                $out .= $e->getMessage();
            }
        }

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
        $datepickerfiltercolumns = ['coursestarttime', 'courseendtime', 'bookingopeningtime'];
        // Exclude column action from columns for filter, sorting, search.
        $filtercolumns = array_diff_key($columns, array_flip(['action']));

        if (isset($args['filter'])) {
            $filterargs = explode(",", $args['filter']);
            $stringmanager = get_string_manager();
            foreach ($filterargs as $key => $colname) {
                // Check if it's an intrangefilter.
                if (in_array($colname, $datepickerfiltercolumns)) {
                    if ($stringmanager->string_exists($colname, 'mod_booking')) {
                        $localizedstring = get_string($colname, 'mod_booking');
                    } else {
                        $localizedstring = "";
                    }
                    $datepicker = new datepicker($colname, $localizedstring);
                    $datepicker->add_options(
                        'in between',
                        '<',
                        get_string('apply_filter', 'local_wunderbyte_table'),
                        'now',
                        'now + 1 year'
                    );
                    $table->add_filter($datepicker);
                    unset($filterargs[$key]);
                } else {
                    // Prepare standardfilter.
                    $localized = $stringmanager->string_exists($colname, 'mod_booking')
                    ? get_string($colname, 'mod_booking') : $colname;
                    $filtercolumns[$colname] = $localized;
                }
            }
        }
        foreach ($filtercolumns as $colname => $localized) {
            $standardfilter = new standardfilter($colname, $localized);
            if ($colname === 'invisible') {
                $standardfilter->add_options([
                "0" => get_string('optionvisible', 'mod_booking'),
                "1" => get_string('optioninvisible', 'mod_booking'),
                "2" => get_string('optionvisibledirectlink', 'mod_booking'),
                ]);
            }
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
     * Setting options from shortcodes arguments common for all children of wunderbyte_table .
     *
     * @param wunderbyte_table $table reference to table
     * @param array $args
     *
     * @return void
     *
     */
    public static function set_common_table_options_from_arguments(&$table, $args): void {
        $defaultorder = SORT_ASC; // Default.
        if (!empty($args['sortorder'])) {
            if (strtolower($args['sortorder']) === "desc") {
                $defaultorder = SORT_DESC;
            }
        }
        if (!empty($args['sortby'])) {
            if (
                !isset($table->columns[$args['sortby']])
            ) {
                $table->define_columns([$args['sortby']]);
            }
            $table->sortable(true, $args['sortby'], $defaultorder);
        } else {
            $table->sortable(true, 'text', $defaultorder);
        }
        if (isset($args['requirelogin']) && $args['requirelogin'] == "false") {
            $table->requirelogin = false;
        }
    }
    /**
     * Checking Perpage Argument from Shortcode for all children of wunderbyte_table .
     *
     * @param array $args
     *
     * @return int $perapage
     *
     */
    public static function check_perpage($args) {
        if (
            !isset($args['perpage'])
            || !is_int((int)$args['perpage'])
            || !$perpage = ($args['perpage'])
        ) {
            return $perpage = 100;
        }
        return $perpage = (int)$args['perpage'];
    }

    /**
     * Modify there wherearray via arguments.
     *
     * @param array $args reference to args
     * @param array $wherearray reference to wherearray
     * @param array $tempparamsarray
     * @param array $columnfilters
     * @return string
     */
    private static function set_customfield_wherearray(
        array &$args,
        array &$wherearray,
        array &$tempparamsarray = [],
        array $columnfilters = []
    ) {

        global $DB;
        $customfields = booking_handler::get_customfields();
        $filterfields = array_merge($customfields, $columnfilters);

        // Set given customfields (shortnames) as arguments.
        $additionalwhere = '';
        if (!empty($filterfields) && !empty($args)) {
            foreach ($args as $key => $value) {
                foreach ($filterfields as $customfield) {
                    if (
                        $customfield->shortname == $key
                        || 'columnfilter_' . $customfield->shortname == $key
                    ) {
                        $configdata = json_decode($customfield->configdata ?? '[]');

                        if (
                            !empty($configdata->multiselect)
                            || (isset($customfield->type) && $customfield->type == 'multiselect')
                        ) {
                            if (!empty($additionalwhere)) {
                                $additionalwhere .= " AND ";
                            }

                            $values = explode(',', $value);

                            if (!empty($values)) {
                                $additionalwhere .= " ( ";
                            }

                            foreach ($values as $vkey => $vvalue) {
                                $additionalwhere .= $vkey > 0 ? ' OR ' : '';
                                $vvalue = "'%$vvalue%'";
                                $additionalwhere .= " $customfield->shortname LIKE $vvalue ";
                            }

                            if (!empty($values)) {
                                $additionalwhere .= " ) ";
                            }
                        } else {
                            $argument = strip_tags($value);
                            $argument = trim($argument);
                            if (
                                !empty($args['cfinclude'])
                            ) {
                                $additionalwhere .= !empty($additionalwhere) ? '' : ' 1 = 1';
                                $tempwherearray = [$customfield->shortname => $argument];
                                booking::apply_wherearray($additionalwhere, $tempwherearray, $tempparamsarray, 1000);
                            } else {
                                $wherearray[$customfield->shortname] = $argument;
                            }
                        }
                        break;
                    }
                }
            }
        }
        if (!empty($additionalwhere)) {
            $additionalwhere = " ( $additionalwhere ) ";
        }

        return $additionalwhere;
    }

    /**
     * Create Outerwherearray to contain all CMID's.
     *
     * @param array $args
     * @param array $wherearray
     * @param array $params
     * @param array $cmidsfromcourse
     *
     * @return string
     *
     */
    private static function set_cmid_wherearray(
        array &$args,
        array &$wherearray,
        array &$params = [],
        array $cmidsfromcourse = []
    ) {
        global $DB;
        if (empty($args['cmid']) && !empty($args['id'])) {
            $args['cmid'] = $args['id'];
        }

        if (!empty($args['cmid']) && !empty($cmidsfromcourse)) {
            $cmids = explode(',', $args['cmid'] ?? '');
            $cmidsfromcourse = array_merge($cmids, array_values($cmidsfromcourse));
            $args['cmid'] = implode(',', $cmidsfromcourse);
        } else if (!empty($cmidsfromcourse)) {
            $args['cmid'] = implode(',', $cmidsfromcourse);
        }

        $additionalwhere = "";
        if (!empty($args['cmid'])) {
            $bookings = [];
            $cmids = array_map('intval', explode(',', $args['cmid']));
            foreach ($cmids as $cmid) {
                $booking = singleton_service::get_instance_of_booking_settings_by_cmid((int)$cmid);
                if (isset($booking->id)) {
                    $bookings[] = $booking->id;
                }
            }
                [$inorequal, $tempparams] = $DB->get_in_or_equal($bookings, SQL_PARAMS_NAMED);
                $additionalwhere = " (bookingid $inorequal) ";
                $params = array_merge($tempparams, $params ?? []);
        }
        if (empty($additionalwhere)) {
            $additionalwhere = " ( 1=1 ) ";
        }
        return $additionalwhere;
    }

    /**
     * Helper function to remove quotation marks from args.
     *
     * @param array $args reference to arguments array
     *
     * @return void
     */
    private static function fix_args(array &$args): void {
        foreach ($args as $key => &$value) {
            // Get rid of quotation marks.
            $value = str_replace('"', '', $value);
            $value = str_replace("'", "", $value);
        }
    }

    /**
     * Helperfunction to get the Viewparam.
     *
     * @param array $args
     *
     * @return int $viewparam if no viewparam is found, the default is MOD_BOOKING_VIEW_PARAM_LIST
     *
     */
    private static function get_viewparam($args) {
        // Default is list.
        $viewparam = MOD_BOOKING_VIEW_PARAM_LIST;
        if (!isset($args['type'])) {
            return $viewparam;
        }
        switch ($args['type']) {
            case 'cards':
                $viewparam = MOD_BOOKING_VIEW_PARAM_CARDS;
                break;
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
                break;
        }
        return $viewparam;
    }

    /**
     * By default, we do not show booking options that lie in the past.
     * Shortcode arg values get transmitted as string, so also check for "false" and "0".
     * And apply setting for selflearningcourse.
     *
     * @param mixed $args
     * @param mixed $where
     *
     * @return void
     *
     */
    private static function applyallarg($args, &$where) {
        if (empty($args['all']) || $args['all'] == "false" || $args['all'] == "0") {
            $startoftoday = strtotime('today'); // Will be 00:00:00 of the current day.
            $selflearncoursesetting = get_config('booking', 'selflearningcoursedisplayinshortcode');
            switch ($selflearncoursesetting) {
                case "0":
                    $where .= " AND (courseendtime > $startoftoday AND courseendtime <> coursestarttime) ";
                    break;
                case false:
                case "1":
                    $where .= " AND courseendtime > $startoftoday ";
                    break;
                case "2":
                    $where .= " AND (courseendtime > $startoftoday OR courseendtime = coursestarttime)";
                    break;
            }
        }
        return;
    }
}
