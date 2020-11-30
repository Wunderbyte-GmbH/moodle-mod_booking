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

use context;
use context_course;
use dml_exception;
use html_writer;
use moodle_url;
use stdClass;
use table_sql;
use mod_booking\booking;
use mod_booking\booking_tags;

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
        global $OUTPUT, $USER;

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
                    $OUTPUT->pix_icon('t/edit', get_string('updatebooking', 'mod_booking')) .
                    get_string('updatebooking', 'mod_booking')) . '</div>';

            // Multiple dates session.
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link(new moodle_url('/mod/booking/optiondates.php',
                    array('id' => $this->cm->id, 'optionid' => $values->id)),
                    $OUTPUT->pix_icon('t/edit',
                        get_string('optiondates', 'booking')) .
                    get_string('optiondates', 'booking')) . '</div>';

            $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                    new moodle_url('/mod/booking/editdescription.php',
                        array('id' => $this->cm->id, 'optionid' => $values->id)),
                    $OUTPUT->pix_icon('t/edit', get_string('editdescription', 'mod_booking')) .
                    get_string('editdescription', 'mod_booking')) . '</div>';
            // Book teachers.
            if (has_capability('mod/booking:updatebooking', $this->context)) {
                $onlyoneurl = new moodle_url('/mod/booking/teachers.php',
                    array('id' => $this->cm->id, 'optionid' => $values->id));
                $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link($onlyoneurl,
                        $OUTPUT->pix_icon('t/edit',
                            get_string('editteacherslink', 'mod_booking')) .
                        get_string('editteacherslink', 'mod_booking')) . '</div>';
            }
            // Book other users.
            if (has_capability('mod/booking:subscribeusers', $this->context) ||
                booking_check_if_teacher($values)) {
                $onlyoneurl = new moodle_url('/mod/booking/subscribeusers.php',
                    array('id' => $this->cm->id, 'optionid' => $values->id));
                $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link($onlyoneurl,
                        $OUTPUT->pix_icon('t/edit',
                            get_string('bookotherusers', 'mod_booking')) .
                        get_string('bookotherusers', 'mod_booking')) . '</div>';
                $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link(new moodle_url('/mod/booking/subscribegroup.php', array('id' => $this->cm->id, 'optionid' => $values->id)),
                        $OUTPUT->pix_icon('t/edit',
                            get_string('subscribegroup', 'mod_booking')) .
                        get_string('subscribegroup', 'mod_booking')) . '</div>';
            }
            // Show only one option.
            $onlyoneurl = new moodle_url('/mod/booking/view.php',
                array('id' => $this->cm->id, 'optionid' => $values->id,
                    'action' => 'showonlyone', 'whichview' => 'showonlyone'));
            $onlyoneurl->set_anchor('goenrol');
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link($onlyoneurl,
                    $OUTPUT->pix_icon('t/edit',
                        get_string('onlythisbookingurl', 'mod_booking')) .
                    get_string('onlythisbookingurl', 'mod_booking')) . '</div>';

            if (has_capability('mod/booking:updatebooking', $this->context)) {
                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(new moodle_url('/mod/booking/report.php',
                        array('id' => $this->cm->id, 'optionid' => $values->id, 'action' => 'deletebookingoption',
                            'sesskey' => sesskey())),
                        $OUTPUT->pix_icon('t/delete', get_string('deletebookingoption', 'mod_booking')) .
                        get_string('deletebookingoption', 'mod_booking')) . '</div>';

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
                return userdate($values->coursestarttime, get_string('strftimedatetime'));
            }
        }

        // Use the renderer to output this column.
        $data = new \mod_booking\output\col_coursestarttime($this->booking, $values);
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
            return userdate($values->courseendtime, get_string('strftimedatetime'));
        }
    }

    protected function col_text($values) {

        global $PAGE;

        $output = $PAGE->get_renderer('mod_booking');

        $forbookeduser = $values->iambooked == 1 ? true : false;

        $data = new \mod_booking\output\bookingoption_description($this->booking, $values->id,
            null, DESCRIPTION_WEBSITE, true, $forbookeduser);

        if ($this->booking->settings->showdescriptionmode == 0) {
            // We will have a number of modals on this site, therefore we have to distinguish them.
            $data->modalcounter = $values->id;

            // We can go with the data from bookingoption_description directly to modal.
            return $output->render_col_text_modal($data);
        } else {
            return $output->render_bookingoption_description($data);
        }
    }

    protected function col_description($values) {
        $output = '';
        if (!empty($values->description)) {
            $output .= html_writer::div($this->format_text($values->description), 'courseinfo');
        }

        return $output;
    }

    protected function col_availableplaces($values) {

        // We moved this code to booking_utils so it's available outside of table_sql.

<<<<<<< HEAD
        $utils = new booking_utils;
        return $utils->return_button_based_on_record($this->booking, $this->context, $values);
=======
        if (time() > $values->bookingclosingtime and $values->bookingclosingtime != 0) {
            $availabibility = "closed";
        }

        // I'm booked or not.
        if ($values->iambooked) {
            if ($values->allowupdate and $availabibility != 'closed' and $values->completed != 1) {

                $buttonoptions = array('id' => $this->cm->id, 'action' => 'delbooking',
                    'optionid' => $values->id, 'sesskey' => $USER->sesskey);
                $url = new moodle_url('view.php', $buttonoptions);
                $delete = $OUTPUT->single_button($url,
                    (empty($values->btncancelname) ? get_string('cancelbooking', 'booking') : $values->btncancelname),
                    'post');

                if ($values->coursestarttime > 0 && $values->allowupdatedays > 0) {
                    if (time() > strtotime("-{$values->allowupdatedays} day", $values->coursestarttime)) {
                        $delete = "";
                    }
                }
            }

            if (!empty($values->completed)) {
                $completed = '<div class="">' . get_string('completed', 'mod_booking') .
                    '<span class="fa fa-check float-right"> </span> </div>';
            } else {
                $completed = '';
            }

            if (!empty($values->status)) {
                $status = '<div class="">' . get_string('presence', 'mod_booking') .
                    '<span class="badge badge-default float-right">' . $this->col_status($values) . '</span> </div>';
            } else {
                $status = '';
            }
            if ($values->waitinglist) {
                $booked .= '<div class="alert alert-info">' . get_string('onwaitinglist', 'booking') . '</div>';
            } else if ($inpast) {
                $booked .= '<div class="alert alert-success">' . get_string('bookedpast', 'booking') . $completed . $status .
                    '</div>';
            } else {
                $booked .= '<div class="alert alert-success">' . get_string('booked', 'booking') . $completed . $status . '</div>';
            }
        } else {
            $buttonoptions = array('answer' => $values->id, 'id' => $this->cm->id,
                'sesskey' => $USER->sesskey);

            if (empty($this->booking->settings->bookingpolicy)) {
                $buttonoptions['confirm'] = 1;
            }

            $url = new moodle_url('view.php', $buttonoptions);
            $button = $OUTPUT->single_button($url,
                (empty($values->btnbooknowname) ? get_string('booknow', 'booking') : $values->btnbooknowname),
                'post');
        }

        if (($values->limitanswers && ($availabibility == "full")) || ($availabibility == "closed") || !$underlimit ||
            $values->disablebookingusers) {
            $button = '';
        }

        if ($values->cancancelbook == 0 && $values->courseendtime > 0 &&
            $values->courseendtime < time()) {
            $button = '';
            $delete = '';
        }

        if (!empty($this->booking->settings->banusernames)) {
            $disabledusernames = explode(',', $this->booking->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $button = '';
                }
            }
        }

        // Check if user has right to book.
        if (!has_capability('mod/booking:choose', $this->context, $USER->id, false)) {
            $button = get_string('norighttobook', 'booking') . "<br />";
        }

        // Check, if can book based on login method.
        if (!empty($this->booking->settings->auth)) {
            if ($this->booking->settings->auth != $USER->auth && !empty($button)) {
                $button = get_string('wrongauth', 'booking') . "<br />";
            }
        }

        if (has_capability('mod/booking:readresponses', $this->context) || $values->isteacher) {
            if (groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS
                AND !has_capability('moodle/site:accessallgroups', \context_course::instance($this->booking->course->id))) {
                $numberofresponses = $values->allbookedsamegroup;
            } else {
                $numberofresponses = $values->waiting + $values->booked;
            }
            $manage = "<br><a href=\"report.php?id={$this->cm->id}&optionid={$values->id}\">" .
                get_string("viewallresponses", "booking", $numberofresponses) . "</a>";
        }

        if ($this->booking->settings->ratings > 0) {
            $manage .= '<div><select class="starrating" id="rate' . $values->id .
                '" data-current-rating="' . $values->myrating . '" data-itemid="' .
                $values->id . '">
  <option value="1">1</option><option value="2">2</option><option value="3">3</option>
  <option value="4">4</option><option value="5">5</option></select></div>';
            if (has_capability('mod/booking:readresponses', $this->context) || $values->isteacher) {
                $manage .= get_string('aggregateavg', 'rating') . ' ' . number_format(
                        (float) $values->rating, 2, '.', '') . " ({$values->ratingcount})";
            }
        }

        if (!$values->limitanswers) {
            return $button . $delete . $booked . get_string("unlimited", 'booking') . $manage;
        } else {
            $places = new places($values->maxanswers, $values->availableplaces, $values->maxoverbooking,
                $values->maxoverbooking - $values->waiting);
            $overbooking = '';
            if ($values->maxoverbooking != 0) {
                $overbooking = "<div>" . get_string("waitingplacesavailable", "booking", $places) . "</div>";
            }
            return $button . $delete . $booked . "<div>" . get_string("availableplaces", "booking", $places) .
                "</div>" . $overbooking . $manage;
        }
>>>>>>> 153f2ee... If there is no waiting places, don't show it.
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
                    return userdate($tmp[1], get_string('strftimedate'));
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
