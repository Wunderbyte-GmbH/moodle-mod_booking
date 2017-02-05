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
 * BadgeCerts table for displaying list of users with certificate.
 *
 * @package report_reportbadges
 * @copyright 2014 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();


class unbooked_users extends table_sql {

    /**
     * Constructor
     *
     * @param int $uniqueid all tables have to have a unique id, this is used as a key when storing table properties like sort order in the session.
     */
    function __construct($uniqueid) {
        parent::__construct($uniqueid);
        // Define the list of columns to show.
        $columns = array('selected', 'completed', 'institution', 'fullname', 'timecreated');
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = array('', get_string('completed', 'mod_booking'),
            get_string('institution', 'mod_booking'), get_string('fullname', 'mod_booking'),
            get_string('timecreated', 'mod_booking'));
        $this->define_headers($headers);

        $this->collapsible(false);
        $this->sortable(true);
        $this->pageable(true);
    }

    /**
     * This function is called for each data row to allow processing of the username value.
     *
     * @param object $values Contains object with all the values of record.
     * @return $string Return username with link to profile or username only when downloading.
     */
    function col_timecreated($values) {
        if ($values->timecreated > 0) {
            return userdate($values->timecreated);
        }

        return '';
    }

    function col_completed($values) {
        if ($values->completed) {
            return '&#x2713;';
        }

        return '';
    }

    function col_selected($values) {
        if (!$this->is_downloading()) {
            return '<input type="checkbox" class="usercheckbox" name="user[][' . $values->userid .
                     ']" value="' . $values->userid . '" />';
        } else {
            return '';
        }
    }

    /**
     * This function is called for each data row to allow processing of columns which do not have a *_cols function.
     *
     * @return string return processed value. Return null if no change has been made.
     */
    function other_cols($colname, $value) {
    }
}
