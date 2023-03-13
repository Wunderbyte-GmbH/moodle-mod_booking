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
 * Display all options.
 *
 * @package mod_booking
 * @copyright 2016 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use comment;
use context;
use context_course;
use dml_exception;
use html_writer;
use moodle_url;
use stdClass;
use table_sql;
use mod_booking\booking;
use mod_booking\booking_tags;
use mod_booking\output\bookingoption_description;

class all_options extends table_sql {

    public $booking = null;

    public $cm = null;

    public $context = null;

    public $tags = null;

    /**
     * mod_booking\all_options constructor.
     *
     * @param string $uniqueid
     * @param booking $booking
     * @param object $cm course module object
     * @param context $context
     * @throws dml_exception
     */
    public function __construct($uniqueid, booking $booking, $cm, context $context) {
        parent::__construct($uniqueid);

        $this->collapsible(true);
        $this->pageable(true);
        $this->booking = $booking;
        $this->cm = $cm;
        $this->context = $context;
        $this->tags = new booking_tags($cm->course);
    }

    protected function col_id($values) {
        global $OUTPUT;

        $ddoptions = array();
        $ret = '<div class="menubar" id="action-menu-' . $values->id . '-menubar" role="menubar">';

        if ($values->iambooked) {
            $ret .= html_writer::link(
                new moodle_url('/mod/booking/viewconfirmation.php',
                    array('id' => $this->cm->id, 'optionid' => $values->id)),
                $OUTPUT->pix_icon('t/print', get_string('bookedtext', 'mod_booking')),
                array('target' => '_blank'));
        }

        if (has_capability('mod/booking:updatebooking', $this->context) || (has_capability(
                    'mod/booking:addeditownoption', $this->context) &&
                booking_check_if_teacher($values))) {
            $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                    new moodle_url('/mod/booking/editoptions.php',
                        array('id' => $this->cm->id, 'optionid' => $values->id)),
                    $OUTPUT->pix_icon('t/editstring', get_string('editbookingoption', 'mod_booking')) .
                    get_string('editbookingoption', 'mod_booking')) . '</div>';

            // Multiple dates session.
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link(new moodle_url('/mod/booking/optiondates.php',
                    array('id' => $this->cm->id, 'optionid' => $values->id)),
                    $OUTPUT->pix_icon('i/scheduled',
                        get_string('optiondatesmanager', 'booking')) .
                    get_string('optiondatesmanager', 'booking')) . '</div>';

            // Book other users.
            if (has_capability('mod/booking:subscribeusers', $this->context) ||
                booking_check_if_teacher($values)) {
                $onlyoneurl = new moodle_url('/mod/booking/subscribeusers.php',
                    array('id' => $this->cm->id, 'optionid' => $values->id));
                $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link($onlyoneurl,
                        $OUTPUT->pix_icon('i/users',
                            get_string('bookotherusers', 'mod_booking')) .
                        get_string('bookotherusers', 'mod_booking')) . '</div>';
            }
            // Show only one option.
            $onlyoneurl = new moodle_url('/mod/booking/view.php',
                array('id' => $this->cm->id, 'optionid' => $values->id,
                    'whichview' => 'showonlyone'));
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link($onlyoneurl,
                    $OUTPUT->pix_icon('i/publish',
                        get_string('onlythisbookingoption', 'mod_booking')) .
                    get_string('onlythisbookingoption', 'mod_booking')) . '</div>';

            if (has_capability('mod/booking:updatebooking', $this->context)) {
                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(new moodle_url('/mod/booking/report.php',
                        array('id' => $this->cm->id, 'optionid' => $values->id, 'action' => 'deletebookingoption',
                            'sesskey' => sesskey())),
                        $OUTPUT->pix_icon('t/delete', get_string('deletethisbookingoption', 'mod_booking')) .
                        get_string('deletethisbookingoption', 'mod_booking')) . '</div>';

                if ($values->bostatus == 1) {
                    $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                        $OUTPUT->pix_icon('i/reload', '') .
                        get_string('undocancelthisbookingoption', 'mod_booking'), ['onclick' =>
                            "require(['mod_booking/confirm_cancel'], function(init) {
                            init.init('" . $values->id . "', '" . $values->bostatus . "');
                            });"
                            ]) . "</div>";
                } else {
                    $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                        $OUTPUT->pix_icon('t/block', '') .
                        get_string('cancelthisbookingoption', 'mod_booking'), ['onclick' =>
                            "require(['mod_booking/confirm_cancel'], function(init) {
                            init.init('" . $values->id . "', '" . $values->bostatus . "');
                            });"
                            ]) . "</div>";
                }

                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(new moodle_url('/mod/booking/editoptions.php',
                        array('id' => $this->cm->id, 'optionid' => -1, 'copyoptionid' => $values->id)), $OUTPUT->pix_icon('t/copy',
                            get_string('duplicatebooking', 'mod_booking')) .
                        get_string('duplicatebooking', 'mod_booking')) . '</div>';
            }
            $modinfo = get_fast_modinfo($this->booking->course);
            $bookinginstances = isset($modinfo->instances['booking']) ? count($modinfo->instances['booking']) : 0;
            if (has_capability('mod/booking:updatebooking', context_course::instance($this->booking->course->id)) &&
                $bookinginstances > 1) {
                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                        new moodle_url('/mod/booking/moveoption.php',
                            array('id' => $this->cm->id, 'optionid' => $values->id, 'sesskey' => sesskey())),
                        $OUTPUT->pix_icon('t/move', get_string('moveoptionto', 'booking')) .
                        get_string('moveoptionto', 'booking')) . '</div>';
            }
        }
        if (!empty($ddoptions)) {
            $ret .= '<div class="dropdown d-inline">
                    <a href="' .
                new moodle_url('/mod/booking/editoptions.php',
                    array('id' => $this->cm->id, 'optionid' => $values->id)) .
                '" id="action-menu-toggle-' . $values->id . '" title="" role="button" data-toggle="dropdown"
                        aria-haspopup="true" aria-expanded="false">' . $OUTPUT->pix_icon(
                    't/edit', get_string('settings', 'moodle')) .
                '</a>
                    <div class="dropdown-menu dropdown-menu-right menu align-tr-br" id="action-menu-' .
                $values->id .
                '-menu" data-rel="menu-content"
                        aria-labelledby="action-menu-toggle-3" role="menu" data-align="tr-br">';
            $ret .= implode($ddoptions);
            $ret .= '</div></div>';
        }

        $ret .= '</div>';

        return $ret;
    }

    protected function col_status($values) {
        switch ($values->status) {
            case 0:
                return '';
            case 1:
                return get_string('status_complete', 'booking');
            case 2:
                return get_string('status_incomplete', 'booking');
            case 3:
                return get_string('status_noshow', 'booking');
            case 4:
                return get_string('status_failed', 'booking');
            case 5:
                return get_string('status_unknown', 'booking');
            case 6:
                return get_string('status_attending', 'booking');
            default:
                return '';
        }
    }

    protected function col_coursestarttime($values) {

        global $PAGE;

        if ($this->is_downloading()) {
            if ($values->coursestarttime == 0) {
                return '';
            } else {
                return userdate($values->coursestarttime, get_string('strftimedatetime', 'langconfig'));
            }
        }

        // Use the renderer to output this column.
        $data = new \mod_booking\output\col_coursestarttime($values->id, $this->booking);
        $output = $PAGE->get_renderer('mod_booking');
        // We can go with the data from bookingoption_description directly to modal.
        return $output->render_col_coursestarttime($data);
    }

    /**
     * Helper function to render multiple sessions data.
     * @param stdClass $values an object containing the values that should be rendered
     * @return string the rendered HTML of the session data containing dates and custom fields
     */
    protected function render_sessions_with_customfields($values) {
        global $DB;

        $val = ''; // The rendered HTML.
        $customfieldshtml = '';

        $timeobjects = $values->timeobjects;
        foreach ($timeobjects as $timeobject) {
            // Retrieve the custom field data.
            if ($customfields = $DB->get_records("booking_customfields", ["optiondateid" => $timeobject->optiondateid])) {
                foreach ($customfields as $customfield) {
                    $customfieldshtml .= '<i>' . $customfield->cfgname . ': </i>';
                    $customfieldshtml .= $customfield->value . '<br>';
                }
                $customfieldshtml .= '<br>';
            } else {
                $customfields = false;
            }

            $slot = explode('-', $timeobject->times);
            $tmpdate = new stdClass();
            $tmpdate->leftdate = userdate($slot[0], get_string('strftimedatetime', 'langconfig'));
            $tmpdate->righttdate = userdate($slot[1], get_string('strftimetime', 'langconfig'));

            $val .= get_string('leftandrightdate', 'booking', $tmpdate) . '<br>';

            if ($customfields && $this->booking->settings->showdescriptionmode == 1) {
                $val .= $customfieldshtml;
                $customfieldshtml = ''; // Reset the custom fields HTML now.
            } else {
                $val .= "<br>";
            }
        }
        return $val;
    }

    protected function col_courseendtime($values) {
        if ($values->courseendtime == 0) {
            return '';
        } else {
            return userdate($values->courseendtime, get_string('strftimedatetime', 'langconfig'));
        }
    }

    protected function col_text($values) {

        global $PAGE;

        $output = $PAGE->get_renderer('mod_booking');

        $forbookeduser = $values->iambooked == 1 ? true : false;

        $data = new bookingoption_description($values->id, null, DESCRIPTION_WEBSITE, true, $forbookeduser);

        $data->invisible = false;
        if (!empty($values->invisible) && $values->invisible == 1) {
            $data->invisible = true;
        }

        $ret = $output->render_bookingoption_description($data);

        // Progress bar showing the consumed quota visually.
        if (get_config('booking', 'showprogressbars')) {
            $collapsible = false;
            if (get_config('booking', 'progressbarscollapsible')) {
                $collapsible = true;
            }
            $ret .= booking_option::get_progressbar_html($values->id, 'primary', 'white', $collapsible);
        }

        // Comment booking options.
        $commentoptions = new stdClass();
        $commentoptions->area = 'booking_option';
        $commentoptions->context = $this->context;
        $commentoptions->cm = $this->cm;
        $commentoptions->itemid = $values->id;
        $commentoptions->component = 'mod_booking';
        $commentoptions->client_id = $values->id;
        $commentoptions->showcount = true;
        $comment = new comment($commentoptions);
        $ret .= $comment->output(true);

        return $ret;
    }

    protected function col_description($values) {
        $output = '';
        if (!empty($values->description)) {
            $output .= html_writer::div($this->format_text($values->description), 'courseinfo');
        }

        return $output;
    }

    /**
     * This function is called for each data row to allow processing of columns which do not have a *_cols function.
     *
     * @return string return processed value. Return null if no change has been made.
     */
    public function other_cols($colname, $value) {
        if (substr($colname, 0, 4) === "cust") {
            $tmp = explode('|', $value->{$colname});

            if (!$tmp) {
                return '';
            }

            if (count($tmp) == 2) {
                if ($tmp[0] == 'datetime') {
                    return userdate($tmp[1], get_string('strftimedate', 'langconfig'));
                } else {
                    return $tmp[1];
                }
            } else {
                return '';
            }
        }
    }

    public function wrap_html_start() {
    }

    public function wrap_html_finish() {
        echo "<hr>";
    }

    /**
     * Count the number of records. This has to be done after query_db was called!!!
     *
     * @return number of records found
     */
    public function count_records() {
        global $DB;
        if (!empty($this->countsql)) {
            return $DB->count_records_sql($this->countsql, $this->countparams);
        }
        return 0;
    }
}
