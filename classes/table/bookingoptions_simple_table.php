<?php

namespace mod_booking\table;

global $CFG;
require_once($CFG->libdir.'/tablelib.php');

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
        // Define the list of columns to show.
        $columns = array('testcolumn1', 'testcolumn2');
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = array('Test Column 1', 'Test Column 2');
        $this->define_headers($headers);
    }

    /**
     * This function is called for each data row to allow processing of the
     * username value.
     *
     * @param object $values Contains object with all the values of record.
     * @return $string Return username with link to profile or username only
     *     when downloading.
     */
    function col_testcolumn1($values) {
        // If the data is being downloaded than we don't want to show HTML.
        if ($this->is_downloading()) {
            return $values->testcolumn1;
        } else {
            return '<a href="linktobookingoption">'.$values->testcolumn1.'</a>';
        }
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