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
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;

use coding_exception;
use mod_booking\output\report_edit_bookingnotes;
use html_writer;
use mod_booking\bo_availability\conditions\customform;
use moodle_url;
use stdClass;
use user_picture;
defined('MOODLE_INTERNAL') || die();
require_once('../../lib/tablelib.php');

/**
 * Displays all bookings for a booking option
 * @author Andraž Prinčič, David Bogner
 *
 */
class all_userbookings extends \table_sql {

    /** @var booking_option|null */
    public $bookingdata = null;

    /** @var stdClass|null */
    public $cm = null;

    /** @var int $optionid*/
    public $optionid = null;

    /** @var array of ratingoptions */
    public $ratingoptions = null;

    /**
     * Constructor
     * @param string $uniqueid
     * @param booking_option $bookingdata
     * @param mixed $cm
     * @param mixed $optionid
     * @return void
     */
    public function __construct($uniqueid, booking_option $bookingdata, $cm, $optionid) {
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
     * @param mixed $values
     * @return string
     * @throws coding_exception
     */
    protected function col_timecreated($values) {
        if ($values->timecreated > 0) {
            return userdate($values->timecreated);
        }

        return '';
    }

    /**
     * For status column.
     * @param mixed $values
     * @return string
     * @throws coding_exception
     */
    protected function col_status($values) {
        switch ($values->status) {
            case 0:
                return '';
            case 1:
                return get_string('statuscomplete', 'booking');
            case 2:
                return get_string('statusincomplete', 'booking');
            case 3:
                return get_string('statusnoshow', 'booking');
            case 4:
                return get_string('statusfailed', 'booking');
            case 5:
                return get_string('statusunknown', 'booking');
            case 6:
                return get_string('statusattending', 'booking');
            default:
                return '';
        }
    }

    /**
     * Fullname column.
     * @param object $values
     * @return string
     */
    public function col_fullname($values) {
        if (empty($values->otheroptions)) {
            return html_writer::link(
                    new moodle_url('/user/profile.php', ['id' => $values->userid]),
                    "{$values->firstname} {$values->lastname} ({$values->username})", []);
        } else {
            return html_writer::link(
                    new moodle_url('/user/profile.php', ['id' => $values->userid]),
                    "{$values->firstname} {$values->lastname} ({$values->username})", []) .
                     "&nbsp;({$values->otheroptions})";
        }
    }

    /**
     * Numrec column.
     * @param mixed $values
     * @return mixed
     */
    protected function col_numrec($values) {
        if ($values->numrec == 0) {
            return '';
        } else {
            return $values->numrec;
        }
    }

    /**
     * Completed column.
     * @param mixed $values
     * @return mixed
     * @throws coding_exception
     */
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

    /**
     * Rating column.
     * @param mixed $values
     * @return string
     */
    protected function col_rating($values) {
        global $PAGE;
        $output = '';
        $renderer = $PAGE->get_renderer('mod_booking');
        if (!empty($values->rating)) {
            $output .= html_writer::tag('div', $renderer->render($values->rating),
                    ['class' => 'booking-option-rating']);
        }
        return $output;
    }

    /**
     * Coursestarttime column.
     * @param mixed $values
     * @return string
     * @throws coding_exception
     */
    protected function col_coursestarttime($values) {
        if ($values->coursestarttime == 0) {
            return '';
        } else {
            return userdate($values->coursestarttime, get_string('strftimedatetime', 'langconfig'));
        }
    }

    /**
     * Courseendtimecolumn.
     * @param mixed $values
     * @return string
     * @throws coding_exception
     */
    protected function col_courseendtime($values) {
        if ($values->courseendtime == 0) {
            return '';
        } else {
            return userdate($values->courseendtime, get_string('strftimedatetime', 'langconfig'));
        }
    }

    /**
     * Waitinglist column.
     * @param mixed $values
     * @return mixed
     * @throws coding_exception
     */
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

    /**
     * City column.
     * @param mixed $values
     * @return mixed
     * @throws coding_exception
     */
    protected function col_city($values) {
        if ($this->is_downloading()) {
            return $values->city;
        }
        return  $values->city;
    }

    /**
     * Selected column.
     * @param mixed $values
     * @return string
     * @throws coding_exception
     */
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
     * Notes column.
     * @param mixed $values
     * @return mixed
     * @throws coding_exception
     */
    protected function col_notes($values) {
        global $PAGE;
        if ($this->is_downloading()) {
            return $values->notes;
        }
        $data = [];
        $data['baid'] = $values->id;
        $data['note'] = $values->notes;
        $data['editable'] = true;
        $renderer = $PAGE->get_renderer('mod_booking');
        $renderednote = new report_edit_bookingnotes($data);
        return $renderer->render($renderednote);
    }

    /**
     * Renders image of user.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_userpic($values): string {
        global $PAGE;
        $user = singleton_service::get_instance_of_user($values->userid);
        $userpic = new user_picture($user);
        $userpic->size = 200;
        $userpictureurl = $userpic->get_url($PAGE);
        return html_writer::img(
            $userpictureurl, "link", ['height' => 100]);
    }

    /**
     * Renders image of user.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_indexnumber($values): string {
        $optionid = $values->optionid;
        return singleton_service::get_index_number($this->uniqueid . $optionid, $values->id);
    }

    /**
     * This function is called for each data row to allow processing of columns which do not have a *_cols function.
     * @param mixed $colname
     * @param mixed $value
     * @return string|void
     * @throws coding_exception
     */
    public function other_cols($colname, $value) {
        if (substr($colname, 0, 4) === "cust") {
            $tmp = explode('|', $value->{$colname} ?? '');

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
        } else if (substr($colname, 0, 10) === "formfield_") {
            $settings = singleton_service::get_instance_of_booking_option_settings((int)$value->optionid);
            $ba = singleton_service::get_instance_of_booking_answers($settings);

            if (
                $answer = $ba->usersonlist[(int)$value->userid]
                ?? $ba->usersonwaitinglist[(int)$value->userid]
                ?? false
            ) {
                [$prefix, $counter] = explode('_', $colname);

                if (
                    isset($answer->json) &&
                    $jsonobject = json_decode($answer->json)) {
                    if (isset($jsonobject->condition_customform)) {
                        foreach ($jsonobject->condition_customform as $key => $value) {

                            $array = explode('_', $key);
                            if (isset($array[2]) &&  $array[2] == $counter) {
                                return "$value";
                            }
                        }
                    }
                }
            }
            return '';
        }
    }

    /**
     *
     * {@inheritDoc}
     * @see \flexible_table::wrap_html_start()
     */
    public function wrap_html_start() {
        echo '<form method="post" id="studentsform">' . "\n";
        $ratingoptions = $this->ratingoptions;
        if (!empty($ratingoptions)) {
            foreach ($ratingoptions as $name => $value) {
                $attributes = ['type' => 'hidden', 'class' => 'ratinginput', 'name' => $name, 'value' => $value];
                echo html_writer::empty_tag('input', $attributes);
            }
        }
    }

    /**
     *
     * {@inheritDoc}
     * @see \flexible_table::wrap_html_finish()
     */
    public function wrap_html_finish() {
        global $DB;
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        if (!$this->bookingdata->booking->settings->autoenrol &&
                 has_capability('mod/booking:communicate', \context_module::instance($this->cm->id)) &&
                 $this->bookingdata->option->courseid > 0) {
            echo '<div class="singlebutton">' .
                '<input type="submit" class="btn btn-secondary btn-sm" name="subscribetocourse" value="' .
                get_string('subscribetocourse', 'booking') . '" /></div>';
        }

        if (has_capability('mod/booking:deleteresponses', \context_module::instance($this->cm->id))) {
            echo '<div class="singlebutton"><input type="submit" class="btn btn-danger btn-sm" name="deleteusers" value="' .
                get_string('booking:deleteresponses', 'booking') . '" /></div>';
            if ($this->bookingdata->booking->settings->completionmodule > 0) {
                $result = $DB->get_record_sql(
                    'SELECT cm.id, cm.course, cm.module, cm.instance, m.name
                FROM {course_modules} cm LEFT JOIN {modules} m ON m.id = cm.module WHERE cm.id = ?',
                    [$this->bookingdata->booking->settings->completionmodule]);
                if ($result) {
                    $dynamicactivitymodulesdata = $DB->get_record($result->name,
                        ['id' => $result->instance]);
                    echo '<div class="singlebutton">' .
                        '<input type="submit" class="btn btn-danger btn-sm" name="deleteusersactivitycompletion" value="' .
                        get_string('deleteresponsesactivitycompletion', 'booking',
                            $dynamicactivitymodulesdata->name) . '" /></div>';
                }
            }
        }

        if (has_capability('mod/booking:communicate', \context_module::instance($this->cm->id))) {
            // PHP 8.1 compatibility with extra safety if poolurl has changed outside option form.
            $pollurl = '';
            if (!empty($this->bookingdata->option->pollurl)) {
                $pollurl = trim($this->bookingdata->option->pollurl);
            }
            if (!empty($pollurl)) {
                echo '<div class="singlebutton"><input type="submit" class="btn btn-primary btn-sm" name="sendpollurl" value="' .
                         get_string('booking:sendpollurl', 'booking') . '" /></div>';
            }
            echo '<div class="singlebutton"><input type="submit" class="btn btn-primary btn-sm" name="sendreminderemail" value="' .
                     get_string('sendreminderemail', 'booking') . '" /></div>';
            echo '<div class="singlebutton"><input type="submit" class="btn btn-primary btn-sm" name="sendcustommsg" value="' .
                     get_string('sendcustommsg', 'booking') . '" /></div>';
        }

        if (booking_check_if_teacher($this->bookingdata->option) ||
                 has_capability('mod/booking:updatebooking',
                        \context_module::instance($this->cm->id))) {
                            $course = $DB->get_record('course', ['id' => $this->bookingdata->booking->settings->course]);
            if (strpos($this->bookingdata->booking->settings->responsesfields, 'completed') !== false) {
                echo '<div class="singlebutton">' .
                '<input type="submit"  class="btn btn-success btn-sm" name="activitycompletion" value="' .
                (empty($this->bookingdata->booking->settings->btncacname) ? get_string(
                'confirmoptioncompletion', 'booking') : $this->bookingdata->booking->settings->btncacname) .
                '" /></div>';
            }

            // Output rating button.
            if (has_capability('moodle/rating:rate', \context_module::instance($this->cm->id)) &&
                     $this->bookingdata->booking->settings->assessed != 0) {
                $ratingbutton = '<div class="singlebutton">' . html_writer::start_tag('span', ['class' => "ratingsubmit"]);
                $attributes = ['type' => 'submit',
                    'class' => 'postratingmenusubmit btn btn-secondary btn-sm',
                    'id' => 'postratingsubmit',
                    'name' => 'postratingsubmit',
                    'value' => s(get_string('rate', 'rating')),
                ];
                $ratingbutton .= html_writer::empty_tag('input', $attributes);
                $ratingbutton .= html_writer::end_span() . '</div>';
                echo $ratingbutton;
            }

            // Output transfer users to other option.
            if (has_capability('mod/booking:bookforothers', \context_module::instance($this->cm->id)) &&
                (has_capability('mod/booking:subscribeusers', \context_module::instance($this->cm->id)) ||
                booking_check_if_teacher($this->bookingdata->option))) {
                if (has_capability('mod/booking:subscribeusers',
                        \context_module::instance($this->cm->id))) {
                            $optionids = \mod_booking\booking::get_all_optionids($this->bookingdata->booking->id);
                } else {
                    $optionids = \mod_booking\booking::get_all_optionids_of_teacher($this->bookingdata->booking->id);
                }
                $optionids = array_values(array_diff($optionids, [$this->optionid]));
                if (!empty($optionids)) {
                    list($insql, $inparams) = $DB->get_in_or_equal($optionids);
                    $options = $DB->get_records_select('booking_options', "id {$insql}",
                            $inparams, '', 'id,text,coursestarttime,location');
                    $transferto = [];
                    foreach ($options as $key => $value) {
                        $string = [];
                        $string[] = $value->text;
                        if ($value->coursestarttime != 0) {
                            $string[] = userdate($value->coursestarttime);
                        }
                        if ($value->location != '') {
                            $string[] = $value->location;
                        }
                        $transferto[$value->id] = implode(', ', $string);
                    }
                    $optionbutton = '<div class="singlebutton">' . \html_writer::start_tag('span',
                            ['class' => "transfersubmit"]);
                    echo \html_writer::div(get_string('transferheading', 'mod_booking'), 'mt-2');
                    echo $dropdown = \html_writer::select($transferto, 'transferoption');
                    $attributes = ['type' => 'submit',
                        'class' => 'transfersubmit btn btn-secondary btn-sm',
                        'id' => 'transfersubmit',
                        'name' => 'transfersubmit',
                        'value' => s(get_string('transfer', 'mod_booking')),
                    ];
                    $optionbutton .= html_writer::empty_tag('input', $attributes);
                    $optionbutton .= html_writer::end_span() . '</div>';
                    echo $optionbutton;
                }
            }

            if ($this->bookingdata->booking->settings->numgenerator) {
                echo '<div class="singlebutton">' .
                    '<input type="submit" class="btn btn-secondary btn-sm" name="generaterecnum" value="' .
                    get_string('generaterecnum', 'booking') . '" onclick="return confirm(\'' .
                    get_string('generaterecnumareyousure', 'booking') . '\')"/></div>';
            }

            $connectedbooking = $DB->get_record("booking",
                    ['conectedbooking' => $this->bookingdata->booking->settings->id], 'id',
                    IGNORE_MULTIPLE);

            if ($connectedbooking) {

                $nolimits = $DB->get_records_sql(
                        "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ?",
                        [$connectedbooking->id]);

                if (!$nolimits) {
                    $result = $DB->get_records_select("booking_options",
                            "bookingid = {$connectedbooking->id} AND id <> {$this->optionid}", null,
                            'text ASC', 'id, text');

                    $options = [];

                    foreach ($result as $value) {
                        $options[$value->id] = $value->text;
                    }

                    echo "<br>";

                    echo html_writer::select($options, 'selectoptionid', '');

                    $label = (empty(
                            $this->bookingdata->booking->settings->booktootherbooking) ? get_string(
                            'booktootherbooking', 'booking') : $this->bookingdata->booking->settings->booktootherbooking);

                    echo '<div class="singlebutton">' .
                        '<input type="submit" class="btn btn-secondary btn-sm" name="booktootherbooking" value="' .
                             $label . '" /></div>';
                } else {
                    $alllimits = $DB->get_records_sql(
                            "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ? AND bo.otheroptionid = ?",
                            [$connectedbooking->id, $this->optionid]);

                    if ($alllimits) {
                        $options = [];

                        foreach ($alllimits as $value) {
                            $options[$value->optionid] = $value->text;
                        }

                        echo "<br>";

                        echo html_writer::select($options, 'selectoptionid', '');

                        $label = (empty(
                                $this->bookingdata->booking->settings->booktootherbooking) ? get_string(
                                'booktootherbooking', 'booking') : $this->bookingdata->booking->settings->booktootherbooking);

                        echo '<div class="singlebutton">' .
                            '<input type="submit" class="btn btn-warning btn-sm" name="booktootherbooking" value="' .
                            $label . '" /></div>';
                    }
                }
            }

            if ($this->bookingdata->booking->settings->enablepresence) {
                // Change presence status.
                // Status order: Unknown, Attending, Complete, Incomplete, No Show, and Failed.
                echo "<br>";
                $presences = [5 => get_string('statusunknown', 'booking'),
                    6 => get_string('statusattending', 'booking'),
                    1 => get_string('statuscomplete', 'booking'),
                    2 => get_string('statusincomplete', 'booking'),
                    3 => get_string('statusnoshow', 'booking'),
                    4 => get_string('statusfailed', 'booking'),
                ];

                echo html_writer::select($presences, 'selectpresencestatus', '', ['' => 'choosedots'],
                    ['class' => 'mt-3']);

                echo '<div class="singlebutton ml-2">' .
                    '<input type="submit" class="btn btn-success btn-sm mt-3" name="changepresencestatus" value="' .
                    get_string('confirmpresence', 'booking') . '" /></div>';
            }
        }

        echo '</form>';

        echo '<hr>';
    }
}
