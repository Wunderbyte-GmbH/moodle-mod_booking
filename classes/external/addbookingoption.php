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
            'name' => new external_value(PARAM_TEXT, 'Booking option name', VALUE_REQUIRED),
            'identifier' => new external_value(
                PARAM_RAW,
                'Unique identifier for booking option',
                VALUE_DEFAULT,
                ''
            ),
            'titleprefix' => new external_value(
                PARAM_RAW,
                'Optional prefix to be shown before title',
                VALUE_DEFAULT,
                ''
            ),
            'targetcourseid' => new external_value(
                PARAM_INT,
                'Id of course where this booking option should be created.',
                VALUE_DEFAULT,
                0
            ),
            'courseid' => new external_value(
                PARAM_INT,
                'Id of course to book users to.',
                VALUE_DEFAULT,
                0
            ),
            'bookingcmid' => new external_value(
                PARAM_INT,
                'Moodle id of booking activity.',
                VALUE_DEFAULT,
                0
            ),
            'bookingidnumber' => new external_value(
                PARAM_RAW,
                'Idnumber identifier of target booking activity.',
                VALUE_DEFAULT,
                ''
            ),
            'bookingoptionid' => new external_value(
                PARAM_INT,
                'Moodle Id of booking option. Allows to update option.',
                VALUE_DEFAULT,
                0
            ),
            'courseidnumber' => new external_value(
                PARAM_RAW,
                'Idnumber identifier of target course. Overriden by bookingidnumber.',
                VALUE_DEFAULT,
                ''
            ),
            'courseshortname' => new external_value(
                PARAM_RAW,
                'Shortname of target course. Overriden by bookingidnumber.',
                VALUE_DEFAULT,
                ''
            ),
            'enroltocourseshortname' => new external_value(
                PARAM_RAW,
                'Shortname of course users will be enrolled to.',
                VALUE_DEFAULT,
                ''
            ),
            'maxanswers' => new external_value(
                PARAM_INT,
                'Max places',
                VALUE_DEFAULT,
                0
            ),
            'maxoverbooking' => new external_value(
                PARAM_INT,
                'Max places on waitinglist',
                VALUE_DEFAULT,
                0
            ),
            'minanswers' => new external_value(
                PARAM_INT,
                'Minimum number of participants',
                VALUE_DEFAULT,
                0
            ),
            'bookingopeningtime' => new external_value(
                PARAM_RAW,
                'Time until when booking is not yet possible.',
                VALUE_DEFAULT,
                ''
            ),
            'bookingclosingtime' => new external_value(
                PARAM_RAW,
                'Time when booking is not possible anymore.',
                VALUE_DEFAULT,
                ''
            ),
            'enrolmentstatus' => new external_value(
                PARAM_INT,
                '0 enrol at coursestart; 1 enrolment done; 2 immediately enrol',
                VALUE_DEFAULT,
                0
            ),
            'description' => new external_value(
                PARAM_RAW,
                'Description',
                VALUE_DEFAULT,
                ''
            ),
            'descriptionformat' => new external_value(
                PARAM_INT,
                'Description format',
                VALUE_DEFAULT,
                0
            ),
            'limitanswers' => new external_value(
                PARAM_INT,
                'Only limited number of answeres allowed',
                VALUE_DEFAULT,
                0
            ),
            'addtocalendar' => new external_value(
                PARAM_INT,
                'To add to calendar set to 1, else 0.',
                VALUE_DEFAULT,
                0
            ),
            'pollurl' => new external_value(
                PARAM_URL,
                'Poll url',
                VALUE_DEFAULT,
                ''
            ),
            'location' => new external_value(
                PARAM_RAW,
                'Location',
                VALUE_DEFAULT,
                ''
            ),
            'institution' => new external_value(
                PARAM_RAW,
                'Institution',
                VALUE_DEFAULT,
                ''
            ),
            'address' => new external_value(
                PARAM_RAW,
                'Address',
                VALUE_DEFAULT,
                ''
            ),
            'pollurlteachers' => new external_value(
                PARAM_URL,
                'Poll url for teachers',
                VALUE_DEFAULT,
                ''
            ),
            'howmanyusers' => new external_value(
                PARAM_INT,
                'How many users',
                VALUE_DEFAULT,
                0
            ),
            'removeafterminutes' => new external_value(
                PARAM_INT,
                'Time to remove booking option in minutes.',
                VALUE_DEFAULT,
                0
            ),
            'notificationtext' => new external_value(
                PARAM_TEXT,
                'Notification text',
                VALUE_DEFAULT,
                ''
            ),
            'notificationtextformat' => new external_value(
                PARAM_INT,
                'Notification text format',
                VALUE_DEFAULT,
                0
            ),
            'disablebookingusers' => new external_value(
                PARAM_INT,
                'Set to 1 to disable booking, else 0.',
                VALUE_DEFAULT,
                0
            ),
            'beforebookedtext' => new external_value(
                PARAM_RAW,
                'Before booked text',
                VALUE_DEFAULT,
                ''
            ),
            'beforecompletedtext' => new external_value(
                PARAM_RAW,
                'Text to show before completion.',
                VALUE_DEFAULT,
                ''
            ),
            'aftercompletedtext' => new external_value(
                PARAM_RAW,
                'Text to show after completion.',
                VALUE_DEFAULT,
                ''
            ),
            'shorturl' => new external_value(
                PARAM_URL,
                'Add short url for this option.',
                VALUE_DEFAULT,
                ''
            ),
            'duration' => new external_value(
                PARAM_INT,
                'Duration',
                VALUE_DEFAULT,
                0
            ),
            'useremail' => new external_value(
                PARAM_EMAIL,
                'Email of user to inscribe. User must exist in system.',
                VALUE_DEFAULT,
                ''
            ),
            'teacheremail' => new external_value(
                PARAM_EMAIL,
                'Email of teacher. User must exist in system.',
                VALUE_DEFAULT,
                ''
            ),
            'user_username' => new external_value(
                PARAM_RAW,
                'Username of user to inscribe. User must exist in system.',
                VALUE_DEFAULT,
                ''
            ),
            'coursestarttime' => new external_value(
                PARAM_TEXT,
                'Time when booking option starts.',
                VALUE_DEFAULT,
                ''
            ),
            'courseendtime' => new external_value(
                PARAM_TEXT,
                'Time when booking option ends.',
                VALUE_DEFAULT,
                ''
            ),
            'invisible' => new external_value(
                PARAM_INT,
                'Default is 0 and visible. 1 will make the option invisible to students.',
                VALUE_DEFAULT,
                0
            ),
            'responsiblecontact' => new external_value(
                PARAM_RAW,
                'Responsible contact as e-mail. Only one possible.',
                VALUE_DEFAULT,
                ''
            ),
            'boavenrolledincourse' => new external_value(
                PARAM_RAW,
                'Booking Condition enrolled courses with shortnames, comma separated',
                VALUE_DEFAULT,
                ''
            ),
            'boavenrolledincohorts' => new external_value(
                PARAM_RAW,
                'Booking Condition enrolled cohorts with shortnames, comma separated',
                VALUE_DEFAULT,
                ''
            ),
            'recommendedin' => new external_value(
                PARAM_RAW,
                'This is for the recommendedin-feature and takes the shortnames of the courses, separated by commas.',
                VALUE_DEFAULT,
                ''
            ),
            'mergeparam' => new external_value(
                PARAM_INT,
                'To upload multisession in consecutive steps or to add teachers to option.
                0 is no multisession, 1 is create ms, 2 is merge with previous, 3 is merge teacher to option',
                VALUE_DEFAULT,
                0
            ),
            ]);
    }

    /**
     * By this function it's possible to create a booking option via webservice.
     *
     * @param string $name
     * @param string $identifier
     * @param string|null $titleprefix
     * @param int|null $targetcourseid
     * @param int|null $courseid
     * @param int|null $bookingid
     * @param string|null $bookingidnumber
     * @param int|null $bookingoptionid
     * @param string|null $courseidnumber
     * @param string|null $courseshortname
     * @param string|null $enroltocourseshortname
     * @param int|null $maxanswers
     * @param int|null $maxoverbooking
     * @param int|null $minanswers
     * @param string|null $bookingopeningtime
     * @param string|null $bookingclosingtime
     * @param int|null $enrolmentstatus
     * @param string|null $description
     * @param int|null $descriptionformat
     * @param int|null $limitanswers
     * @param int|null $addtocalendar
     * @param string|null $pollurl
     * @param string|null $location
     * @param string|null $institution
     * @param string|null $address
     * @param string|null $pollurlteachers
     * @param int|null $howmanyusers
     * @param int|null $removeafterminutes
     * @param string|null $notifcationtext
     * @param int|null $notifcationtextformat
     * @param int|null $disablebookingusers
     * @param string|null $beforebookedtext
     * @param string|null $beforecompletedtext
     * @param string|null $aftercompletedtext
     * @param string|null $shorturl
     * @param int|null $duration
     * @param string|null $useremail
     * @param string|null $teacheremail
     * @param string|null $userusername
     * @param string|null $coursestarttime
     * @param string|null $courseendtime
     * @param int|null $invisible
     * @param string|null $responsiblecontact
     * @param string|null $boavenrolledincourse
     * @param string|null $boavenrolledincohorts
     * @param string|null $recommendedin
     * @param int|null $mergeparam
     * @return array
     * @throws \invalid_parameter_exception
     */
    public static function execute(
        string $name,
        string $identifier,
        ?string $titleprefix = null,
        ?int $targetcourseid = null,
        ?int $courseid = null,
        ?int $bookingid = null,
        ?string $bookingidnumber = null,
        ?int $bookingoptionid = null,
        ?string $courseidnumber = null,
        ?string $courseshortname = null,
        ?string $enroltocourseshortname = null,
        ?int $maxanswers = null,
        ?int $maxoverbooking = null,
        ?int $minanswers = null,
        ?string $bookingopeningtime = null,
        ?string $bookingclosingtime = null,
        ?int $enrolmentstatus = null,
        ?string $description = null,
        ?int $descriptionformat = 0,
        ?int $limitanswers = 0,
        ?int $addtocalendar = null,
        ?string $pollurl = null,
        ?string $location = null,
        ?string $institution = null,
        ?string $address = null,
        ?string $pollurlteachers = null,
        ?int $howmanyusers = null,
        ?int $removeafterminutes = null,
        ?string $notifcationtext = null,
        ?int $notifcationtextformat = null,
        ?int $disablebookingusers = 0,
        ?string $beforebookedtext = null,
        ?string $beforecompletedtext = null,
        ?string $aftercompletedtext = null,
        ?string $shorturl = null,
        ?int $duration = 0,
        ?string $useremail = null,
        ?string $teacheremail = null,
        ?string $userusername = null,
        ?string $coursestarttime = null,
        ?string $courseendtime = null,
        ?int $invisible = 0,
        ?string $responsiblecontact = null,
        ?string $boavenrolledincourse = null,
        ?string $boavenrolledincohorts = null,
        ?string $recommendedin = null,
        ?int $mergeparam = null
    ): array {

        $params = external_api::validate_parameters(
            self::execute_parameters(),
            [
                        'name' => $name,
                        'identifier' => $identifier,
                        'titleprefix' => $titleprefix, // Optional prefix to be shown before title.
                        'targetcourseid' => $targetcourseid, // Id of course where the booking option should be created.
                        'courseid' => $courseid, // Id of course where users should be inscribed when booked.
                        'bookingcmid' => $bookingid, // Moodle cm ID of the target booking instance.
                        'bookingidnumber' => $bookingidnumber, // Idnumber of target booking instance.
                        'courseidnumber' => $courseidnumber, // Way of identifying target course via idnumber.
                        'courseshortname' => $courseshortname, // Way of identifiying target course via shortname.
                        'enroltocourseshortname' => $enroltocourseshortname, // Shortname of the course useres will be enroled to.
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
                        'boavenrolledincourse' => $boavenrolledincourse,
                        'boavenrolledincohorts' => $boavenrolledincohorts,
                        'recommendedin' => $recommendedin,
                        'mergeparam' => $mergeparam,
            ]
        );

        // We want to pass on an object to, so we clean all unnecessary values.
        $cleanedarray = array_filter($params, function ($x) {
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
            'status' => new external_value(PARAM_BOOL, 'status: true if success'),
            ]);
    }
}
