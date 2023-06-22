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
 * This class contains a webservice function related to the Booking Module by Wunderbyte.
 *
 * @package    mod_booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use external_warnings;
use mod_booking\booking_option;
use mod_booking\utils\webservice_import;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service to create a booking option.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class addbookingoption extends external_api {

    /**
     * Describes the parameters for unenrol user.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_TEXT,
                'Booking option name'),
            'identifier' => new external_value(PARAM_RAW,
                'Unique identifier for booking option', (bool) VALUE_DEFAULT, null),
            'titleprefix' => new external_value(PARAM_RAW,
                'Optional prefix to be shown before title', (bool) VALUE_DEFAULT, null),
            'targetcourseid' => new external_value(PARAM_INT,
                'Id of course where this booking option should be created.', (bool) VALUE_DEFAULT, null),
            'courseid' => new external_value(PARAM_INT,
                'Id of course to book users to.', (bool) VALUE_DEFAULT, null),
            'bookingcmid' => new external_value(PARAM_INT,
                'Moodle id of booking activity.', (bool) VALUE_DEFAULT, null),
            'bookingidnumber' => new external_value(PARAM_RAW,
                'Idnumber identifier of target booking activity.', (bool) VALUE_DEFAULT, null),
            'bookingoptionid' => new external_value(PARAM_INT,
                'Moodle Id of booking option. Allows to update option.', (bool) VALUE_DEFAULT, null),
            'courseidnumber' => new external_value(PARAM_RAW,
                'Idnumber identifier of target course. Overriden by bookingidnumber.', (bool) VALUE_DEFAULT, null),
            'courseshortname' => new external_value(PARAM_RAW,
                'Shortname of target course. Overriden by bookingidnumber.', (bool) VALUE_DEFAULT, null),
            'maxanswers' => new external_value(PARAM_INT,
                'Max places', (bool) VALUE_DEFAULT, null),
            'maxoverbooking' => new external_value(PARAM_INT,
                'Max places on waitinglist', (bool) VALUE_DEFAULT, null),
            'minanswers' => new external_value(PARAM_INT,
                'Minimum number of participants', (bool) VALUE_DEFAULT, null),
            'bookingclosingtime' => new external_value(PARAM_RAW,
                'Time when booking is not possible anymore.', (bool) VALUE_DEFAULT, null),
            'bookingopeningtime' => new external_value(PARAM_RAW,
                'Time until when booking is not yet possible.', (bool) VALUE_DEFAULT, null),
            'enrolmentstatus' => new external_value(PARAM_INT,
                '0 enrol at coursestart; 1 enrolment done; 2 immediately enrol', (bool) VALUE_DEFAULT, null),
            'description' => new external_value(PARAM_RAW,
                'Description', (bool) VALUE_DEFAULT, ''),
            'descriptionformat' => new external_value(PARAM_INT,
                'Description format', (bool) VALUE_DEFAULT, 0),
            'limitanswers' => new external_value(PARAM_INT,
                'Only limited number of answeres allowed', (bool) VALUE_DEFAULT, 0),
            'addtocalendar' => new external_value(PARAM_INT,
                'To add to calendar set to 1, else 0.', (bool) VALUE_DEFAULT, null),
            'pollurl' => new external_value(PARAM_URL,
                'Poll url', (bool) VALUE_DEFAULT, null),
            'location' => new external_value(PARAM_RAW,
                'Location', (bool) VALUE_DEFAULT, null),
            'institution' => new external_value(PARAM_RAW,
                'Institution', (bool) VALUE_DEFAULT, null),
            'address' => new external_value(PARAM_RAW,
                'Address', (bool) VALUE_DEFAULT, null),
            'pollurlteachers' => new external_value(PARAM_URL,
                'Poll url for teachers', (bool) VALUE_DEFAULT, null),
            'howmanyusers' => new external_value(PARAM_INT,
                'How many users', (bool) VALUE_DEFAULT, null),
            'removeafterminutes' => new external_value(PARAM_INT,
                'Time to remove booking option in minutes.', (bool) VALUE_DEFAULT, null),
            'notificationtext' => new external_value(PARAM_TEXT,
                'Notification text', (bool) VALUE_DEFAULT, null),
            'notificationtextformat' => new external_value(PARAM_INT,
                'Notification text format', (bool) VALUE_DEFAULT, null),
            'disablebookingusers' => new external_value(PARAM_INT,
                'Set to 1 to disable booking, else 0.', (bool) VALUE_DEFAULT, 0),
            'beforebookedtext' => new external_value(PARAM_INT,
                'Max waintinglist', (bool) VALUE_DEFAULT, null),
            'beforecompletedtext' => new external_value(PARAM_TEXT,
                'Text to show before completion.', (bool) VALUE_DEFAULT, null),
            'aftercompletedtext' => new external_value(PARAM_RAW,
                'Text to show after completion.', (bool) VALUE_DEFAULT, null),
            'shorturl' => new external_value(PARAM_URL,
                'Add short url for this option.', (bool) VALUE_DEFAULT, null),
            'duration' => new external_value(PARAM_INT,
                'Duration', (bool) VALUE_DEFAULT, 0),
            'useremail' => new external_value(PARAM_EMAIL,
                'Email of user to inscribe. User must exist in system.', (bool) VALUE_DEFAULT, null),
            'teacheremail' => new external_value(PARAM_EMAIL,
                'Email of teacher. User must exist in system.', (bool) VALUE_DEFAULT, null),
            'user_username' => new external_value(PARAM_RAW,
                'Username of user to inscribe. User must exist in system.', (bool) VALUE_DEFAULT, null),
            'coursestarttime' => new external_value(PARAM_TEXT,
                'Time when booking option starts.', (bool) VALUE_DEFAULT, null),
            'courseendtime' => new external_value(PARAM_TEXT,
                'Time when booking option ends.', (bool) VALUE_DEFAULT, null),
            'invisible' => new external_value(PARAM_INT,
                'Default is 0 and visible. 1 will make the option invisible to students.', (bool) VALUE_DEFAULT, 0),
            'responsiblecontact' => new external_value(PARAM_RAW,
                'Responsible contact as e-mails, semicolon separated', (bool) VALUE_DEFAULT, ''),
            'boav_enrolledincourse' => new external_value(PARAM_RAW,
                'Booking Condition enrolled courses with shortnames, semicolon separated', (bool) VALUE_DEFAULT, ''),
            'mergeparam' => new external_value(PARAM_INT,
                'To upload multisession in consecutive steps or to add teachers to option.
                0 is no multisession, 1 is create ms, 2 is merge with previous, 3 is merge teacher to option',
                (bool) VALUE_DEFAULT, null)
            ]
        );
    }

    /**
     * By this function it's possible to create a booking option via webservice.
     *
     * @return array
     */
    public static function execute(
                        string $name,
                        string $identifier,
                        string $titleprefix = null,
                        int $targetcourseid = null,
                        int $courseid = null,
                        int $bookingid = null,
                        $bookingidnumber = null,
                        int $bookingoptionid = null,
                        $courseidnumber = null,
                        string $courseshortname = null,
                        int $maxanswers = null,
                        int $maxoverbooking = null,
                        int $minanswers = null,
                        string $bookingopeningtime = null,
                        string $bookingclosingtime = null,
                        int $enrolmentstatus = null,
                        string $description = null,
                        int $descriptionformat = 0,
                        int $limitanswers = 0,
                        int $addtocalendar = null,
                        string $pollurl = null,
                        string $location = null,
                        string $institution = null,
                        string $address = null,
                        string $pollurlteachers = null,
                        int $howmanyusers = null,
                        int $removeafterminutes = null,
                        string $notifcationtext = null,
                        int $notifcationtextformat = null,
                        int $disablebookingusers = 0,
                        int $beforebookedtext = null,
                        string $beforecompletedtext = null,
                        string $aftercompletedtext = null,
                        string $shorturl = null,
                        int $duration = 0,
                        string $useremail = null,
                        string $teacheremail = null,
                        string $userusername = null,
                        string $coursestarttime = null,
                        string $courseendtime = null,
                        int $invisible = 0,
                        string $responsiblecontact = null,
                        string $boav_enrolledincourse = null,
                        int $mergeparam = null
                    ): array {

        $params = self::validate_parameters(self::execute_parameters(),
                array(
                        'name' => $name,
                        'identifier' => $identifier,
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
                        'minanswers' => $minanswers,
                        'bookingopeningtime' => $bookingopeningtime,
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
                        'responsiblecontact' => $responsiblecontact,
                        'boav_enrolledincourse' => $boav_enrolledincourse,
                        'mergeparam' => $mergeparam
                    ));

        // We want to pass on an object to, so we clean all unnecessary values.
        $cleanedarray = array_filter($params, function($x) {
            return $x !== null;
        });

        $importer = new webservice_import();

        return $importer->process_data((object)$cleanedarray);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'status: true if success')
            ]
        );
    }
}
