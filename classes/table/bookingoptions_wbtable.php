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

use coding_exception;
use comment;
use context_course;
use context_module;
use dml_exception;
use html_writer;
use local_wunderbyte_table\wunderbyte_table;
use moodle_exception;
use moodle_url;
use stdClass;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\booking_option;
use mod_booking\dates_handler;
use mod_booking\output\col_availableplaces;
use mod_booking\output\col_teacher;
use mod_booking\price;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 */
class bookingoptions_wbtable extends wunderbyte_table {

    /** @var int $cmid */
    private $cmid = null;

    /** @var booking $booking */
    private $booking = null;

    /** @var stdClass $buyforuser */
    private $buyforuser = null;

    /** @var context_module $buyforuser */
    private $context = null;

    /** @var object $cm */
    private $cm = null;

    /** @var object $course */
    private $course = null;

    /** @var string $returnurl */
    private $returnurl = '';

    /**
     * Constructor
     * @param string $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     * @param booking $booking the booking instance
     */
    public function __construct(string $uniqueid, booking $booking = null) {
        parent::__construct($uniqueid);

        if (!empty($booking)) {
            $this->booking = $booking;
            $this->cmid = $this->booking->cmid;
            $this->context = context_module::instance($this->cmid);
            list($this->course, $this->cm) = get_course_and_cm_from_cmid($this->cmid);
        }

        // We set buyforuser here for better performance.
        $this->buyforuser = price::return_user_to_buy_for();

        // Columns and headers are not defined in constructor, in order to keep things as generic as possible.
    }

    /**
     * This function is called for each data row to allow processing of the
     * invisible value. It's called 'invisibleoption' so it does not interfere with
     * the bootstrap class 'invisible'.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $invisible Returns visibility of the booking option as string.
     * @throws coding_exception
     */
    public function col_invisibleoption($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);

        if (!empty($settings->invisible)) {
            return get_string('invisibleoption', 'mod_booking');
        } else {
            return '';
        }
    }

    public function col_image($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);

        if (empty($settings->imageurl)) {
            return null;
        }

        return $settings->imageurl;
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
        $output = $PAGE->get_renderer('mod_booking');

        // Render col_teacher using a template.
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);
        $data = new col_teacher($values->id, $settings);
        return $output->render_col_teacher($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * booknow value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    public function col_booknow($values) {

        // Render col_price using a template.
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);

        return booking_bookit::render_bookit_button($settings, 0);
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

        if (empty($this->booking)) {
            $this->booking = singleton_service::get_instance_of_booking_by_optionid($values->id, $values);
        }
        if (empty($this->cmid)) {
            $this->cmid = $this->booking->cmid;
        }
        if (empty($this->context)) {
            $this->context = context_module::instance($this->cmid);
        }

        if ($this->booking) {
            $url = new moodle_url('/mod/booking/optionview.php', ['optionid' => $values->id,
                                                                  'cmid' => $this->booking->cmid,
                                                                  'userid' => $this->buyforuser->id]);
        } else {
            $url = '#';
        }

        $title = $values->text;
        if (!empty($values->titleprefix)) {
            $title = $values->titleprefix . ' - ' . $values->text;
        }

        $title = "<div class='bookingoptions-wbtable-option-title'><a href='$url' target='_blank'>$title</a></div>";

        return $title;
    }

    /**
     * This function is called for each data row to allow processing of the
     * progressbar value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string the progress bar HTML
     * @throws dml_exception
     */
    public function col_progressbar($values) {
        // Progress bar showing the consumed quota visually.
        $progressbarhtml = '';
        if (get_config('booking', 'showprogressbars')) {
            $collapsible = false;
            if (get_config('booking', 'progressbarscollapsible')) {
                $collapsible = true;
            }
            $progressbarhtml = booking_option::get_progressbar_html($values->id, 'primary', 'white', $collapsible);
        }
        return $progressbarhtml;
    }

    /**
     * This function is called for each data row to allow processing of the
     * comments value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string the comments HTML
     * @throws dml_exception
     */
    public function col_comments($values) {

        $commentshtml = '';
        if (!empty($this->cm) && !empty($this->cmid) && !empty($this->context)) {

            // Important: Without init commenting won't work.
            comment::init();

            // Comment booking options.
            $commentoptions = new stdClass();
            $commentoptions->area = 'booking_option';
            $commentoptions->context = $this->context;
            $commentoptions->itemid = $values->id;
            $commentoptions->component = 'mod_booking';
            $commentoptions->showcount = true;
            $commentoptions->displaycancel = true;
            $comment = new comment($commentoptions);
            if (!empty($comment)) {
                $commentshtml = $comment->output(true);
            }
        }
        return $commentshtml;
    }

    /**
     * This function is called for each data row to allow processing of the
     * coursestarttime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $coursestarttime Returns course start time as a readable string.
     * @throws coding_exception
     */
    public function col_bookings($values) {
        global $PAGE;
        $output = $PAGE->get_renderer('mod_booking');

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);
        // Render col_bookings using a template.
        $data = new col_availableplaces($values, $settings, $this->buyforuser);
        return $output->render_col_availableplaces($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * location value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string location
     * @throws coding_exception
     */
    public function col_location($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);

        if (isset($settings->entity) && (count($settings->entity) > 0)) {

            $url = new moodle_url('/local/entities/view.php', ['id' => $settings->entity['id']]);

            // If there is a shortname of the entity, we'll show the shortname, otherwise we show the full name.
            $nametobeshown = $settings->entity['name'];
            if (!empty($settings->entity['shortname'])) {
                $nametobeshown = $settings->entity['shortname'];
            }
            return html_writer::tag('a', $nametobeshown, ['href' => $url->out(false)]);
        }

        return $settings->location;
    }

    /**
     * This function is called for each data row to allow processing of the
     * institution value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string institution
     * @throws coding_exception
     */
    public function col_institution($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);
        return $settings->institution;
    }

    /**
     * This function is called for each data row to allow processing of the
     * associated Moodle course.
     *
     * @param object $values Contains object with all the values of record.
     * @return string a link to the Moodle course - if there is one
     * @throws coding_exception
     */
    public function col_course($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);

        if (empty($settings->courseid)) {
            $ret = '';
        } else {
            $moodleurl = new moodle_url('/course/view.php', ['id' => $settings->courseid]);
            $courseurl = $moodleurl->out(false);
            $gotocoursematerial = get_string('gotocoursematerial', 'local_musi');
            $ret = "<a href='$courseurl' target='_self' class='btn btn-primary mt-2 mb-2 w-100'>
                <i class='fa fa-graduation-cap' aria-hidden='true'></i>&nbsp;&nbsp;$gotocoursematerial
            </a>";
        }

        return $ret;
    }

    /**
     * This function is called for each data row to allow processing of the
     * dayofweektime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $dayofweektime String for date series, e.g. "Mon, 16:00 - 17:00"
     * @throws coding_exception
     */
    public function col_dayofweektime($values) {

        $ret = '';
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);

        $units = null;
        if (!empty($settings->dayofweektime)) {
            $units = dates_handler::calculate_and_render_educational_units($settings->dayofweektime);
        }

        if (!empty($settings->dayofweektime)) {
            $ret = $settings->dayofweektime;
            if (!$this->is_downloading() && !empty($units)) {
                $ret .= " ($units)";
            }
        }

        return $ret;
    }

    /**
     * This function is called for each data row to allow processing of the
     * showdates value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string a string containing collapsible dates
     * @throws coding_exception
     */
    public function col_showdates($values) {

        global $PAGE;

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* if ($this->is_downloading()) {
            if ($values->coursestarttime == 0) {
                return '';
            } else {
                return userdate($values->coursestarttime, get_string('strftimedatetime', 'langconfig'));
            }
        } */

        // Use the renderer to output this column.
        $data = new \mod_booking\output\col_coursestarttime($values->id, $this->booking);
        $output = $PAGE->get_renderer('mod_booking');
        return $output->render_col_coursestarttime($data);
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

        $settings = singleton_service::get_instance_of_booking_option_settings($values->optionid, $values);
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings, 0);

        if (count($bookinganswers->usersonlist) > 0) {
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
        global $OUTPUT, $USER;

        if (empty($this->booking)) {
            $this->booking = singleton_service::get_instance_of_booking_by_optionid($values->id, $values);
        }
        if (empty($this->cmid)) {
            $this->cmid = $this->booking->cmid;
        }
        if (empty($this->context)) {
            $this->context = context_module::instance($this->cmid);
        }

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($values->id);
        $answersobject = singleton_service::get_instance_of_booking_answers($optionsettings);
        $status = $answersobject->user_status($USER->id);

        // Set the returnurl to navigate back to after form is saved.
        $viewphpurl = new moodle_url('/mod/booking/view.php', ['id' => $this->cmid]);
        $returnurl = $viewphpurl->out();

        $ddoptions = array();
        $ret = '<div class="menubar" id="action-menu-' . $values->id . '-menubar" role="menubar">';

        if ($status == STATUSPARAM_BOOKED) {
            $ret .= html_writer::link(
                new moodle_url('/mod/booking/viewconfirmation.php',
                    array('id' => $this->cmid, 'optionid' => $values->id)),
                $OUTPUT->pix_icon('t/print', get_string('bookedtext', 'mod_booking')),
                array('target' => '_blank'));
        }

        if (has_capability('mod/booking:updatebooking', $this->context) || (has_capability(
                    'mod/booking:addeditownoption', $this->context) &&
                booking_check_if_teacher($values))) {
            $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                    new moodle_url('/mod/booking/editoptions.php',
                        ['id' => $this->cmid, 'optionid' => $values->id,
                        'returnto' => 'url',
                        'returnurl' => $returnurl]),
                    $OUTPUT->pix_icon('t/editstring', get_string('updatebooking', 'mod_booking')) .
                    get_string('updatebooking', 'mod_booking')) . '</div>';

            // Multiple dates session.
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link(new moodle_url('/mod/booking/optiondates.php',
                    array('id' => $this->cmid, 'optionid' => $values->id,
                    'returnto' => 'url',
                    'returnurl' => $returnurl)),
                    $OUTPUT->pix_icon('i/scheduled',
                        get_string('optiondatesmanager', 'booking')) .
                    get_string('optiondatesmanager', 'booking')) . '</div>';

            // Book other users.
            if (has_capability('mod/booking:subscribeusers', $this->context) ||
                booking_check_if_teacher($values)) {
                $subscribeusersurl = new moodle_url('/mod/booking/subscribeusers.php',
                    array('id' => $this->cmid, 'optionid' => $values->id,
                    'returnto' => 'url',
                    'returnurl' => $returnurl));
                $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link($subscribeusersurl,
                        $OUTPUT->pix_icon('i/users',
                            get_string('bookotherusers', 'mod_booking')) .
                        get_string('bookotherusers', 'mod_booking')) . '</div>';
            }

            // Show link to optiondates-teachers-report (teacher substitutions).
            $optiondatesteachersmoodleurl = new moodle_url('/mod/booking/optiondates_teachers_report.php',
                ['id' => $this->cmid, 'optionid' => $values->id,
                'returnto' => 'url', 'returnurl' => $returnurl]);
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link($optiondatesteachersmoodleurl,
                    $OUTPUT->pix_icon('i/grades',
                        get_string('optiondatesteachersreport', 'mod_booking')) .
                    get_string('optiondatesteachersreport', 'mod_booking')) . '</div>';

            // Show only one option.
            $onlyoneurl = new moodle_url('/mod/booking/view.php',
                array('id' => $this->cmid, 'optionid' => $values->id,
                    'action' => 'showonlyone', 'whichview' => 'showonlyone'));
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link($onlyoneurl,
                    $OUTPUT->pix_icon('i/publish',
                        get_string('onlythisbookingurl', 'mod_booking')) .
                    get_string('onlythisbookingurl', 'mod_booking')) . '</div>';

            if (has_capability('mod/booking:updatebooking', $this->context)) {
                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(new moodle_url('/mod/booking/report.php',
                        array('id' => $this->cmid, 'optionid' => $values->id, 'action' => 'deletebookingoption',
                            'sesskey' => sesskey(),
                            'returnto' => 'url',
                            'returnurl' => $returnurl)),
                        $OUTPUT->pix_icon('t/delete', get_string('deletethisbookingoption', 'mod_booking')) .
                        get_string('deletethisbookingoption', 'mod_booking')) . '</div>';

                // Cancel booking options.
                // Find out if the booking option has a price or not.
                $optioninfo = $optionsettings->return_booking_option_information();
                $optionhasprice = empty($optioninfo['price']) ? false : true;

                if ($optionhasprice && class_exists('local_shopping_cart\shopping_cart')) {
                    // The option costs something and shopping cart is installed:
                    // We have to cancel the shopping-cart way!
                    if ($values->status == 1) {
                        // If booking option is already cancelled, we want to show the "undo cancel" button.
                        $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                            $OUTPUT->pix_icon('i/reload', '') .
                            get_string('undocancelthisbookingoption', 'mod_booking'),
                            [
                                'class' => 'undocancelallusers',
                                'data-id' => $values->id,
                                'data-componentname' => 'mod_booking',
                                'data-area' => 'option',
                                'onclick' =>
                                    "require(['mod_booking/confirm_cancel'], function(init) {
                                        init.init('" . $values->id . "', '" . $values->status . "');
                                    });"
                            ]) . "</div>";

                    } else {
                        // Else we show the cancel button.
                        $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                            $OUTPUT->pix_icon('t/block', '') .
                            get_string('cancelallusers', 'mod_booking'),
                            [
                                'class' => 'cancelallusers',
                                'data-id' => $values->id,
                                'data-componentname' => 'mod_booking',
                                'data-area' => 'option',
                                'onclick' =>
                                    "require(['local_shopping_cart/menu'], function(menu) {
                                        menu.confirmCancelAllUsersAndSetCreditModal('" . $values->id . "', 'mod_booking', 'option');
                                    });"
                            ]) . "</div>";
                    }

                } else {
                    // The option has no price or shopping cart is not installed, so we cancel the default booking way.
                    if ($values->status == 1) {
                        // If booking option is already cancelled, we want to show the "undo cancel" button.
                        $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                            $OUTPUT->pix_icon('i/reload', '') .
                            get_string('undocancelthisbookingoption', 'mod_booking'),
                            [
                                'onclick' =>
                                    "require(['mod_booking/confirm_cancel'], function(init) {
                                        init.init('" . $values->id . "', '" . $values->status . "');
                                    });"
                            ]) . "</div>";
                    } else {
                        // Else we show the cancel button.
                        $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                            $OUTPUT->pix_icon('t/block', '') .
                            get_string('cancelthisbookingoption', 'mod_booking'),
                            [
                                'onclick' =>
                                    "require(['mod_booking/confirm_cancel'], function(init) {
                                        init.init('" . $values->id . "', '" . $values->status . "');
                                    });"
                            ]) . "</div>";
                    }
                }

                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(new moodle_url('/mod/booking/editoptions.php',
                        array('id' => $this->cmid, 'optionid' => -1, 'copyoptionid' => $values->id,
                        'returnto' => 'url', 'returnurl' => $returnurl)), $OUTPUT->pix_icon('t/copy',
                            get_string('duplicatebooking', 'mod_booking')) .
                        get_string('duplicatebooking', 'mod_booking')) . '</div>';
            }
            $modinfo = get_fast_modinfo($this->booking->course);
            $bookinginstances = isset($modinfo->instances['booking']) ? count($modinfo->instances['booking']) : 0;
            if (has_capability('mod/booking:updatebooking', context_course::instance($this->booking->course->id)) &&
                $bookinginstances > 1) {
                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                        new moodle_url('/mod/booking/moveoption.php',
                            array('id' => $this->cmid, 'optionid' => $values->id, 'sesskey' => sesskey())),
                        $OUTPUT->pix_icon('t/move', get_string('moveoptionto', 'booking')) .
                        get_string('moveoptionto', 'booking')) . '</div>';
            }
        }
        if (!empty($ddoptions)) {
            $ret .= '<div class="dropdown d-inline">
                    <a href="' .
                new moodle_url('/mod/booking/editoptions.php',
                    array('id' => $this->cmid, 'optionid' => $values->id)) .
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

    /**
     * This function is called for each data row to allow processing of the
     * action button.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $action Returns formatted action button.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_actionnew($values) {
        global $PAGE;
        $output = $PAGE->get_renderer('mod_booking');

        if (empty($this->booking)) {
            $this->booking = singleton_service::get_instance_of_booking_by_optionid($values->id, $values);
        }
        if (empty($this->cmid)) {
            $this->cmid = $this->booking->cmid;
        }
        if (empty($this->context)) {
            $this->context = context_module::instance($this->cmid);
        }

        $data = new stdClass();

        $data->id = $values->id;
        $data->componentname = 'mod_booking';
        $data->cmid = $this->cmid;

        // We will have a number of modals on this site, therefore we have to distinguish them.
        // This is in case we render modal.
        $data->modalcounter = $values->id;
        $data->modaltitle = $values->text;
        $data->userid = $this->buyforuser->id;

        // Get the URL to edit the option.
        if (!empty($values->id)) {
            $bosettings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);
            if (!empty($bosettings)) {

                if (!$this->context) {
                    $this->context = context_module::instance($bosettings->cmid);
                }

                // If the user has no capability to editoptions, the URLs will not be added.
                if ((has_capability('mod/booking:updatebooking', $this->context) ||
                        has_capability('mod/booking:addeditownoption', $this->context))) {
                    if (isset($bosettings->editoptionurl)) {
                        // Get the URL to edit the option.

                        $data->editoptionurl = $this->add_return_url($bosettings->editoptionurl);
                    }
                    if (isset($bosettings->manageresponsesurl)) {
                        // Get the URL to manage responses (answers) for the option.
                        $data->manageresponsesurl = $bosettings->manageresponsesurl;
                    }
                    if (isset($bosettings->optiondatesteachersurl)) {
                        // Get the URL for the optiondates-teachers-report.
                        $data->optiondatesteachersurl = $bosettings->optiondatesteachersurl;
                    }
                }
            }
        }

        // If booking option is already cancelled, we want to show the "undo cancel" button instead.
        if ($values->status == 1) {
            $data->showundocancel = true;
            $data->undocancellink = html_writer::link('#',
            '<i class="fa fa-undo" aria-hidden="true"></i> ' .
                get_string('undocancelthisbookingoption', 'mod_booking'),
                [
                    'class' => 'dropdown-item undocancelallusers',
                    'data-id' => $values->id,
                    'data-componentname' => 'mod_booking',
                    'data-area' => 'option',
                    'onclick' =>
                        "require(['mod_booking/confirm_cancel'], function(init) {
                            init.init('" . $values->id . "', '" . $values->status . "');
                        });"
                ]);
        } else {
            // Else we show the default cancel button.
            // We do NOT set $data->undocancel here.
            $data->showcancel = true;
            $data->cancellink = html_writer::link('#',
            '<i class="fa fa-ban" aria-hidden="true"></i> ' .
                get_string('cancelallusers', 'mod_booking'),
                [
                    'class' => 'dropdown-item cancelallusers',
                    'data-id' => $values->id,
                    'data-componentname' => 'mod_booking',
                    'data-area' => 'option',
                    'onclick' =>
                        "require(['local_shopping_cart/menu'], function(menu) {
                            menu.confirmCancelAllUsersAndSetCreditModal('" . $values->id . "', 'mod_booking', 'option');
                        });"
                ]);
        }

        return $output->render_col_text_link($data);
    }

    private function add_return_url(string $urlstring):string {

        $returnurl = $this->baseurl->out();

        $urlcomponents = parse_url($urlstring);

        parse_str($urlcomponents['query'], $params);

        $url = new moodle_url(
            $urlcomponents['path'],
            array_merge(
                $params, [
                'returnto' => 'url',
                'returnurl' => $returnurl
                ]
            )
        );

        return $url->out(false);
    }
}
