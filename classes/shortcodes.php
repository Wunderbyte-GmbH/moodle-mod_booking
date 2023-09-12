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

        $table = self::init_table_for_courses(null, $course->shortname);

        $wherearray['recommendedin'] = "%$course->shortname%";

        list($fields, $from, $where, $params, $filter) =
                booking::get_options_filter_sql(0, 0, '', null, null, [], $wherearray);

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
    public static function fieldofstudyoptions($shortcode, $args, $content, $env, $next) {

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

        $table = new bookingoptions_wbtable($tablename, $booking);

        // Without defining sorting won't work!
        // phpcs:ignore
        //$table->define_columns(['titleprefix']);
        return $table;
    }

    /**
     * Define Filter columns
     *
     * @param bookingoptions_wbtable $table
     * @return void
     */
    private static function define_filtercolumns(&$table) {
        $table->define_filtercolumns([
            'id',
            'dayofweek' => [
                'localizedname' => get_string('dayofweek', 'mod_booking'),
                'monday' => get_string('monday', 'mod_booking'),
                'tuesday' => get_string('tuesday', 'mod_booking'),
                'wednesday' => get_string('wednesday', 'mod_booking'),
                'thursday' => get_string('thursday', 'mod_booking'),
                'friday' => get_string('friday', 'mod_booking'),
                'saturday' => get_string('saturday', 'mod_booking'),
                'sunday' => get_string('sunday', 'mod_booking')
            ],  'location' => [
                'localizedname' => get_string('location', 'mod_booking')
            ]
        ]);
    }

    private static function get_booking($args) {
        // If the id argument was not passed on, we have a fallback in the connfig.
        if (!isset($args['id'])) {
            $args['id'] = get_config('mod_booking', 'shortcodessetinstance');
        }

        // To prevent misconfiguration, id has to be there and int.
        if (!(isset($args['id']) && $args['id'] && is_int((int)$args['id']))) {
            return 'Set id of booking instance';
        }

        if (!$booking = singleton_service::get_instance_of_booking_by_cmid($args['id'])) {
            return 'Couldn\'t find right booking instance ' . $args['id'];
        }

        return $booking;
    }

    /**
     * Add some information about the table
     *
     * @param bookingoptions_wbtable $table
     * @param array $args
     * @return void
     */
    private static function generate_table_for_list(&$table, $args) {
        $subcolumnsinfo = ['teacher', 'dayofweektime', 'location', 'bookings'];
        if (!empty($args['showminanswers'])) {
            $subcolumnsinfo[] = 'minanswers';
        }
        $subcolumnsleftside = ['text'];

        $table->define_cache('mod_booking', 'bookingoptionstable');

        $table->add_subcolumns('top', ['action']);
        $table->add_subcolumns('leftside', ['text']);
        $table->add_subcolumns('info', $subcolumnsinfo);
        // phpcs:ignore
        /* $table->add_subcolumns('footer', ['botags']); */
        $table->add_subcolumns('leftside', $subcolumnsleftside);

        $table->add_subcolumns('info', $subcolumnsinfo);
        // phpcs:ignore
        //$table->add_subcolumns('footer', ['botags']);

        $table->add_classes_to_subcolumns('top', ['columnkeyclass' => 'd-none']);
        $table->add_classes_to_subcolumns('top', ['columnclass' => 'text-right col-md-2 position-relative pr-0'], ['action']);

        $table->add_classes_to_subcolumns('leftside', ['columnkeyclass' => 'd-none']);
        $table->add_classes_to_subcolumns('leftside', ['columnclass' => 'text-left mt-2 mb-2 h3 col-md-auto'], ['text']);

        $table->add_classes_to_subcolumns('info', ['columnkeyclass' => 'd-none']);
        $table->add_classes_to_subcolumns('info', ['columnclass' => 'text-left text-secondary font-size-sm pr-2']);
        $table->add_classes_to_subcolumns('info', ['columnvalueclass' => 'd-flex'], ['teacher']);
        $table->add_classes_to_subcolumns('info', ['columniclassbefore' => 'fa fa-clock-o'], ['dayofweektime']);
        $table->add_classes_to_subcolumns('info', ['columniclassbefore' => 'fa fa-map-marker'], ['location']);
        $table->add_classes_to_subcolumns('info', ['columniclassbefore' => 'fa fa-ticket'], ['bookings']);
        if (!empty($args['showminanswers'])) {
            $table->add_classes_to_subcolumns('info', ['columniclassbefore' => 'fa fa-arrow-up'], ['minanswers']);
        }

        // Set additional descriptions.
        $table->add_classes_to_subcolumns('rightside', ['columnvalueclass' =>
            'text-right mb-auto align-self-end shortcodes_option_info_invisible '],
            ['invisibleoption']);
        $table->add_classes_to_subcolumns('rightside', ['columnclass' =>
            'text-right mt-auto align-self-end theme-text-color bold ']);

        // Override naming for columns. one could use getstring for localisation here.
        $table->add_classes_to_subcolumns(
            'top',
            ['keystring' => get_string('tableheader_text', 'booking')],
        );
        $table->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_text', 'booking')],
            ['text']
        );
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*
        $table->add_classes_to_subcolumns(
            'leftside',
            ['keystring' => get_string('tableheader_teacher', 'booking')],
            ['teacher']
        );
        */
        $table->add_classes_to_subcolumns(
            'info',
            ['keystring' => get_string('tableheader_maxanswers', 'booking')],
            ['maxanswers']
        );
        $table->add_classes_to_subcolumns(
            'info',
            ['keystring' => get_string('tableheader_maxoverbooking', 'booking')],
            ['maxoverbooking']
        );
        $table->add_classes_to_subcolumns(
            'info',
            ['keystring' => get_string('tableheader_coursestarttime', 'booking')],
            ['coursestarttime']
        );
        $table->add_classes_to_subcolumns(
            'info',
            ['keystring' => get_string('tableheader_courseendtime', 'booking')],
            ['courseendtime']
        );

        $table->is_downloading('', 'List of booking options');
    }
}
