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
 * For displaying all user bookings of a bookingoption
 *
 * @package mod_booking
 * @copyright 2014 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();


class all_userbookings extends table_sql {

    public $bookingdata = null;

    public $cm = null;

    public $db = null;

    /**
     *
     * @var int
     */
    public $optionid = null;

    /**
     *
     * @var array of ratingoptions
     */
    public $ratingoptions = null;

    /**
     * Constructor
     *
     * @param int $uniqueid all tables have to have a unique id, this is used as a key when storing table properties like sort order in the session.
     */
    public function __construct($uniqueid, $bookingdata, $cm, $optionid) {
        parent::__construct($uniqueid);

        $this->collapsible(true);
        $this->sortable(true);
        $this->pageable(true);
        $this->bookingdata = $bookingdata;
        $this->cm = $cm;
        $this->optionid = $optionid;
        unset($this->attributes['cellspacing']);
    }

    /**
     * Set rating options
     *
     * @param array $ratingoptions
     */
    public function set_ratingoptions($ratingoptions) {
        $this->ratingoptions = $ratingoptions;
    }

    /**
     * This function is called for each data row to allow processing of the username value.
     *
     * @param object $values Contains object with all the values of record.
     * @return $string Return username with link to profile or username only when downloading.
     */
    protected function col_timecreated($values) {
        if ($values->timecreated > 0) {
            return userdate($values->timecreated);
        }

        return '';
    }

    public function col_fullname($values) {
        if (empty($values->otheroptions)) {
            return html_writer::link(
                    new moodle_url('/user/profile.php', array('id' => $values->userid)),
                    "{$values->firstname} {$values->lastname} ({$values->username})", array());
        } else {
            return html_writer::link(
                    new moodle_url('/user/profile.php', array('id' => $values->userid)),
                    "{$values->firstname} {$values->lastname} ({$values->username})", array()) .
                     "({$values->otheroptions})";
        }
    }

    protected function col_numrec($values) {
        if ($values->numrec == 0) {
            return '';
        } else {
            return $values->numrec;
        }
    }

    protected function col_completed($values) {
        if (!$this->is_downloading()) {
            $completed = '';
            if ($values->completed) {
                $completed = '&#x2713;';
            }
            return $completed;
        } else {
            return $values->completed;
        }
    }

    protected function col_rating($values) {
        global $PAGE;
        $output = '';
        $renderer = $PAGE->get_renderer('mod_booking');
        if (!empty($values->rating)) {
            $output .= html_writer::tag('div', $renderer->render($values->rating),
                    array('class' => 'booking-option-rating'));
        }
        return $output;
    }

    protected function col_coursestarttime($values) {
        if ($values->coursestarttime == 0) {
            return '';
        } else {
            return userdate($values->coursestarttime, get_string('strftimedatetime'));
        }
    }

    protected function col_courseendtime($values) {
        if ($values->courseendtime == 0) {
            return '';
        } else {
            return userdate($values->courseendtime, get_string('strftimedatetime'));
        }
    }

    protected function col_waitinglist($values) {
        if ($this->is_downloading()) {
            return $values->waitinglist;
        }

        $completed = '&nbsp;';

        if ($values->waitinglist) {
            $completed = '&#x2713;';
        }

        return $completed;
    }

    protected function col_selected($values) {
        if (!$this->is_downloading()) {
            return '<input id="check' . $values->id .
                     '" type="checkbox" class="usercheckbox" name="user[][' . $values->userid .
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

    /**
     *
     * {@inheritDoc}
     * @see flexible_table::wrap_html_start()
     */
    public function wrap_html_start() {
        echo '<form method="post" id="studentsform">' . "\n";
        $ratingoptions = $this->ratingoptions;
        if (!empty($ratingoptions)) {
            foreach ($ratingoptions as $name => $value) {
                $attributes = array('type' => 'hidden', 'class' => 'ratinginput', 'name' => $name,
                    'value' => $value);
                echo html_writer::empty_tag('input', $attributes);
            }
        }
    }

    /**
     *
     * {@inheritDoc}
     * @see flexible_table::wrap_html_finish()
     */
    public function wrap_html_finish() {
        global $DB;
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        if (!$this->bookingdata->booking->autoenrol &&
                 has_capability('mod/booking:communicate', context_module::instance($this->cm->id)) &&
                 $this->bookingdata->option->courseid > 0) {
            echo '<input type="submit" name="subscribetocourse" value="' .
             get_string('subscribetocourse', 'booking') . '" />';
        }

        if (has_capability('mod/booking:deleteresponses', context_module::instance($this->cm->id))) {
            echo '<input type="submit" name="deleteusers" value="' .
                     get_string('booking:deleteresponses', 'booking') . '" />';
        }

        if (has_capability('mod/booking:communicate', context_module::instance($this->cm->id))) {
            $pollurl = trim($this->bookingdata->option->pollurl);
            if (!empty($pollurl)) {
                echo '<input type="submit" name="sendpollurl" value="' .
                         get_string('booking:sendpollurl', 'booking') . '" />';
            }
            echo '<input type="submit" name="sendreminderemail" value="' .
                     get_string('sendreminderemail', 'booking') . '" />';
            echo '<input type="submit" name="sendcustommessage" value="' .
                     get_string('sendcustommessage', 'booking') . '" />';
        }

        if (booking_check_if_teacher($this->bookingdata->option) ||
                 has_capability('mod/booking:updatebooking',
                        context_module::instance($this->cm->id))) {
            echo '<input type="submit" name="activitycompletion" value="' .
             (empty($this->bookingdata->booking->btncacname) ? get_string(
                    'confirmactivitycompletion', 'booking') : $this->bookingdata->booking->btncacname) .
             '" />';

            // Output rating button.
            if (has_capability('moodle/rating:rate', context_module::instance($this->cm->id)) &&
                     $this->bookingdata->booking->assessed != 0) {
                $ratingbutton = html_writer::start_tag('span', array('class' => "ratingsubmit"));
                $attributes = array('type' => 'submit', 'class' => 'postratingmenusubmit',
                    'id' => 'postratingsubmit', 'name' => 'postratingsubmit',
                    'value' => s(get_string('rate', 'rating')));
                $ratingbutton .= html_writer::empty_tag('input', $attributes);
                $ratingbutton .= html_writer::end_span();
                echo $ratingbutton;
            }

            if ($this->bookingdata->booking->numgenerator) {
                echo '<input type="submit" name="generaterecnum" value="' .
                         get_string('generaterecnum', 'booking') . '" onclick="return confirm(\'' .
                         get_string('generaterecnumareyousure', 'booking') . '\')"/>';
            }

            $connectedbooking = $DB->get_record("booking",
                    array('conectedbooking' => $this->bookingdata->booking->id), 'id',
                    IGNORE_MULTIPLE);

            if ($connectedbooking) {

                $nolimits = $DB->get_records_sql(
                        "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ?",
                        array($connectedbooking->id));

                if (!$nolimits) {
                    $result = $DB->get_records_select("booking_options",
                            "bookingid = {$connectedbooking->id} AND id <> {$this->optionid}", null,
                            'text ASC', 'id, text');

                    $options = array();

                    foreach ($result as $value) {
                        $options[$value->id] = $value->text;
                    }

                    echo "<br>";

                    echo html_writer::select($options, 'selectoptionid', '');

                    $label = (empty(
                            $this->bookingdata->booking->booktootherbooking) ? get_string(
                            'booktootherbooking', 'booking') : $this->bookingdata->booking->booktootherbooking);

                    echo '<input type="submit" name="booktootherbooking" value="' .
                             $label . '" />';
                } else {
                    $alllimits = $DB->get_records_sql(
                            "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ? AND bo.otheroptionid = ?",
                            array($connectedbooking->id, $this->optionid));

                    if ($alllimits) {
                        $options = array();

                        foreach ($alllimits as $value) {
                            $options[$value->optionid] = $value->text;
                        }

                        echo "<br>";

                        echo html_writer::select($options, 'selectoptionid', '');

                        $label = (empty(
                                $this->bookingdata->booking->booktootherbooking) ? get_string(
                                'booktootherbooking', 'booking') : $this->bookingdata->booking->booktootherbooking);

                        echo '<input type="submit" name="booktootherbooking" value="' .
                                 $label . '" />';
                    }
                }
            }
        }

        echo '</form>';

        echo '<hr>';
    }
}
