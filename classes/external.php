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
 * booking module external API
 *
 * @package mod_booking
 * @category external
 * @copyright 2018 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;

use context_module;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_warnings;
use mod_booking\utils\webservice_import;
use stdClass;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/externallib.php');

/**
 * booking module external functions
 *
 * @package mod_booking
 * @category external
 * @copyright 2018 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends external_api {

    /**
     * Describes the parameters for instancetemplate.
     *
     * @return external_function_parameters
     */
    public static function instancetemplate_parameters() {
        return new external_function_parameters(
            array(
                'id' => new external_value(PARAM_INT, 'instancetemplate id',
                'ID of booking instance template.', VALUE_REQUIRED, 0)
            )
        );
    }

    /**
     * Get instance template.
     *
     * @param integer $id
     * @return string
     */
    public static function instancetemplate($id) {
        global $DB;
        $params = self::validate_parameters(self::instancetemplate_parameters(),
                array('id' => $id)
            );

        $template = $DB->get_record("booking_instancetemplate", array('id' => $id), '*', IGNORE_MISSING);

        return array(
            'id' => $id,
            'name' => $template->name,
            'template' => $template->template
        );
    }

    /**
     * Expose to AJAX
     *
     * @return boolean
     */
    public static function instancetemplate_is_allowed_from_ajax() {
        return true;
    }

    /**
     * Returns description of method result value
     *
     * @return \external_single_structure
     * @since Moodle 3.0
     */
    public static function instancetemplate_returns() {
        return new external_single_structure(
                array('id' => new external_value(PARAM_INT, 'Template id.'),
                    'name' => new \external_value(PARAM_TEXT, 'Template name.'),
                    'template' => new \external_value(PARAM_RAW), 'JSON serialized template data.'));
    }

    /**
     * Describes the parameters for optiontemplate.
     *
     * @return external_function_parameters
     */
    public static function optiontemplate_parameters() {
        return new external_function_parameters(
            array(
                'id' => new external_value(PARAM_INT, 'optiontemplate id',
                'ID of option template.', VALUE_REQUIRED, 0)
            )
        );
    }

    /**
     * Get instance template.
     *
     * @param integer $id
     * @return string
     */
    public static function optiontemplate($id) {
        global $DB;
        $params = self::validate_parameters(self::optiontemplate_parameters(),
                array('id' => $id)
            );

        $template = $DB->get_record("booking_options", array('id' => $id), '*', IGNORE_MISSING);

        return array(
            'id' => $id,
            'name' => $template->text,
            'template' => json_encode($template)
        );
    }

    /**
     * Expose to AJAX
     *
     * @return boolean
     */
    public static function optiontemplate_is_allowed_from_ajax() {
        return true;
    }

    /**
     * Returns description of method result value
     *
     * @return \external_single_structure
     * @since Moodle 3.0
     */
    public static function optiontemplate_returns() {
        return new external_single_structure(
                array('id' => new external_value(PARAM_INT, 'Template id.'),
                    'name' => new \external_value(PARAM_TEXT, 'Template name.'),
                    'template' => new \external_value(PARAM_RAW), 'JSON serialized template data.'));
    }

    /**
     * Describes the parameters for update_bookingnotes.
     *
     * @return external_function_parameters
     */
    public static function update_bookingnotes_parameters() {
        return new external_function_parameters(
                array(
                    'baid' => new external_value(PARAM_INT, 'booking_answer id',
                            'ID of the booking answer', VALUE_REQUIRED, 0),
                    'note' => new external_value(PARAM_TEXT, 'booking_answer note',
                            'Note added to the booking answer', VALUE_DEFAULT, '')));
    }

    /**
     * Update the notes in booking_answers table
     *
     * @param integer $baid
     * @param string $note
     * @return string[][]|boolean[]
     */
    public static function update_bookingnotes($baid, $note) {
        global $DB;
        $params = self::validate_parameters(self::update_bookingnotes_parameters(),
                array('baid' => $baid, 'note' => $note));

        $dataobject = new stdClass();
        $dataobject->id = $baid;
        $dataobject->notes = $note;
        $warnings = array();
        // Check if entry exists in DB.
        if (!$DB->record_exists('booking_answers', array('id' => $dataobject->id))) {
            $warnings[] = 'Invalid booking';
        }

        $success = $DB->update_record('booking_answers', $dataobject);
        $return = array('note' => $note, 'baid' => $baid, 'warnings' => $warnings,
            'status' => $success);
        return $return;
    }

    /**
     * Expose to AJAX
     *
     * @return boolean
     */
    public static function update_bookingnotes_is_allowed_from_ajax() {
        return true;
    }

    /**
     * Returns description of method result value
     *
     * @return \external_single_structure
     * @since Moodle 3.0
     */
    public static function update_bookingnotes_returns() {
        return new external_single_structure(
                array('status' => new external_value(PARAM_BOOL, 'status: true if success'),
                    'warnings' => new external_warnings(),
                    'note' => new \external_value(PARAM_TEXT, 'the updated note'),
                    'baid' => new \external_value(PARAM_INT)));
    }

    /**
     * Describes the parameters for enrol_user.
     *
     * @return external_function_parameters
     */
    public static function enrol_user_parameters() {
        return new external_function_parameters(
                array('id' => new external_value(PARAM_TEXT, 'cmid', 'CM ID', VALUE_REQUIRED, 0),
                    'answer' => new external_value(PARAM_INT, 'Answer id', 'Answer id',
                            VALUE_REQUIRED, 0),
                    'courseid' => new external_value(PARAM_TEXT, 'course id', 'Course id',
                            VALUE_REQUIRED, 0)));
    }

    /**
     * Enrol user
     *
     * @param integer $id
     * @param integer $answer
     * @param integer $courseid
     * @return string[][]|boolean[]
     */
    public static function enrol_user($id, $answer, $courseid) {
        global $DB, $USER;
        $params = self::validate_parameters(self::enrol_user_parameters(),
                array('id' => $id, 'answer' => $answer, 'courseid' => $courseid));

        $bookingdata = new \mod_booking\booking_option($id, $answer, array(), 0, 0, false);
        $bookingdata->apply_tags();
        if ($bookingdata->user_submit_response($USER)) {
            $contents = get_string('bookingsaved', 'booking');
            if ($bookingdata->booking->settings->sendmail) {
                $contents .= "<br />" . get_string('mailconfirmationsent', 'booking') . ".";
            }
        } else if (is_numeric($answer)) {
            $contents = get_string('bookingmeanwhilefull', 'booking') . " " . $bookingdata->option->text;
        }

        return array('status' => true, 'id' => $id, 'message' => htmlentities($contents), 'answer' => $answer,
                        'courseid' => $courseid);
    }

    /**
     * Returns description of method result value
     *
     * @return \external_single_structure
     * @since Moodle 3.0
     */
    public static function enrol_user_returns() {
        return new external_single_structure(
                array('status' => new external_value(PARAM_BOOL, 'status: true if success'),
                    'warnings' => new external_warnings(),
                                'message' => new \external_value(PARAM_TEXT, 'the updated note'),
                    'id' => new \external_value(PARAM_INT),
                    'answer' => new \external_value(PARAM_INT),
                    'courseid' => new \external_value(PARAM_INT)
                ));
    }

    public static function unenrol_user_parameters() {
        return new external_function_parameters(
                array('cmid' => new external_value(PARAM_TEXT, 'CM ID', VALUE_REQUIRED, 0),
                    'optionid' => new external_value(PARAM_INT, 'Option id',
                            VALUE_REQUIRED, 0),
                    'courseid' => new external_value(PARAM_TEXT, 'course id',
                            VALUE_REQUIRED, 0)));
    }

    public static function unenrol_user($cmid, $optionid, $courseid) {
        global $USER;

        $params = self::validate_parameters(self::unenrol_user_parameters(),
                array('cmid' => $cmid, 'optionid' => $optionid, 'courseid' => $courseid));

        $bookingdata = new \mod_booking\booking_option($cmid, $optionid, array(), 0, 0, false);
        $bookingdata->apply_tags();

        if ($bookingdata->user_delete_response($USER->id)) {
            $contents = get_string('bookingdeleted', 'booking');
        } else {
            $contents = get_string('cannotremovesubscriber', 'booking');
        }

        return array('status' => true, 'cmid' => $cmid, 'message' => htmlentities($contents),
                        'optionid' => $optionid, 'courseid' => $courseid);
    }

    /**
     * Define return value of unenrol_user.
     * @return external_single_structure
     */
    public static function unenrol_user_returns() {
        return new external_single_structure(
                array('status' => new external_value(PARAM_BOOL, 'status: true if success'),
                    'warnings' => new external_warnings(),
                    'message' => new \external_value(PARAM_TEXT, 'the updated note'),
                    'cmid' => new \external_value(PARAM_INT),
                    'optionid' => new \external_value(PARAM_INT),
                                'courseid' => new \external_value(PARAM_INT)));
    }

    /**
     * By this function it's possible to create a booking option via webservice.
     */
    public static function addbookingoption(
        $name,
        $titleprefix = null,
        $targetcourseid = null,
        $courseid = null,
        $bookingid = null,
        $bookingidnumber = null,
        $bookingoptionid = null,
        $courseidnumber = null,
        $courseshortname = null,
        $maxanswers = null,
        $maxoverbooking = null,
        $bookingclosingtime = null,
        $enrolmentstatus = null,
        $description = null,
        $descriptionformat = null,
        $limitanswers = null,
        $addtocalendar = null,
        $pollurl = null,
        $location = null,
        $institution = null,
        $address = null,
        $credits = null,
        $pollurlteachers = null,
        $howmanyusers = null,
        $removeafterminutes = null,
        $notifcationtext = null,
        $notifcationtextformat = null,
        $disablebookingusers = null,
        $beforebookedtext = null,
        $beforecompletedtext = null,
        $aftercompletedtext = null,
        $shorturl = null,
        $duration = null,
        $useremail = null,
        $teacheremail = null,
        $userusername = null,
        $coursestarttime = null,
        $courseendtime = null,
        $invisible = null,
        $mergeparam = null
    ) {

        $params = self::validate_parameters(self::addbookingoption_parameters(),
                array('name' => $name,
                        'titleprefix' => $titleprefix, // Optional prefix to be shown before title.
                        'targetcourseid' => $targetcourseid, // Id of course where the booking option should be created.
                        'courseid' => $courseid, // Id of course where users should be inscribed when booked.
                        'bookingcmid' => $bookingid, // Moodle cm ID of the target booking instance.
                        'bookingidnumber' => $bookingidnumber, // Idnumber of target booking instance.
                        'courseidnumber' => $courseidnumber, // Way of identifying target course via idnumber.
                        'courseshortname' => $courseshortname, // Way of identifiying target course via shortname.
                        'bookingoptionid' => $bookingoptionid, // Moodle id of bookingoption to update booking option.
                        'maxanswers' => $maxanswers,
                        'maxoverbooking' => $maxoverbooking,
                        'bookingclosingtime' => $bookingclosingtime,
                        'enrolmentstatus' => $enrolmentstatus,
                        'description' => $description,
                        'descriptionformat' => $descriptionformat,
                        'limitanswers' => $limitanswers,
                        'addtocalendar' => $addtocalendar,
                        'pollurl' => $pollurl,
                        'location' => $location,
                        'institution' => $institution,
                        'address' => $address,
                        'credits' => $credits,
                        'pollurlteachers' => $pollurlteachers,
                        'howmanyusers' => $howmanyusers,
                        'removeafterminutes' => $removeafterminutes,
                        'notificationtext' => $notifcationtext,
                        'notificationtextformat' => $notifcationtextformat,
                        'disablebookingusers' => $disablebookingusers,
                        'beforebookedtext' => $beforebookedtext,
                        'beforecompletedtext' => $beforecompletedtext,
                        'aftercompletedtext' => $aftercompletedtext,
                        'shorturl' => $shorturl,
                        'duration' => $duration,
                        'useremail' => $useremail,
                        'teacheremail' => $teacheremail,
                        'user_username' => $userusername,
                        'coursestarttime' => $coursestarttime,
                        'courseendtime' => $courseendtime,
                        'invisible' => $invisible,
                        'mergeparam' => $mergeparam));

        // We want to pass on an object to, so we clean all unnecessary values.
        $cleanedarray = array_filter($params, function($x) {
            return $x !== null;
        });

        $importer = new webservice_import();
        return $importer->process_data((object)$cleanedarray);
    }

    /**
     * Define parameters for addbookingoption function.
     * @return external_function_parameters
     */
    public static function addbookingoption_parameters() {
        return new external_function_parameters(
                array(
                        'name' => new external_value(PARAM_TEXT,
                            'Booking option name', VALUE_REQUIRED),
                        'titleprefix' => new external_value(PARAM_RAW,
                            'Optional prefix to be shown before title', VALUE_DEFAULT, null),
                        'targetcourseid' => new external_value(PARAM_INT,
                                'Id of course where this booking option should be created.', VALUE_DEFAULT, null),
                        'courseid' => new external_value(PARAM_INT,
                            'Id of course to book users to.', VALUE_DEFAULT, null),
                        'bookingcmid' => new external_value(PARAM_INT,
                            'Moodle id of booking activity.', VALUE_DEFAULT, null),
                        'bookingidnumber' => new external_value(PARAM_RAW,
                            'Idnumber identifier of target booking activity.', VALUE_DEFAULT, null),
                        'bookingoptionid' => new external_value(PARAM_INT,
                            'Moodle Id of booking option. Allows to update option.', VALUE_DEFAULT, null),
                        'courseidnumber' => new external_value(PARAM_RAW,
                            'Idnumber identifier of target course. Overriden by bookingidnumber.', VALUE_DEFAULT, null),
                        'courseshortname' => new external_value(PARAM_RAW,
                            'Shortname of target course. Overriden by bookingidnumber.', VALUE_DEFAULT, null),
                        'maxanswers' => new external_value(PARAM_INT,
                            'Max places', VALUE_DEFAULT, null),
                        'maxoverbooking' => new external_value(PARAM_INT,
                            'Max places on waitinglist', VALUE_DEFAULT, null),
                        'bookingclosingtime' => new external_value(PARAM_RAW,
                            'Time when booking is not possible anymore.', VALUE_DEFAULT, null),
                        'enrolmentstatus' => new external_value(PARAM_INT,
                            '0 enrol at coursestart; 1 enrolment done; 2 immediately enrol', VALUE_DEFAULT, null),
                        'description' => new external_value(PARAM_TEXT,
                            'Description', VALUE_DEFAULT, ''),
                        'descriptionformat' => new external_value(PARAM_INT,
                            'Description format', VALUE_DEFAULT, 0),
                        'limitanswers' => new external_value(PARAM_INT,
                            'Only limited number of answeres allowed', VALUE_DEFAULT, 0),
                        'addtocalendar' => new external_value(PARAM_INT,
                            'To add to calendar set to 1, else 0.', VALUE_DEFAULT, null),
                        'pollurl' => new external_value(PARAM_URL,
                            'Poll url', VALUE_DEFAULT, null),
                        'location' => new external_value(PARAM_RAW,
                            'Location', VALUE_DEFAULT, null),
                        'institution' => new external_value(PARAM_RAW,
                            'Institution', VALUE_DEFAULT, null),
                        'address' => new external_value(PARAM_RAW,
                            'Address', VALUE_DEFAULT, null),
                        'credits' => new external_value(PARAM_RAW,
                            'Credits', VALUE_DEFAULT, null),                      
                        'pollurlteachers' => new external_value(PARAM_URL,
                            'Poll url for teachers', VALUE_DEFAULT, null),
                        'howmanyusers' => new external_value(PARAM_INT,
                            'How many users', VALUE_DEFAULT, null),
                        'removeafterminutes' => new external_value(PARAM_INT,
                            'Time to remove booking option in minutes.', VALUE_DEFAULT, null),
                        'notificationtext' => new external_value(PARAM_TEXT,
                            'Notification text', VALUE_DEFAULT, null),
                        'notificationtextformat' => new external_value(PARAM_INT,
                            'Notification text format', VALUE_DEFAULT, null),
                        'disablebookingusers' => new external_value(PARAM_INT,
                            'Set to 1 to disable booking, else 0.', VALUE_DEFAULT, 0),
                        'beforebookedtext' => new external_value(PARAM_INT,
                            'Max waintinglist', VALUE_DEFAULT, null),
                        'beforecompletedtext' => new external_value(PARAM_TEXT,
                            'Text to show before completion.', VALUE_DEFAULT, null),
                        'aftercompletedtext' => new external_value(PARAM_TEXT,
                            'Text to show after completion.', VALUE_DEFAULT, null),
                        'shorturl' => new external_value(PARAM_URL,
                            'Add short url for this option.', VALUE_DEFAULT, null),
                        'duration' => new external_value(PARAM_INT,
                            'Duration', VALUE_DEFAULT, 0),
                        'useremail' => new external_value(PARAM_EMAIL,
                            'Email of user to inscribe. User must exist in system.', VALUE_DEFAULT, null),
                        'teacheremail' => new external_value(PARAM_EMAIL,
                            'Email of teacher. User must exist in system.', VALUE_DEFAULT, null),
                        'user_username' => new external_value(PARAM_RAW,
                            'Username of user to inscribe. User must exist in system.', VALUE_DEFAULT, null),
                        'coursestarttime' => new external_value(PARAM_TEXT,
                            'Time when booking option starts.', VALUE_DEFAULT, null),
                        'courseendtime' => new external_value(PARAM_TEXT,
                            'Time when booking option ends.', VALUE_DEFAULT, null),
                        'invisible' => new external_value(PARAM_INT,
                            'Default is 0 and visible. 1 will make the option invisible to students.',
                            VALUE_DEFAULT, 0),
                        'mergeparam' => new external_value(PARAM_INT,
                            'To upload multisession in consecutive steps or to add teachers to option.
                            0 is no multisession, 1 is create ms, 2 is merge with previous, 3 is merge teacher to option',
                            VALUE_DEFAULT, null)
                )
        );
    }

    /**
     * Define return values for addbookingoption function.
     * @return external_single_structure
     */
    public static function addbookingoption_returns() {
        return new external_single_structure(
                array(
                    'status' => new external_value(PARAM_BOOL, 'status: true if success')
                )
        );
    }


    /**
     * Returns description of method result value.
     *
     * @return \external_single_structure
     * @since Moodle 3.0
     */
    public static function get_booking_option_description_returns() {
        return new external_single_structure(
                array('content' => new external_value(PARAM_RAW, 'json object as string'),
                      'template' => new \external_value(PARAM_TEXT, 'the template to render the content')
                ));
    }

    /**
     * Function get_booking_option_description
     *
     * @return external_function_parameters
     */
    public static function get_booking_option_description_parameters() {
        return new external_function_parameters(
                array('optionid' => new external_value(PARAM_INT, 'Option id', VALUE_REQUIRED, 0),
                      'userid' => new external_value(PARAM_TEXT, 'userid', VALUE_REQUIRED, 0)
                )
        );
    }

    /**
     * function get_booking_option_description
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    public static function get_booking_option_description($optionid, $userid) {

        $params = self::validate_parameters(self::get_booking_option_description_parameters(),
                array('optionid' => $optionid, 'userid' => $userid));

        $booking = singleton_service::get_instance_of_booking_by_optionid($optionid);

        if ($userid > 0) {
            $user = singleton_service::get_instance_of_user($userid);
        } else {
            $user = null;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings, $userid);

        // Check if user is booked.
        $forbookeduser = $bookinganswer->user_status($userid) == 1 ? true : false;

        $data = new \mod_booking\output\bookingoption_description($booking, $optionid,
                null, DESCRIPTION_WEBSITE, true, $forbookeduser, $user);

        // Fix invisible attribute, by converting to boolean.
        if (isset($data->invisible) && $data->invisible == 1) {
            $data->invisible = true;
        } else {
            $data->invisible = false;
        }

        return ['content' => json_encode($data), 'template' => 'mod_booking/bookingoption_description'];
    }

    /**
     * Functionality of toggle_notify_user
     *
     * @return external_function_parameters
     */
    public static function toggle_notify_user(int $userid, int $optionid) {

        $params = self::validate_parameters(self::toggle_notify_user_parameters(),
                array('optionid' => $optionid, 'userid' => $userid));

        $result = booking_option::toggle_notify_user($params['userid'], $params['optionid']);

        return $result;
    }

    /**
     * Function for toggle_notify_user returns
     *
     * @return external_function_parameters
     */
    public static function toggle_notify_user_returns() {
        return new external_function_parameters(
                [
                    'status' => new external_value(PARAM_INT, 'Status 1 for user is now on list, 0 for not on list.',
                        VALUE_REQUIRED),
                    'optionid' => new external_value(PARAM_INT, 'option id', VALUE_REQUIRED),
                    'error' => new external_value(PARAM_RAW, 'error', VALUE_OPTIONAL, ''),
                ]
        );
    }

    /**
     * Function for toggle_notify_user paramters
     *
     * @return external_function_parameters
     */
    public static function toggle_notify_user_parameters() {
        return new external_function_parameters(
                array('userid' => new external_value(PARAM_INT, 'user id', VALUE_REQUIRED, 0),
                      'optionid' => new external_value(PARAM_TEXT, 'option id', VALUE_REQUIRED, 0)
                )
        );
    }
}
