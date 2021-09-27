<?php

namespace mod_booking\table;

global $CFG;
require_once($CFG->libdir.'/tablelib.php');

use coding_exception;
use dml_exception;
use mod_booking\booking_utils;
use moodle_exception;
use moodle_url;
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
        $columns = array('text', 'course', 'coursestarttime', 'courseendtime', 'location', 'institution', 'participants',
            'waitinglist', 'manageresponses', 'link');
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = array(
            get_string('bsttext', 'mod_booking'),
            get_string('bstcourse', 'mod_booking'),
            get_string('bstcoursestarttime', 'mod_booking'),
            get_string('bstcourseendtime', 'mod_booking'),
            get_string('bstlocation', 'mod_booking'),
            get_string('bstinstitution', 'mod_booking'),
            get_string('bstparticipants', 'mod_booking'),
            get_string('bstwaitinglist', 'mod_booking'),
            get_string('bstmanageresponses', 'mod_booking'),
            get_string('bstlink', 'mod_booking')
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
     * This function is called for each data row to add a link
     * for managing responses (booking_answers).
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a link to report.php (manage responses).
     * @throws moodle_exception
     * @throws coding_exception
     */
    function col_manageresponses($values) {
        global $CFG, $DB;

        // Link is empty on default.
        $link = '';

        if ($DB->get_records('booking_answers', ['optionid' => $values->optionid])) {
            // Add a link to redirect to the booking option.
            $link = new moodle_url($CFG->wwwroot . '/mod/booking/report.php', array(
                'id' => $values->cmid,
                'optionid' => $values->optionid
            ));
            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            $link = html_entity_decode($link->out());

            if (!$this->is_downloading()) {
                // Only format as a button if it's not an export.
                $link = '<a href="' . $link . '" class="btn btn-secondary">'
                    . get_string('bstmanageresponses', 'mod_booking')
                    . '</a>';
            }
        }
        // Do not show a link if there are no answers.

        return $link;
    }

    /**
     * This function is called for each data row to allow processing of the
     * link value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a link to the booking option (formatted as button).
     * @throws moodle_exception
     * @throws coding_exception
     */
    function col_link($values) {
        global $CFG;

        // Add a link to redirect to the booking option.
        $link = new moodle_url($CFG->wwwroot . '/mod/booking/view.php', array(
            'id' => $values->cmid,
            'optionid' => $values->optionid,
            'action' => 'showonlyone',
            'whichview' => 'showonlyone'
        ));
        // Use html_entity_decode to convert "&amp;" to a simple "&" character.
        $link = html_entity_decode($link->out());

        if (!$this->is_downloading()) {
            // Only format as a button if it's not an export.
            $link = '<a href="' . $link . '" class="btn btn-primary">'
                . get_string('bstlink', 'mod_booking')
                . '</a>';
        }

        return $link;
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