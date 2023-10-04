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
use mod_booking\option\dates_handler;
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
    public $cmid = null;

    /** @var booking $booking */
    public $booking = null;

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
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);
        $ret = '';

        if ($this->is_downloading()) {
            // When we download, we want to render teachers as plain text.
            if (!empty($settings->teachers)) {
                $teacherstrings = [];
                foreach ($settings->teachers as $teacher) {
                    $teacherstrings[] = "$teacher->firstname $teacher->lastname ($teacher->email)";
                }
                $ret = implode(' | ', $teacherstrings);
            }
        } else {
            // Render col_teacher using a template.
            $data = new col_teacher($values->id, $settings);
            $output = singleton_service::get_renderer('mod_booking');
            $ret = $output->render_col_teacher($data);
        }
        return $ret;
    }

    /**
     * This function is called for each data row to allow processing of the
     * responsiblecontact value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return a link to the responsible contact's user profile.
     * @throws dml_exception
     */
    public function col_responsiblecontact($values) {
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);
        $ret = '';
        if (empty($settings->responsiblecontact)) {
            return $ret;
        }
        if ($user = singleton_service::get_instance_of_user($settings->responsiblecontact)) {
            $userstring = "$user->firstname $user->lastname";
            $emailstring = " ($user->email)";
            if ($this->is_downloading()) {
                $ret = $userstring . $emailstring;
            } else {
                $profileurl = new moodle_url('/user/profile.php', ['id' => $settings->responsiblecontact]);
                $ret = get_string('responsible', 'mod_booking')
                    . ": " . html_writer::link($profileurl, $userstring);
            }
        }
        return $ret;
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

        global $USER;

        // Render col_price using a template.
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);

        return booking_bookit::render_bookit_button($settings, $USER->id);
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

        $title = $values->text;

        // If we download, we return the raw title without link or prefix.
        if ($this->is_downloading()) {
            return $title;
        }

        // NOTE: Do not use $this->cmid and $this->context because it might be that booking options come from different instances!
        // So we always need to retrieve them via singleton service for the current booking option ($values->id).
        $optionid = $values->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        if ($booking) {
            $url = new moodle_url('/mod/booking/optionview.php', ['optionid' => $optionid,
                                                                  'cmid' => $cmid,
                                                                  'userid' => $this->buyforuser->id,
                                                                ]);
        } else {
            $url = '#';
        }

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

        // NOTE: Do not use $this->cmid and $this->context because it might be that booking options come from different instances!
        // So we always need to retrieve them via singleton service for the current booking option ($values->id).

        // phpcs:disable
        // TODO: We still need to figure out how we can fix comments in combination with wb-table-search.
        // Notice: We already have a webservice called init_comments which might help us!
        //     // Important: Without init commenting won't work.
		//     global $CFG;
        //     require_once($CFG->dirroot. '/comment/lib.php');

        //     comment::init();

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* if (!empty($this->cm) && !empty($this->cmid) && !empty($this->context)) {

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
        }*/
        // phpcs:enable

        return $commentshtml;
    }

    /**
     * This function is called for each data row to allow processing of the
     * ratings value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string the ratings HTML
     * @throws dml_exception
     */
    public function col_ratings($values) {
        global $DB, $USER;

        // NOTE: Do not use $this->cmid and $this->context because it might be that booking options come from different instances!
        // So we always need to retrieve them via singleton service for the current booking option ($values->id).
        $optionid = $values->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $context = context_module::instance($cmid);

        $ratingshtml = '';

        $isteacher = booking_check_if_teacher($values);

        $params = ['optionid' => $values->id];

        $sql = "SELECT AVG(rate) AS rating, COUNT(rate) AS ratingcount
        FROM {booking_ratings} br
        WHERE br.optionid = :optionid";

        if ($record = $DB->get_record_sql($sql, $params)) {
            $rating = $record->rating;
            $ratingcount = $record->ratingcount;
        } else {
            $rating = null;
            $ratingcount = null;
        }

        // Now add userid to params.
        $params['userid'] = $USER->id;

        $sql = "SELECT rate AS myrating
            FROM {booking_ratings} br
            WHERE br.optionid = :optionid
            AND br.userid = :userid";

        if ($record = $DB->get_record_sql($sql, $params)) {
            $myrating = $record->myrating;
        } else {
            $myrating = null;
        }

        if (!empty($cmid)) {
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
            if (!empty($context) && !empty($bookingsettings)) {
                if ($bookingsettings->ratings > 0) {

                    $ratingshtml =
                    "<div>
                        <select class='starrating' id='rate$values->id' data-current-rating='$myrating' data-itemid='$values->id'>
                            <option value='1'>1</option>
                            <option value='2'>2</option>
                            <option value='3'>3</option>
                            <option value='4'>4</option>
                            <option value='5'>5</option>
                        </select>
                    </div>";

                    if (has_capability('mod/booking:readresponses', $context) || $isteacher) {
                        $ratingshtml .= get_string('aggregateavg', 'rating') . ' ' . number_format(
                                        (float) $rating, 2, '.', '') . " ($ratingcount)";
                    }
                }
            }
        }

        return $ratingshtml;
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
        $output = singleton_service::get_renderer('mod_booking');

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);
        // Render col_bookings using a template.
        $data = new col_availableplaces($values, $settings, $this->buyforuser);

        $ret = '';
        if ($this->is_downloading()) {
            $bookinginformation = $data->get_bookinginformation();

            $booked = $bookinginformation['booked'] ?? 0;
            $maxanswers = $bookinginformation['maxanswers'] ?? 0;
            $waiting = $bookinginformation['waiting'] ?? 0;
            $maxoverbooking = $bookinginformation['maxoverbooking'] ?? 0;

            $ret .= "$booked / ";
            $ret .= $maxanswers ?? get_string('unlimitedplaces', 'mod_booking');
            if ($maxoverbooking) {
                $ret .= " (" . get_string('waitinglist', 'mod_booking') . ": $waiting / $maxoverbooking)";
            }
        } else {
            $ret = $output->render_col_availableplaces($data);
        }
        return $ret;
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

        if ($this->is_downloading()) {
            return $settings->location;
        }

        if (isset($settings->entity) && (count($settings->entity) > 0)) {

            $url = new moodle_url('/local/entities/view.php', ['id' => $settings->entity['id']]);
            // Full name of the entity (NOT the shortname).
            $nametobeshown = $settings->entity['name'];
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
        global $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id, $values);
        $context = context_module::instance($settings->cmid);

        $ret = '';

        $moodleurl = new moodle_url('/course/view.php', ['id' => $settings->courseid]);
        $courseurl = $moodleurl->out(false);
        // If we download, we want to return the plain URL.
        if ($this->is_downloading()) {
            return $courseurl;
        }

        $answersobject = singleton_service::get_instance_of_booking_answers($settings);
        $status = $answersobject->user_status($USER->id);

        $isteacherofthisoption = booking_check_if_teacher($values);

        if (!empty($settings->courseid) && (
                $status == STATUSPARAM_BOOKED ||
                has_capability('mod/booking:updatebooking', $context) ||
                (has_capability('mod/booking:addeditownoption', $context) && $isteacherofthisoption) ||
                (has_capability('mod/booking:limitededitownoption', $context) && $isteacherofthisoption)
        )) {
            $gotomoodlecourse = get_string('gotomoodlecourse', 'mod_booking');
            $ret = "<a href='$courseurl' target='_self' class='btn btn-primary mt-2 mb-2 w-100'>
                <i class='fa fa-graduation-cap fa-fw' aria-hidden='true'></i>&nbsp;&nbsp;$gotomoodlecourse
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
            $unitsnumber = dates_handler::calculate_and_render_educational_units($settings->dayofweektime);
            $units = get_string('units', 'mod_booking') . ": $unitsnumber";
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

        // NOTE: Do not use $this->cmid and $this->context because it might be that booking options come from different instances!
        // So we always need to retrieve them via singleton service for the current booking option ($values->id).
        $optionid = $values->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        $ret = '';
        if ($this->is_downloading()) {
            $datestrings = dates_handler::return_array_of_sessions_datestrings($optionid);
            $ret = implode(' | ', $datestrings);
        } else {
            // Use the renderer to output this column.
            $data = new \mod_booking\output\col_coursestarttime($optionid, $booking);
            $output = singleton_service::get_renderer('mod_booking');
            $ret = $output->render_col_coursestarttime($data);
        }
        return $ret;
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
            $link = new moodle_url($CFG->wwwroot . '/mod/booking/report.php', [
                'id' => $values->cmid,
                'optionid' => $values->optionid,
            ]);
            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            if ($CFG->version >= 2023042400) {
                // Moodle 4.2 needs second param.
                $link = html_entity_decode($link->out(), ENT_QUOTES);
            } else {
                // Moodle 4.1 and older.
                $link = html_entity_decode($link->out(), ENT_COMPAT);
            }

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

        // NOTE: Do not use $this->cmid and $this->context because it might be that booking options come from different instances!
        // So we always need to retrieve them via singleton service for the current booking option ($values->id).
        $optionid = $values->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $context = context_module::instance($cmid);
        $answersobject = singleton_service::get_instance_of_booking_answers($settings);
        $status = $answersobject->user_status($USER->id);

        // Set the returnurl to navigate back to after form is saved.
        $viewphpurl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
        $returnurl = $viewphpurl->out();

        // Capabilities.
        $canupdate = has_capability('mod/booking:updatebooking', $context);
        $isteacherandcanedit = (has_capability('mod/booking:addeditownoption', $context) &&
            booking_check_if_teacher($values));

        $ddoptions = [];
        $ret = '<div class="menubar" id="action-menu-' . $optionid . '-menubar" role="menubar">';

        if ($status == STATUSPARAM_BOOKED) {
            $ret .= html_writer::link(
                new moodle_url('/mod/booking/viewconfirmation.php',
                    ['id' => $cmid, 'optionid' => $optionid]),
                $OUTPUT->pix_icon('t/print', get_string('bookedtext', 'mod_booking')),
                ['target' => '_blank']);
        }

        if ($canupdate || $isteacherandcanedit) {
            $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                    new moodle_url('/mod/booking/editoptions.php',
                        ['id' => $cmid, 'optionid' => $optionid,
                        'returnto' => 'url',
                        'returnurl' => $returnurl,
                        ]),
                    $OUTPUT->pix_icon('t/editstring', get_string('editbookingoption', 'mod_booking')) .
                    get_string('editbookingoption', 'mod_booking')) . '</div>';

            if (has_capability('mod/booking:manageoptiondates', $context)) {
                // Multiple dates session.
                $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link(new moodle_url('/mod/booking/optiondates.php',
                        ['id' => $cmid, 'optionid' => $optionid,
                        'returnto' => 'url',
                        'returnurl' => $returnurl,
                        ]),
                        $OUTPUT->pix_icon('i/scheduled',
                            get_string('optiondatesmanager', 'booking')) .
                        get_string('optiondatesmanager', 'booking')) . '</div>';
            }

            // Book other users.
            if (has_capability('mod/booking:bookforothers', $context) &&
                (has_capability('mod/booking:subscribeusers', $context) ||
                booking_check_if_teacher($values))) {

                $subscribeusersurl = new moodle_url('/mod/booking/subscribeusers.php',
                    ['id' => $cmid, 'optionid' => $optionid,
                    'returnto' => 'url',
                    'returnurl' => $returnurl,
                    ]);
                $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link($subscribeusersurl,
                        $OUTPUT->pix_icon('i/users',
                            get_string('bookotherusers', 'mod_booking')) .
                        get_string('bookotherusers', 'mod_booking')) . '</div>';
            }

            // Create booking option from each option date.
            $createfromoptiondateurl = new moodle_url('/mod/booking/editoptions.php',
                    ['id' => $cmid, 'optionid' => $optionid, 'createfromoptiondates' => 1]);
            $ddoptions[] = '<div class="dropdown-item">' .
                    html_writer::link($createfromoptiondateurl,
                            $OUTPUT->pix_icon('i/withsubcat',
                                    get_string('createoptionsfromoptiondate', 'mod_booking')) .
                            get_string('createoptionsfromoptiondate', 'mod_booking')) . '</div>';

            if (get_config('booking', 'teachersallowmailtobookedusers')) {
                $mailtolink = booking_option::get_mailto_link_for_partipants($optionid);
                if (!empty($mailtolink)) {
                    $ddoptions[] = '<div class="dropdown-item">' .
                        html_writer::link($mailtolink, $OUTPUT->pix_icon('t/email',
                            get_string('sendmailtoallbookedusers', 'mod_booking')) .
                        get_string('sendmailtoallbookedusers', 'booking')) .
                    '</div>';
                }
            }

            // Show link to optiondates-teachers-report (teacher substitutions).
            $optiondatesteachersmoodleurl = new moodle_url('/mod/booking/optiondates_teachers_report.php',
                ['id' => $cmid, 'optionid' => $optionid, 'returnto' => 'url', 'returnurl' => $returnurl]);
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link($optiondatesteachersmoodleurl,
                    $OUTPUT->pix_icon('i/grades',
                        get_string('optiondatesteachersreport', 'mod_booking')) .
                    get_string('optiondatesteachersreport', 'mod_booking')) . '</div>';

            // Show only one option.
            $onlyoneurl = new moodle_url('/mod/booking/view.php',
                ['id' => $cmid, 'optionid' => $optionid, 'whichview' => 'showonlyone']);
            $ddoptions[] = '<div class="dropdown-item">' .
                html_writer::link($onlyoneurl,
                    $OUTPUT->pix_icon('i/publish',
                        get_string('onlythisbookingoption', 'mod_booking')) .
                    get_string('onlythisbookingoption', 'mod_booking')) . '</div>';

            if ($canupdate) {

                // Cancel booking options.
                // Find out if the booking option has a price or not.
                $optioninfo = $settings->return_booking_option_information();
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
                                'data-id' => $optionid,
                                'data-componentname' => 'mod_booking',
                                'data-area' => 'option',
                                'onclick' =>
                                    "require(['mod_booking/confirm_cancel'], function(init) {
                                        init.init('" . $optionid . "', '" . $values->status . "');
                                    });",
                            ]) . "</div>";

                    } else {
                        // Else we show the cancel button.
                        $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                            $OUTPUT->pix_icon('t/block', '') .
                            get_string('cancelallusers', 'mod_booking'),
                            [
                                'class' => 'cancelallusers',
                                'data-id' => $optionid,
                                'data-componentname' => 'mod_booking',
                                'data-area' => 'option',
                                'onclick' =>
                                    "require(['local_shopping_cart/menu'], function(menu) {
                                        menu.confirmCancelAllUsersAndSetCreditModal('" . $optionid . "', 'mod_booking', 'option');
                                    });",
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
                                        init.init('" . $optionid . "', '" . $values->status . "');
                                    });",
                            ]) . "</div>";
                    } else {
                        // Else we show the cancel button.
                        $ddoptions[] = '<div class="dropdown-item">' . html_writer::link('#',
                            $OUTPUT->pix_icon('t/block', '') .
                            get_string('cancelthisbookingoption', 'mod_booking'),
                            [
                                'onclick' =>
                                    "require(['mod_booking/confirm_cancel'], function(init) {
                                        init.init('" . $optionid . "', '" . $values->status . "');
                                    });",
                            ]) . "</div>";
                    }
                }

                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(new moodle_url('/mod/booking/editoptions.php',
                        ['id' => $cmid, 'optionid' => -1, 'copyoptionid' => $optionid,
                        'returnto' => 'url', 'returnurl' => $returnurl,
                        ]), $OUTPUT->pix_icon('t/copy',
                            get_string('duplicatebooking', 'mod_booking')) .
                        get_string('duplicatebooking', 'mod_booking')) . '</div>';

                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                        new moodle_url('/mod/booking/report.php', [
                            'id' => $cmid,
                            'optionid' => $optionid,
                            'action' => 'deletebookingoption',
                            'sesskey' => sesskey(),
                            'returnto' => 'url',
                            'returnurl' => $returnurl,
                        ]),
                        $OUTPUT->pix_icon('t/delete', get_string('deletethisbookingoption', 'mod_booking')) .
                            get_string('deletethisbookingoption', 'mod_booking')
                ) . '</div>';
            }
            // TODO: Move booking options to another option currently does not work correcly.
            // We temporarily remove it from booking until we are sure, it works.
            // We need to make sure it works for: teachers, optiondates, prices, answers customfields etc.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* $modinfo = get_fast_modinfo($this->booking->course);
            $bookinginstances = isset($modinfo->instances['booking']) ? count($modinfo->instances['booking']) : 0;
            if (has_capability('mod/booking:updatebooking', context_course::instance($this->booking->course->id)) &&
                $bookinginstances > 1) {
                $ddoptions[] = '<div class="dropdown-item">' . html_writer::link(
                        new moodle_url('/mod/booking/moveoption.php',
                            array('id' => $cmid, 'optionid' => $optionid, 'sesskey' => sesskey())),
                        $OUTPUT->pix_icon('t/move', get_string('moveoptionto', 'booking')) .
                        get_string('moveoptionto', 'booking')) . '</div>';
            } */
        }

        if (!empty($ddoptions)) {
            $ret .= '<div class="dropdown d-inline">
                    <button class="dropdown-toggle btn btn-link" id="action-menu-toggle-' . $optionid .
                        '" title="" role="button" data-toggle="dropdown"
                        aria-haspopup="true" aria-expanded="false">' . $OUTPUT->pix_icon(
                    't/edit', get_string('settings', 'moodle')) .
                '</button>
                    <div class="dropdown-menu dropdown-menu-right menu align-tr-br" id="action-menu-' .
                $optionid .
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
     * minanswers value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string a string containing the minanswers description and value
     * @throws coding_exception
     */
    public function col_minanswers($values) {
        $ret = '';
        if (!empty($values->minanswers)) {
            if (!$this->is_downloading()) {
                $ret .= get_string('minanswers', 'mod_booking') . ": ";
            }
            $ret .= $values->minanswers;
        }
        return $ret;
    }

    /**
     * This function is called for each data row to allow processing of the
     * "text depending on user status (statusdescription)" value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string a string containing the text depending on userstatus
     * @throws coding_exception
     */
    public function col_statusdescription($values) {
        $ret = '';

        // NOTE: Do not use $this->cmid and $this->context because it might be that booking options come from different instances!
        // So we always need to retrieve them via singleton service for the current booking option ($values->id).
        $optionid = $values->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);

        $statusdescription = $bookingoption->get_text_depending_on_status($bookinganswers);
        if (!empty($statusdescription)) {
            $ret = $statusdescription;
        }
        return $ret;
    }

    /**
     * This function is called for each data row to allow processing of the
     * "description" value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string a string containing the description
     * @throws coding_exception
     */
    public function col_description($values) {
        $description = $values->description;
        // If we download, we want to show text only without HTML tags.
        if ($this->is_downloading()) {
            $ret = strip_tags($description);
        } else {
            $ret = html_writer::div($description);
        }
        return $ret;
    }
}
