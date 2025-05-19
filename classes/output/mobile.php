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
 * Mobile output class for booking
 *
 * @package mod_booking
 * @copyright 2018 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

defined('MOODLE_INTERNAL') || die();

use context;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\local\mobile\customformstore;
use mod_booking\local\mobile\mobileformbuilder;
use mod_booking\places;
use mod_booking\price;
use mod_booking\singleton_service;
use moodle_exception;
use stdClass;

require_once($CFG->dirroot . '/mod/booking/locallib.php');

/**
 * Mobile output class for booking
 *
 * @package mod_booking
 * @copyright 2018 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * Returns all my bookings view for mobile app.
     *
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function mobile_system_view($args) {

        global $DB, $OUTPUT, $USER;

        $cmid = get_config('booking', 'shortcodessetinstance');

        if (empty($cmid)) {
            throw new moodle_exception('nocmidselected', 'mod_booking');
        }

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        $wherearray['bookingid'] = (int)$booking->id;

        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', null, null, [], $wherearray);

        $sql = "SELECT $fields
                FROM $from
                WHERE $where";

        $records = $DB->get_records_sql($sql, $params);

        $outputdata = [];

        $maxdatabeforecollapsable = get_config('booking', 'collapseshowsettings');
        if ($maxdatabeforecollapsable === false) {
            $maxdatabeforecollapsable = '2';
        }
        foreach ($records as $record) {
            $settings = singleton_service::get_instance_of_booking_option_settings($record->id);
            $tmpoutputdata = $settings->return_booking_option_information();
            $tmpoutputdata['maxsessions'] = $maxdatabeforecollapsable;
            $data = $settings->return_booking_option_information();
            if (count($settings->sessions) > $maxdatabeforecollapsable) {
                $data['collapsedsessions'] = $data['sessions'];
                unset($data['sessions']);
            }
            $outputdata[] = $data;
        }

        $data = [
          'mybookings' => $outputdata,
        ];
        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_booking/mobile/mobile_mybookings_list', $data),
                ],
            ],
            'javascript' => '',
            'otherdata' => ['data' => '{}'],
        ];
    }

    /**
     * Returns detail view of booking option
     *
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function mobile_booking_option_details($args) {

        global $DB, $OUTPUT, $USER;

        if (empty($args['optionid'])) {
            throw new moodle_exception('nooptionid', 'mod_booking');
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($args['optionid']);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
        $customform = customform::return_formelements($settings);
        $mobileformbuilder = new mobileformbuilder();

        $data = (array)$settings->return_settings_as_stdclass();

        $data['userid'] = $USER->id;

        $boinfo = new bo_info($settings);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $USER->id, false);

        // Now we render the button for this option & user.
        [$templates, $button] = booking_bookit::render_bookit_template_data($settings, 0, false);
        $button = reset($button);

        $ionsubmissionhtml = '';

        switch ($id) {
            case MOD_BOOKING_BO_COND_JSON_CUSTOMFORM:
                if (!empty($customform)) {
                    $customformstore = new customformstore($USER->id, $data['id']);
                    $customformuserdata = $customformstore->get_customform_data();
                    $formvalidated = [false];
                    if ($customformuserdata) {
                        $formvalidated = $customformstore->validation($customform, (array)$customformuserdata);
                    }
                    if (empty($formvalidated)) {
                        $data['submit']['label'] = $button->data['main']['label'];
                        $ionsubmissionhtml = $mobileformbuilder::submission_form_submitted();
                    } else {
                        if ($customformuserdata !== false) {
                            $customform = $customformstore->translate_errors($customform, $formvalidated);
                        }
                        $ionsubmissionhtml = $mobileformbuilder::build_submission_entitites($customform, $data);
                    }
                }
                break;
            case MOD_BOOKING_BO_COND_BOOKITBUTTON:
            case MOD_BOOKING_BO_COND_CONFIRMBOOKIT:
                $data['submit']['label']
                    = $description;
                break;
            case MOD_BOOKING_BO_COND_PRICEISSET:
                $price = price::get_price('option', $settings->id);
                $data['nosubmit']['label'] = $price['price'] . " " . $price['currency'];
                break;
            case MOD_BOOKING_BO_COND_BOOKINGPOLICY:
                $data['nosubmit']['label'] = get_string('notbookable', 'mod_booking');
                break;
            case MOD_BOOKING_BO_COND_ALREADYBOOKED:
            case MOD_BOOKING_BO_COND_CONFIRMCANCEL:
                $data['nosubmit']['label'] = get_string('booked', 'mod_booking');
                $cancellabel = $id == MOD_BOOKING_BO_COND_ALREADYBOOKED ? get_string('cancelmyself', 'mod_booking') : $description;
                self::render_course_button($data);
                if ($bookingsettings->cancancelbook == '1') {
                    $data['cancelbookingoption'] = [
                        'itemid' => $data['id'],
                        'area' => "option",
                        'userid' => $USER->id,
                        'label' => $cancellabel,
                        'data' => '"{\"itemid\":\"' .
                            $data['id'] .
                            '\",\"componentname\":\"mod_booking\",\"area\":\"option\",\"userid\":\"' .
                            $USER->id .
                            ' \",\"results\":\"\",\"initialized\":\"true\"}"',
                    ];
                }
                break;
            default:
                $data['nosubmit']['label']
                    = !empty($description) ? $description : get_string('notbookable', 'mod_booking');
                break;
        }

        $teachers = [];
        foreach ($data['teachers'] as $teacher) {
            if (
                get_config('booking', 'teachersshowemails')
                || (
                    get_config('booking', 'bookedteachersshowemails')
                    && ($id == MOD_BOOKING_BO_COND_ALREADYBOOKED)
                )
            ) {
                $teacher->email = str_replace('@', '&#64;', $teacher->email);
            } else {
                $teacher->email = false;
            }

            $teachers[] = (array)$teacher;
        }
        $data['teachers'] = $teachers;

        self::format_description($data['description']);
        $detailhtml = $OUTPUT->render_from_template('mod_booking/mobile/mobile_booking_option_details', $data);
        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $detailhtml . $ionsubmissionhtml ?? '',
                ],
            ],
            'javascript' => '',
            'otherdata' => ['data' => '{}'],
        ];
    }

    /**
     * Get all selected nav tabs from the config
     * @param string $description
     */
    private static function format_description(&$description) {
        $description = str_replace('</p>', '</p><br>', $description);
    }


    /**
     * Get all selected nav tabs from the config
     * @param array $data
     */
    public static function render_course_button(&$data) {
        global $CFG;
        if (
            isset($data['courseid']) &&
            (int)$data['courseid'] > 0
        ) {
            $linktocourse = $CFG->wwwroot . '/course/view.php?id=' . $data['courseid'];
            if (get_config('booking', 'linktomoodlecourseonbookedbutton')) {
                $data['linktomoodlecourseonbookedbutton'] = $linktocourse;
            } else {
                $data['linktomoodlecourseadditionalbutton'] = $linktocourse;
            }
        }
    }

    /**
     * Returns all my bookings view for mobile app.
     *
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function mobile_mybookings_list($args) {
        global $OUTPUT, $USER, $DB;

        $mybookings = $DB->get_records_sql(
            "SELECT ba.id id, c.id courseid, c.fullname fullname, b.id bookingid, b.name, bo.text, bo.id optionid,
            bo.coursestarttime coursestarttime, bo.courseendtime courseendtime, cm.id cmid
            FROM
            {booking_answers} ba
            LEFT JOIN
        {booking_options} bo ON ba.optionid = bo.id
            LEFT JOIN
        {booking} b ON b.id = bo.bookingid
            LEFT JOIN
        {course} c ON c.id = b.course
            LEFT JOIN
            {course_modules} cm ON cm.module = (SELECT
                    id
                FROM
                    {modules}
                WHERE
                    name = 'booking')
                WHERE instance = b.id AND ba.userid = {$USER->id} AND cm.visible = 1"
        );

        $outputdata = [];

        foreach ($mybookings as $key => $value) {
            $status = '';
            $coursestarttime = '';

            if ($value->coursestarttime > 0) {
                $coursestarttime = userdate($value->coursestarttime);
            }
            $status = booking_getoptionstatus($value->coursestarttime, $value->courseendtime);

            $outputdata[] = [
                'fullname' => $value->fullname,
                'name' => $value->name,
                'text' => $value->text,
                'status' => $status,
                'coursestarttime' => $coursestarttime,
            ];
        }

        $data = ['mybookings' => $outputdata];

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_booking/mobile/mobile_mybookings_list', $data),
                ],
            ],
            'javascript' => '',
            'otherdata' => ['data' => '{}'],
        ];
    }

    /**
     * Returns the booking course view for the mobile app.
     *
     * @param array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and otherdata
     */
    public static function mobile_course_view($args) {
        global $DB, $OUTPUT, $USER;

        $cmid = $args['cmid'];
        $availablenavtabs = self::get_available_nav_tabs($cmid);
        $whichview = self::set_active_nav_tabs($availablenavtabs, $args['whichview'] ?? null);

        if (empty($cmid)) {
            throw new moodle_exception('nocmidselected', 'mod_booking');
        }

        $records = self::get_available_booking_options($whichview, $cmid);
        $outputdata = [];
        $maxdatabeforecollapsable = get_config('booking', 'collapseshowsettings');
        if ($maxdatabeforecollapsable === false) {
            $maxdatabeforecollapsable = '2';
        }
        foreach ($records as $record) {
            $outputdata[] = self::get_course_view_output_dat($record->id, $maxdatabeforecollapsable);
        }
        $data = [];
        $data['availablenavtabs'] = $availablenavtabs;
        $data['whichview'] = $whichview;
        $data['cmid'] = $cmid;
        $data['mybookings'] = $outputdata;
        $data['timestamp'] = time();
        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_booking/mobile/mobile_mybookings_list', $data),
                ],
            ],
            'javascript' => '',
            'otherdata' => ['data' => '{}'],
        ];
    }

    /**
     * Get all selected nav tabs from the config
     * @param int $recordid
     * @param int $maxdatabeforecollapsable
     * @return array
     */
    public static function get_course_view_output_dat($recordid, $maxdatabeforecollapsable) {
        $settings = singleton_service::get_instance_of_booking_option_settings($recordid);
        $tmpoutputdata = $settings->return_booking_option_information();
        $tmpoutputdata['maxsessions'] = $maxdatabeforecollapsable;
        $tmpoutputdata = $settings->return_booking_option_information();
        if (
            strlen(strip_tags($tmpoutputdata['description'])) >
            (int) get_config('booking', 'collapsedescriptionmaxlength')
        ) {
            $tmpoutputdata['descriptioncollapsable'] = $tmpoutputdata['description'];
            unset($tmpoutputdata['description']);
        }
        if (count($settings->sessions) > $maxdatabeforecollapsable) {
            $tmpoutputdata['collapsedsessions'] = $tmpoutputdata['sessions'];
            unset($tmpoutputdata['sessions']);
        }
        return $tmpoutputdata;
    }

    /**
     * Get all selected nav tabs from the config
     * @param string $selectedview
     * @param int $cmid
     * @return array
     */
    public static function get_available_booking_options($selectedview, $cmid) {
        global $DB;
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $params = [];
        switch ($selectedview) {
            case 'showactive':
                $params = self::get_rendered_active_options_table($booking);
                break;
            case 'mybooking':
                $params = self::get_rendered_my_booked_options_table($booking);
                break;
            case 'myoptions':
                $params = self::get_rendered_table_for_teacher($booking);
                break;
            // Todo: When we need it, we can uncomment this.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* case 'optionsiamresponsiblefor':
                $params = self::get_rendered_table_for_responsible_contact($booking);
                break; */
            case 'myinstitution':
                $params = self::get_rendered_myinstitution_table($booking);
                break;
            case 'showvisible':
                $params = self::get_rendered_visible_options_table($booking);
                break;
            case 'showinvisible':
                $params = self::get_rendered_invisible_options_table($booking);
                break;
            default:
                $params = self::get_rendered_all_options_table($booking);
                break;
        }
        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $booking->context,
            [],
            $params['wherearray'],
            $params['userid'] ?? null,
            $params['bookingparams'] ?? 0,
            $params['additionalwhere'] ?? null
        );

        if ($selectedview == 'showactive') {
            $params['timenow'] = strtotime('today 00:00');
        }

        $sql = "SELECT $fields
                FROM $from
                WHERE $where";

        $records = $DB->get_records_sql($sql, $params);
        return $records;
    }

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    public static function get_rendered_invisible_options_table($booking): array {
        $wherearray = [
            'bookingid' => (int) $booking->id,
            'invisible' => 1,
        ];
        return [
            'wherearray' => $wherearray,
        ];
    }

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    public static function get_rendered_visible_options_table($booking): array {
        $wherearray = [
            'bookingid' => (int) $booking->id,
            'invisible' => 0,
        ];
        return [
            'wherearray' => $wherearray,
        ];
    }

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    public static function get_rendered_myinstitution_table($booking): array {
        global $USER;
        $wherearray = [
            'bookingid' => (int)$booking->id,
            'teacherobjects' => '%"id":' . $USER->institution . ',%',
        ];
        return [
            'wherearray' => $wherearray,
        ];
    }

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    public static function get_rendered_table_for_teacher($booking): array {
        global $USER;
        $wherearray = [
            'bookingid' => (int)$booking->id,
            'teacherobjects' => '%"id":' . $USER->id . ',%',
        ];
        return [
            'wherearray' => $wherearray,
        ];
    }

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    // Todo: When we need it, we can uncomment this.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /* public static function get_rendered_table_for_responsible_contact($booking): array {
        global $USER;
        $wherearray = ['bookingid' => (int)$booking->id];
        $additionalwhere = "responsiblecontact = $USER->id";
        return [
            'wherearray' => $wherearray,
            'additionalwhere' => $additionalwhere,
        ];
    } */

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    public static function get_rendered_active_options_table($booking): array {
        $wherearray = ['bookingid' => (int)$booking->id];
        $additionalwhere = '((courseendtime > :timenow OR courseendtime = 0) AND status = 0)';
        return [
            'wherearray' => $wherearray,
            'additionalwhere' => $additionalwhere,
            'bookingparams' => [MOD_BOOKING_STATUSPARAM_BOOKED],
        ];
    }

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    public static function get_rendered_my_booked_options_table($booking): array {
        global $DB, $USER;
        $wherearray = ['bookingid' => (int)$booking->id];
        return [
            'wherearray' => $wherearray,
            'userid' => $USER->id,
        ];
    }

    /**
     * Render table for all booking options.
     * @param \mod_booking\booking $booking
     * @return array
     */
    public static function get_rendered_all_options_table($booking): array {
        global $DB;
        $wherearray = ['bookingid' => (int)$booking->id];
        return [
            'wherearray' => $wherearray,
        ];
    }

    /**
     * Get all selected nav tabs from the config$activetab
     * @param string $cmid
     * @return array
     */
    public static function get_available_nav_tabs($cmid) {
        $selectednavlabelnames = [];
        $navlabelnames = self::match_view_label_and_names();
        $configmobileviewoptions = get_config('booking', 'mobileviewoptions');
        if ($configmobileviewoptions !== '') {
            $navtabs = explode(',', get_config('booking', 'mobileviewoptions'));
            foreach ($navtabs as $navtab) {
                if (
                    !empty($navtab) &&
                    self::get_available_booking_options($navtab, $cmid)
                ) {
                    $selectednavlabelnames[] = [
                      'label' => $navtab,
                      'name' => $navlabelnames[$navtab],
                      'class' => $activetab === $navtab ? 'active' : false,
                    ];
                }
            }
        }
        return $selectednavlabelnames;
    }

    /**
     * Get all selected nav tabs from the config$activetab
     * @param array $tabs
     * @param string $activetab
     * @return string
     */
    public static function set_active_nav_tabs(&$tabs, $activetab) {
        $whichview = $activetab ?? $tabs[0]['label'] ?? 'showall';
        foreach ($tabs as &$tab) {
            if ($tab['label'] == $whichview) {
                $tab['class'] = 'active';
                break;
            }
        }
        return $whichview;
    }

    /**
     * Config options my name
     * @return array
     */
    public static function match_view_label_and_names() {
        return [
            'showall' => get_string('showallbookingoptions', 'mod_booking'),
            'mybooking' => get_string('showmybookingsonly', 'mod_booking'),
            'myoptions' => get_string('optionsiteach', 'mod_booking'),
            // Todo: When we need it, we can uncomment this.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* 'optionsiamresponsiblefor' => get_string('optionsiamresponsiblefor', 'mod_booking'), */
            'showactive' => get_string('activebookingoptions', 'mod_booking'),
            'myinstitution' => get_string('myinstitution', 'mod_booking'),
            'showvisible' => get_string('visibleoptions', 'mod_booking'),
            'showinvisible' => get_string('invisibleoptions', 'mod_booking'),
        ];
    }

    /**
     * TODO: What does it do?
     * @param int $allpages
     * @param int $pagnumber
     * @return array
     */
    public static function npbuttons($allpages, $pagnumber) {
        $p = 0;
        $n = 0;

        if ($pagnumber > 0) {
            $p = $pagnumber - 1;
        }

        if ($pagnumber < $allpages) {
            $n = $pagnumber + 1;
        }

        return [
            'p' => $p, 'n' => $n,
        ];
    }

    /**
     * TODO: What does it do?
     *
     * @param mixed $bookingoptions
     * @param booking $booking
     * @param context $context
     * @param stdClass $cm
     * @param int $courseid
     *
     * @return array
     * @throws \coding_exception
     *
     */
    public static function prepare_options_array($bookingoptions, booking $booking, context $context, stdClass $cm, $courseid) {
        $options = [];

        foreach ($bookingoptions as $key => $value) {
            $option = singleton_service::get_instance_of_booking_option(
                $cm->id,
                (is_object($value) ? $value->id : $value)
            );
            $option->get_teachers();
            $options[] = self::prepare_options($option, $booking, $context, $cm, $courseid);
        }

        return $options;
    }

    /**
     * Prepare booking options for output on mobile.
     *
     * @param mixed $values
     * @param booking $booking
     * @param context $context
     * @param stdClass $cm
     * @param int $courseid
     * @return array
     * @throws \coding_exception
     */
    public static function prepare_options($values, booking $booking, context $context, stdClass $cm, $courseid) {
        global $USER;

        $text = '';

        if (strlen($values->option->address) > 0) {
            $text .= $values->option->address . "<br>";
        }

        if (strlen($values->option->location) > 0) {
            $text .= (empty($booking->settings->lbllocation) ? get_string('location', 'booking') :
                $booking->settings->lbllocation) . ': ' . $values->option->location . "<br>";
        }
        if (strlen($values->option->institution) > 0) {
            $text .= (empty($booking->settings->lblinstitution) ? get_string('institution', 'booking') :
                $booking->settings->lblinstitution) . ': ' . $values->option->institution . "<br>";
        }

        if (!empty($values->option->description)) {
            $text .= format_text($values->option->description);
        }

        $teachers = [];
        foreach ($values->teachers as $tvalue) {
            $teachers[] = "{$tvalue->firstname} {$tvalue->lastname}";
        }

        if ($values->option->coursestarttime != 0 && $values->option->courseendtime != 0) {
            $text .= userdate($values->option->coursestarttime) . " - " . userdate(
                $values->option->courseendtime
            );
        }

        $text .= (!empty($values->teachers) ? "<br>" . (empty($booking->settings->lblteachname) ? get_string(
            'teachers',
            'booking'
        ) : $booking->settings->lblteachname) . ": " . implode(
            ', ',
            $teachers
        ) : '');

        $delete = [];
        $status = '';
        $button = [];
        $booked = '';
        $inpast = $values->option->courseendtime && ($values->option->courseendtime < time());

        $underlimit = ($booking->settings->maxperuser == 0);
        $underlimit = $underlimit || ($values->option->bookinggetuserbookingcount < $values->option->maxperuser);

        if (!$values->option->limitanswers) {
            $status = "available";
        } else if (($values->waiting + $values->booked) >= ($values->option->maxanswers + $values->option->maxoverbooking)) {
            $status = "full";
        }

        if (time() > $values->option->bookingclosingtime && $values->option->bookingclosingtime != 0) {
            $status = "closed";
        }

        if (time() < $values->option->bookingopeningtime && $values->option->bookingopeningtime != 0) {
            $status = "closed";
        }

        // I'm booked or not.
        if ($values->iambooked) {
            if ($booking->settings->allowupdate && $status != 'closed' && $values->completed != 1) {
                // TO-DO: Naredi gumb za izpis iz opcije.
                $deletemessage = format_string($values->option->text);

                if ($values->option->coursestarttime != 0) {
                    $deletemessage .= "<br />" . userdate(
                        $values->option->coursestarttime,
                        get_string('strftimedatetime', 'langconfig')
                    ) . " - " . userdate(
                        $values->option->courseendtime,
                        get_string('strftimedatetime', 'langconfig')
                    );
                }

                $cmessage = get_string('deletebooking', 'booking', $deletemessage);
                $bname = (empty($values->option->btncancelname) ? get_string(
                    'cancelbooking',
                    'booking'
                ) : $values->option->btncancelname);
                $delete = [
                    'text' => $bname,
                                'args' => "optionid: {$values->option->id}, cmid: {$cm->id}, courseid: {$courseid}",
                    'cmessage' => "{$cmessage}",
                ];

                if ($values->option->coursestarttime > 0 && $values->booking->allowupdatedays > 0) {
                    if (time() > strtotime("-{$values->booking->allowupdatedays} day", $values->option->coursestarttime)) {
                        $delete = [];
                    }
                }
            }

            if ($values->onwaitinglist) {
                $text .= '<br><ion-chip><ion-label>' . get_string('onwaitinglist', 'booking') . '</ion-label></ion-chip>';
            } else if ($inpast) {
                $text .= '<br><ion-chip><ion-label>' . get_string('bookedpast', 'booking') . '</ion-label></ion-chip>';
            } else {
                $text .= '<br><ion-chip><ion-label>' . get_string('booked', 'booking') . '</ion-label></ion-chip>';
            }
        } else {
            $message = $values->option->text;
            if ($values->option->coursestarttime != 0) {
                $message .= "<br>" . userdate(
                    $values->option->coursestarttime,
                    get_string('strftimedatetime')
                ) . " - " . userdate(
                    $values->option->courseendtime,
                    get_string('strftimedatetime', 'langconfig')
                );
            }
            $message .= '<br><br>' . get_string('confirmbookingoffollowing', 'booking');
            if (!empty($booking->settings->bookingpolicy)) {
                $message .= "<br><br>" . get_string('bookingpolicyagree', 'booking');
                $message .= "<br>" . format_text($booking->settings->bookingpolicy, FORMAT_HTML);
            }
            $bnow = (empty($booking->settings->btnbooknowname) ? get_string('booknow', 'booking') :
                $booking->settings->btnbooknowname);
            $button = [
                'text' => $bnow,
                            'args' => "answer: {$values->option->id}, id: {$cm->id}, courseid: {$courseid}",
                'message' => $message,
            ];
        }

        if (
            ($values->option->limitanswers && ($status == "full")) || ($status == "closed") ||
            !$underlimit || $values->option->disablebookingusers
        ) {
            $button = [];
        }

        if (
            $booking->settings->cancancelbook == 0 && $values->option->courseendtime > 0
            && $values->option->courseendtime < time()
        ) {
            $button = [];
            $delete = [];
        }

        if (!empty($booking->settings->banusernames)) {
            $disabledusernames = explode(',', $booking->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $button = [];
                }
            }
        }

        if ($values->option->limitanswers) {
            $places = new places(
                $values->option->maxanswers,
                $values->option->maxanswers - $values->booked,
                $values->option->maxoverbooking,
                $values->option->maxoverbooking - $values->waiting
            );
        }

        return [
            'name' => $values->option->text, 'text' => $text, 'button' => $button,
            'delete' => $delete,
        ];
    }
}
