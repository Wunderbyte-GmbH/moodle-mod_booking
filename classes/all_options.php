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
use html_writer;
use moodle_url;
use stdClass;
use table_sql;
use mod_booking\booking;
use mod_booking\places;
use mod_booking\booking_tags;

defined('MOODLE_INTERNAL') || die();

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
                break;
            case 1:
                return get_string('status_complete', 'booking');
                break;
            case 2:
                return get_string('status_incomplete', 'booking');
                break;
            case 3:
                return get_string('status_noshow', 'booking');
                break;
            case 4:
                return get_string('status_failed', 'booking');
                break;
            case 5:
                return get_string('status_unknown', 'booking');
                break;
            case 6:
                return get_string('status_attending', 'booking');
                break;
            default:
                return '';
                break;
        }
    }

    protected function col_coursestarttime($values) {
        if ($this->is_downloading()) {
            if ($values->coursestarttime == 0) {
                return '';
            } else {
                return userdate($values->coursestarttime, get_string('strftimedatetime'));
            }
        }

        if ($values->coursestarttime == 0) {
            return get_string('datenotset', 'booking');
        } else {
            if (!isset($values->timeobjects)) {
                return userdate($values->coursestarttime) . " -<br>" .
                    userdate($values->courseendtime);
            } else {
                return $this->render_sessions_with_customfields($values);
            }
        }
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

            if ($customfields) {
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
        global $DB;

        // Get description mode (modal or inline) from instance settings.
        $showfulldescription = $this->booking->settings->showdescriptionmode;

        $output = '';
        $output .= html_writer::tag('h4', format_string($values->text, true, $this->booking->settings->course));
        $style = 'display: none;';
        $th = '';
        $ts = '"display: none;"';
        if (isset($_GET['whichview']) && $_GET['whichview'] == 'showonlyone') {
            $style = '';
            $th = '"display: none;"';
            $ts = '';
        }

        if (strlen($values->address) > 0) {
            $output .= html_writer::empty_tag('br');
            $output .= get_string('address', 'booking') . ': ' . $values->address;
        }
        if (strlen($values->location) > 0) {
            $output .= html_writer::empty_tag('br');
            $lbllocation = $this->booking->settings->lbllocation;
            $output .= (empty($lbllocation) ? get_string('location', 'booking') : $lbllocation) . ': ' . $values->location;
        }
        if (strlen($values->institution) > 0) {
            $output .= html_writer::empty_tag('br');
            $lblinstitution = $this->booking->settings->lblinstitution;
            $output .= (empty($lblinstitution) ? get_string('institution', 'booking') : $lblinstitution) . ': ' .
                $values->institution;
        }

        if (!empty($values->description)) {
            $values->description = $this->tags->tag_replaces($values->description);

            if (isset($showfulldescription) && $showfulldescription == 1) {
                // Show the full description without the show/hide link.
                $output .= html_writer::div(format_text($values->description, FORMAT_HTML), 'optiontext',
                    array('style' => '', 'id' => 'optiontextdes' . $values->id));
            } else {
                // Info links with modal are the default.
                $output .= '<br><a href="#" data-toggle="modal" data-target="#descriptionModal"><i class="fa fa-info-circle fa-lg"></i></a>
                            <div class="modal fade" id="descriptionModal" tabindex="-1" role="dialog" aria-labelledby="descriptionModalLabel" aria-hidden="true">
                              <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                  <div class="modal-header">
                                    <h5 class="modal-title" id="descriptionModalLabel">' . $values->text . '</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                      <span aria-hidden="true">&times;</span>
                                    </button>
                                  </div>
                                  <div class="modal-body">' .
                                    $values->description .
                                  '</div>
                                </div>
                              </div>
                            </div>';
            }
            //else {
                // Show the show/hide link (description hidden by default).
                /*$showhidetext = '<span id="showtextdes' . $values->id . '" style=' . $th . '>' . get_string(
                        'showdescription', "mod_booking") . '</span><span id="hidetextdes' . $values->id . '" style=' . $ts . '>' .
                    get_string(
                        'hidedescription', "mod_booking") . '</span>';

                $output .= '<br><a href="#" class="showHideOptionText" data-id="des' . $values->id . '">' .
                    $showhidetext . "</a>";
                $output .= html_writer::div(format_text($values->description, FORMAT_HTML), 'optiontext',
                    array('style' => $style, 'id' => 'optiontextdes' . $values->id));
            }*/
        }

        $lblteach = $this->booking->settings->lblteachname;
        $output .= (!empty($values->teachers) ? " <br />" . (empty($lblteach) ? get_string('teachers', 'booking') : $lblteach) .
            ": " . $values->teachers : '');

        // Custom fields.
        $customfields = $DB->get_records('booking_customfields', array('optionid' => $values->id));
        $customfieldcfg = \mod_booking\booking_option::get_customfield_settings();
        if ($customfields && !empty($customfieldcfg)) {
            foreach ($customfields as $field) {
                if (!empty($field->value)) {
                    $cfgvalue = $customfieldcfg[$field->cfgname]['value'];
                    if ($customfieldcfg[$field->cfgname]['type'] == 'multiselect') {
                        $tmpdata = implode(", ", explode("\n", $field->value));
                        $output .= "<br> <b>$cfgvalue: </b>$tmpdata";
                    } else {
                        $output .= "<br> <b>$cfgvalue: </b>$field->value";
                    }
                }
            }
        }

        // Show text.
        $texttoshow = "";
        $bookingdata = new \mod_booking\booking_option($this->cm->id, $values->id);
        $texttoshow = $bookingdata->get_option_text();
        $texttoshow = $this->tags->tag_replaces($texttoshow);

        $showhidetext = '<span id="showtext' . $values->id . '" style=' . $th . '>' . get_string(
                'showdescription', "mod_booking") . '</span><span id="hidetext' . $values->id . '" style=' . $ts . '>' . get_string(
                'hidedescription', "mod_booking") . '</span>';

        if (!empty($texttoshow)) {
            if (isset($showfulldescription) && $showfulldescription == 1) {
                // Show the full description without the show/hide link.
                $output .= html_writer::div($texttoshow, 'optiontext', array('style' => '',
                    'id' => 'optiontext' . $values->id));
            } else {
                // Show the show/hide link (description hidden by default).
                $output .= '<br><a href="#" class="showHideOptionText" data-id="' . $values->id . '">' .
                $showhidetext . "</a>";
                $output .= html_writer::div($texttoshow, 'optiontext', array('style' => $style,
                                                                             'id' => 'optiontext' . $values->id));
            }
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'mod_booking', 'myfilemanageroption',
            $values->id);

        if (count($files) > 0) {
            $output .= html_writer::start_tag('div');
            $output .= html_writer::tag('label', get_string("attachedfiles", "booking") . ': ',
                array('class' => 'bold'));

            foreach ($files as $file) {
                if ($file->get_filesize() > 0) {
                    $filename = $file->get_filename();
                    $furl = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
                        $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename(), false);
                    $out[] = html_writer::link($furl, $filename);
                }
            }
            $output .= html_writer::tag('span', implode(', ', $out));
            $output .= html_writer::end_tag('div');
        }

        $options = new stdClass();
        $options->area = 'booking_option';
        $options->context = $this->context;
        $options->cm = $this->cm;
        $options->itemid = $values->id;
        $options->component = 'mod_booking';
        $options->client_id = "client_{$values->id}";
        $options->showcount = true;
        $comment = new comment($options);
        $output .= $comment->output(true);

        return $output;
    }

    protected function col_description($values) {
        $output = '';
        if (!empty($values->description)) {
            $output .= html_writer::div($this->format_text($values->description), 'courseinfo');
        }

        return $output;
    }

    protected function col_availableplaces($values) {

        // We moved this code to booking_utils so it's available outside of table_sql

        $utils = new booking_utils;
        return $utils->return_button_based_on_record($this->booking, $this->context, $values);
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
