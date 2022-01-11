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

namespace mod_booking\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/tablelib.php');

use coding_exception;
use dml_exception;
use \local_wunderbyte_table\wunderbyte_table;
use \mod_booking\booking_utils;
use mod_booking\output\col_action;
use mod_booking\output\col_price;
use \mod_booking\output\col_text;
use \mod_booking\output\col_teacher;
use moodle_exception;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 */
class bookingoptions_table extends wunderbyte_table {

    /**
     * Cache an array of teacher names to save DB queries.
     */
    private $teachers = [];

    /**
     * Constructor
     * @param int $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        global $PAGE;
        $this->baseurl = $PAGE->url;

        // Columns and headers are not defined in constructor, in order to keep things as generic as possible.
    }

    /**
     * This function is called for each data row to allow processing of the
     * teacher value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    public function col_teacher($values) {
        global $PAGE;

        // Render col_text using a template.
        $output = $PAGE->get_renderer('mod_booking');

        // Currently, this will use dummy teachers.
        $data = new col_teacher();

        return $output->render_col_teacher($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * price value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    public function col_price($values) {
        global $PAGE;

        // Render col_text using a template.
        $output = $PAGE->get_renderer('mod_booking');

        // Currently, this will use dummy teachers.
        $data = new col_price();

        return $output->render_col_price($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * text value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    public function col_text($values) {
        global $PAGE;

        // If the data is being downloaded we show the original text including the separator and unique idnumber.
        if (!$this->is_downloading()) {
            // Remove identifier key and separator if necessary.
            booking_utils::transform_unique_bookingoption_name_to_display_name($values);
        }

        // Render col_text using a template.
        $output = $PAGE->get_renderer('mod_booking');
        $data = new col_text($values->text);

        return $output->render_col_text($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * coursestarttime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $coursestarttime Returns course start time as a readable string.
     * @throws coding_exception
     */
    public function col_coursestarttime($values) {
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
    public function col_courseendtime($values) {
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
    public function col_manageresponses($values) {
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
     * action button.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $action Returns formatted action button.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_action($values) {
        global $PAGE;

        // Render col_text using a template.
        $output = $PAGE->get_renderer('mod_booking');

        // Currently, this will use dummy teachers.
        $data = new col_action();

        return $output->render_col_action($data);
    }

    /**
     * Override wunderbyte_table function and use own renderer.
     *
     * @return void
     */
    public function finish_html() {
        global $PAGE;

        $output = $PAGE->get_renderer('mod_booking');
        $table = new \local_wunderbyte_table\output\table($this);
        echo $output->render_bookingoptions_table($table);
    }
}
