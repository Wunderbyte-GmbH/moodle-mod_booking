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
namespace mod_booking;
use mod_booking\output\report_edit_bookingnotes;
use html_writer;
use moodle_url;
defined('MOODLE_INTERNAL') || die();
require_once('../../lib/tablelib.php');

/**
 * Displays all bookings for a booking option
 * @author Andraž Prinčič, David Bogner
 *
 */
class all_userbookings extends \table_sql {

    /**
     * @var booking_option|null
     */
    public $bookingdata = null;

    /**
     * @var \   course_module|\stdClass|null
     */
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
     * @param int $uniqueid all tables have to have a unique id, this is used as a key when
     * storing table properties like sort order in the session.
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

    public function col_fullname($values) {
        if (empty($values->otheroptions)) {
            return html_writer::link(
                    new moodle_url('/user/profile.php', array('id' => $values->userid)),
                    "{$values->firstname} {$values->lastname} ({$values->username})", array());
        } else {
            return html_writer::link(
                    new moodle_url('/user/profile.php', array('id' => $values->userid)),
                    "{$values->firstname} {$values->lastname} ({$values->username})", array()) .
                     "&nbsp;({$values->otheroptions})";
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

    protected function col_city($values) {
        if ($this->is_downloading()) {
            return $values->city;
        }
        return  $values->city;
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

    protected function col_notes($values) {
        global $PAGE;
        if ($this->is_downloading()) {
            return $values->notes;
        }
        $data = array();
        $data['baid'] = $values->id;
        $data['note'] = $values->notes;
        $data['editable'] = true;
        $renderer = $PAGE->get_renderer('mod_booking');
        $renderednote = new report_edit_bookingnotes($data);
        return $renderer->render($renderednote);
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
     * @see \flexible_table::wrap_html_start()
     */
    public function wrap_html_start() {
        echo '<form method="post" id="studentsform" class="mform">' . "\n";
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
     * @see \flexible_table::wrap_html_finish()
     */
    public function wrap_html_finish() {
        global $DB, $OUTPUT;

        $manageusersoptions = [];

        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        if (!$this->bookingdata->booking->settings->autoenrol &&
                 has_capability('mod/booking:communicate', \context_module::instance($this->cm->id)) &&
                 $this->bookingdata->option->courseid > 0) {
                    $manageusersoptions[] = [
                        'value' => 'subscribetocourse',
                        'label' => get_string('subscribetocourse', 'booking')
                    ];
        }

        if (has_capability('mod/booking:deleteresponses', \context_module::instance($this->cm->id))) {
            $manageusersoptions[] = [
                'value' => 'deleteusers',
                'label' => get_string('booking:deleteresponses', 'booking')
            ];
            if ($this->bookingdata->booking->settings->completionmodule > 0) {
                $result = $DB->get_record_sql(
                    'SELECT cm.id, cm.course, cm.module, cm.instance, m.name
                FROM {course_modules} cm LEFT JOIN {modules} m ON m.id = cm.module WHERE cm.id = ?',
                    array($this->bookingdata->booking->settings->completionmodule));
                if ($result) {
                    $dynamicactivitymodulesdata = $DB->get_record($result->name,
                        array('id' => $result->instance));
<<<<<<< HEAD
                    echo '<div class="singlebutton">' .
                        '<input type="submit" class="btn btn-secondary" name="deleteusersactivitycompletion" value="' .
                        get_string('deleteresponsesactivitycompletion', 'booking',
                            $dynamicactivitymodulesdata->name) . '" /></div>';
=======
                        $manageusersoptions[] = [
                            'value' => 'deleteusersactivitycompletion',
                            'label' => get_string('deleteresponsesactivitycompletion', 'booking', $dynamicactivitymodulesdata->name)
                        ];
>>>>>>> 71a2499... UI enhancements and integration with tool_certificate.
                }
            }
        }

        if (has_capability('mod/booking:communicate', \context_module::instance($this->cm->id))) {
            if (!empty(trim($this->bookingdata->option->pollurl))) {
                $manageusersoptions[] = [
                    'value' => 'sendpollurl',
                    'label' => get_string('booking:sendpollurl', 'booking')
                ];
            }
            $manageusersoptions[] = [
                'value' => 'sendreminderemail',
                'label' => get_string('sendreminderemail', 'booking')
            ];
            $manageusersoptions[] = [
                'value' => 'sendcustommessage',
                'label' => get_string('sendcustommessage', 'booking')
            ];
        }

        if (booking_check_if_teacher($this->bookingdata->option) ||
                 has_capability('mod/booking:updatebooking',
                        \context_module::instance($this->cm->id))) {
            if (strpos($this->bookingdata->booking->settings->responsesfields, 'completed') !== false) {
                $manageusersoptions[] = [
                    'value' => 'activitycompletion',
                    'label' => (empty($this->bookingdata->booking->settings->btncacname) ? get_string('confirmoptioncompletion', 'booking') : $this->bookingdata->booking->settings->btncacname)
                ];
            }

            // Output rating button.
            if (has_capability('moodle/rating:rate', \context_module::instance($this->cm->id)) &&
                     $this->bookingdata->booking->settings->assessed != 0) {
                $ratingbutton = '<div class="singlebutton">' . html_writer::start_tag('span', array('class' => "ratingsubmit"));
                $attributes = array('type' => 'submit', 'class' => 'postratingmenusubmit btn btn-secondary',
                    'id' => 'postratingsubmit', 'name' => 'postratingsubmit',
                    'value' => s(get_string('rate', 'rating')));
                $ratingbutton .= html_writer::empty_tag('input', $attributes);
                $ratingbutton .= html_writer::end_span() . '</div>';
                echo $ratingbutton;
            }
        }

        $optgroups = [];

        // Issue certificate
        if (has_capability ( 'mod/booking:readresponses', \context_module::instance($this->cm->id) ) || booking_check_if_teacher ($option )) {
            if (!empty($this->bookingdata->booking->settings->template)) {
                $optgroups[] = [
                    'label' => get_string('issuecertificate', 'booking'),
                    'options' => [
                        ['label' => get_string('issuecertificateall', 'booking'), 'value' => 'issuecertificateall'],
                        ['label' => get_string('issuecertificateselected', 'booking'), 'value' => 'issuecertificateselected'],
                        ['label' => get_string('issuecertificateconfirmed', 'booking'), 'value' => 'issuecertificateconfirmed']
                    ]
                ];
            }
        }

        if ($this->bookingdata->booking->settings->numgenerator) {
            $manageusersoptions[] = [
                'value' => 'generaterecnum',
                'label' => get_string('generaterecnum', 'booking')
            ];
        }

        $availableoptions = '';
        $transferto = '';
        $presencestatus = '';
        $connectedbookings = '';

        if (booking_check_if_teacher($this->bookingdata->option) ||
                 has_capability('mod/booking:updatebooking',
                        \context_module::instance($this->cm->id))) {
            // Output transfer users to other option.
            if (has_capability('mod/booking:subscribeusers',
                    \context_module::instance($this->cm->id)) || booking_check_if_teacher(
                            $this->bookingdata->option)) {
                if (has_capability('mod/booking:subscribeusers',
                        \context_module::instance($this->cm->id))) {
                            $optionids = \mod_booking\booking::get_all_optionids($this->bookingdata->booking->id);
                } else {
                    $optionids = \mod_booking\booking::get_all_optionids_of_teacher($this->bookingdata->booking->id);
                }
                $optionids = array_values(array_diff($optionids, array($this->optionid)));
                if (!empty($optionids)) {
                    list($insql, $inparams) = $DB->get_in_or_equal($optionids);
                    $options = $DB->get_records_select('booking_options', "id {$insql}",
                            $inparams, '', 'id,text,coursestarttime,location');
                    $transferto = [];
                    foreach ($options as $key => $value) {
                        $string = array();
                        $string[] = $value->text;
                        if ($value->coursestarttime != 0) {
                            $string[] = userdate($value->coursestarttime);
                        }
                        if ($value->location != '') {
                            $string[] = $value->location;
                        }
<<<<<<< HEAD
                        $transferto[$value->id] = implode(', ', $string);
=======
                        $transferto[] = [
                            'value' => $value->id,
                            'label' => implode($string, ', ')
                        ];
>>>>>>> 71a2499... UI enhancements and integration with tool_certificate.
                    }

                    $manageusersoptions[] = [
                        'value' => 'transferheading',
                        'label' => get_string('transferheading', 'booking')
                    ];

                    array_unshift($transferto, ['value' => '', 'label' => '']);

                    $data = array(
                        'label' => '',
                        'name' => 'transferoption',
                        'options' => $transferto,
                        'submit' => s(get_string('transfer', 'mod_booking')),
                        'style' => 'display: none;'
                    );

                    $transferto = $OUTPUT->render_from_template('booking/dataformat_selector', $data);
                }
            }

            $connectedbooking = $DB->get_record("booking",
                    array('conectedbooking' => $this->bookingdata->booking->settings->id), 'id',
                    IGNORE_MULTIPLE);

            if ($connectedbooking) {
                $nolimits = $DB->get_records_sql(
                        "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ?",
                        array($connectedbooking->id));

                    $options = [];

                if (!$nolimits) {
                    $result = $DB->get_records_select("booking_options",
                            "bookingid = {$connectedbooking->id} AND id <> {$this->optionid}", null,
                            'text ASC', 'id, text');

                    foreach ($result as $value) {
                        $options[] = [
                            'label' => $value->text,
                            'value' => $value->id
                        ];
                    }
<<<<<<< HEAD

                    echo "<br>";

                    echo html_writer::select($options, 'selectoptionid', '');

                    $label = (empty(
                            $this->bookingdata->booking->settings->booktootherbooking) ? get_string(
                            'booktootherbooking', 'booking') : $this->bookingdata->booking->settings->booktootherbooking);

                    echo '<div class="singlebutton">' .
                        '<input type="submit" class="btn btn-secondary" name="booktootherbooking" value="' .
                             $label . '" /></div>';
=======
>>>>>>> 71a2499... UI enhancements and integration with tool_certificate.
                } else {
                    $alllimits = $DB->get_records_sql(
                            "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ? AND bo.otheroptionid = ?",
                            array($connectedbooking->id, $this->optionid));

                    if ($alllimits) {
                        foreach ($alllimits as $value) {
                            $options[] = [
                                'label' => $value->text,
                                'value' => $value->optionid
                            ];
                        }
<<<<<<< HEAD

                        echo "<br>";

                        echo html_writer::select($options, 'selectoptionid', '');

                        $label = (empty(
                                $this->bookingdata->booking->settings->booktootherbooking) ? get_string(
                                'booktootherbooking', 'booking') : $this->bookingdata->booking->settings->booktootherbooking);

                        echo '<div class="singlebutton">' .
                            '<input type="submit" class="btn btn-secondary" name="booktootherbooking" value="' .
                                 $label . '" /></div>';
=======
>>>>>>> 71a2499... UI enhancements and integration with tool_certificate.
                    }
                }

                $label = (empty(
                    $this->bookingdata->booking->settings->booktootherbooking) ? get_string(
                    'booktootherbooking', 'booking') : $this->bookingdata->booking->settings->booktootherbooking);

                if (!empty($options)) {
                    array_unshift($options, ['value' => '', 'label' => '']);

                    $data = array(
                        'label' => '',
                        'name' => 'booktootherbooking',
                        'options' => $options,
                        'submit' => s(get_string('transfer', 'mod_booking')),
                        'style' => 'display: none;'
                    );

                    $connectedbookings = $OUTPUT->render_from_template('booking/dataformat_selector', $data);
                    $manageusersoptions[] = [
                        'value' => 'connectedbookings',
                        'label' => $label
                    ];
                }
            }

            if ($this->bookingdata->booking->settings->enablepresence) {
                $presences = [
                    [
                        'value' => 5,
                        'label' => get_string('status_unknown', 'booking')
                    ],
                    [
                        'value' => 6,
                        'label' => get_string('status_attending', 'booking')
                    ],
                    [
                        'value' => 1,
                        'label' => get_string('status_complete', 'booking')
                    ],
                    [
                        'value' => 2,
                        'label' => get_string('status_incomplete', 'booking')
                    ],
                    [
                        'value' => 3,
                        'label' => get_string('status_noshow', 'booking')
                    ],
                    [
                        'value' => 4,
                        'label' => get_string('status_failed', 'booking')
                    ]
                ];

                $manageusersoptions[] = [
                    'value' => 'changepresencestatus',
                    'label' => get_string('presence', 'booking')
                ];

                array_unshift($presences, ['value' => '', 'label' => '']);

                $data = array(
                    'label' => '',
                    'name' => 'selectpresencestatus',
                    'options' => $presences,
                    'submit' => s(get_string('confirmpresence', 'booking')),
                    'style' => 'display: none;'
                );

                $presencestatus = $OUTPUT->render_from_template('booking/dataformat_selector', $data);
            }
        }

        if (!empty($manageusersoptions)) {
            array_unshift($manageusersoptions, ['value' => '', 'label' => '']);
            $data = array(
                'label' => get_string('selectaction', 'mod_booking'),
                'base' => '',
                'name' => 'massactions',
                'params' => [],
                'options' => $manageusersoptions,
                'optgroups' => $optgroups,
                'submit' => s(get_string('submit')),
            );

            $availableoptions = $OUTPUT->render_from_template('booking/dataformat_selector', $data);
        }

        echo '<br>';
        echo '<div class="container-fluid">';
        echo '  <div class="row">';
        echo '      <div class="col-6">';
        echo            $availableoptions;
        echo '      </div>';
        echo '      <div class="col-6">';
        echo            $transferto;
        echo            $presencestatus;
        echo            $connectedbookings;
        echo '      </div>';
        echo '  </div>';
        echo '</div>';

        echo '</form>';

        echo '<hr>';
    }
}
