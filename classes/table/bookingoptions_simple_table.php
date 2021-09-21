<?php

namespace mod_booking\table;

global $CFG;
require_once($CFG->libdir.'/tablelib.php');

use coding_exception;
use dml_exception;
use mod_booking\booking_utils;
use table_sql;

defined('MOODLE_INTERNAL') || die();

/**
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 */
class bookingoptions_simple_table extends table_sql {

    /**
     * Constructor
     * @param int $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     */
    function __construct($uniqueid) {
        parent::__construct($uniqueid);

        global $PAGE;
        $this->baseurl = $PAGE->url;

        // Define the list of columns to show.
        $columns = array('text', 'coursestarttime', 'courseendtime', 'location', 'institution', 'course');
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = array(
            get_string('bsttext', 'mod_booking'),
            get_string('bstcoursestarttime', 'mod_booking'),
            get_string('bstcourseendtime', 'mod_booking'),
            get_string('bstlocation', 'mod_booking'),
            get_string('bstinstitution', 'mod_booking'),
            get_string('bstcourse', 'mod_booking')
        );
        $this->define_headers($headers);
    }

    /**
     * This function is called for each data row to allow processing of the
     * text value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    function col_text($values) {
        // If the data is being downloaded we show the original text including the separator and unique idnumber.
        if (!$this->is_downloading()) {
            // Remove identifier key and separator if necessary.
            booking_utils::transform_unique_bookingoption_name_to_display_name($values);
        }

        return $values->text;
    }

    /**
     * This function is called for each data row to allow processing of the
     * coursestarttime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $coursestarttime Returns course start time as a readable string.
     * @throws coding_exception
     */
    function col_coursestarttime($values) {
        // Prepare date string.
        if ($values->coursestarttime != 0) {
            $coursestarttime = userdate($values->coursestarttime, get_string('strftimedatetime'));
        } else {
            $coursestarttime = '';
        }

        return $coursestarttime;
    }

    /**
     * This function is called for each data row to allow processing of the
     * courseendtime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $courseendtime Returns course end time as a readable string.
     * @throws coding_exception
     */
    function col_courseendtime($values) {
        // Prepare date string.
        if ($values->courseendtime != 0) {
            $courseendtime = userdate($values->courseendtime, get_string('strftimedatetime'));
        } else {
            $courseendtime = '';
        }

        return $courseendtime;
    }

    /**
     * This function is called for each data row to allow processing of
     * columns which do not have a *_cols function.
     * @return string return processed value. Return NULL if no change has
     *     been made.
     */
    function other_cols($colname, $value) {
        // Anything we want to do with other cols...
    }
}