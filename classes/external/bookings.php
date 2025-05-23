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
 * This class contains a webservice function returns bookings for course id.
 *
 * @package    mod_booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use external_api;
use external_files;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_util;
use external_value;
use mod_booking\booking;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for return bookings for course id.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookings extends external_api {
    /**
     * Describes the parameters for booking.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_TEXT, 'Course id', VALUE_DEFAULT, ''),
            'printusers' => new external_value(PARAM_TEXT, 'Print user profiles', VALUE_DEFAULT, ''),
            'days' => new external_value(PARAM_TEXT, 'How old bookings to retrive - in days.', VALUE_DEFAULT, ''),
            ]);
    }

    /**
     * Webservice for return bookings for course id.
     *
     * @param string $courseid
     * @param string $printusers
     * @param string $days
     *
     * @return array
     *
     */
    public static function execute($courseid = '0', $printusers = '0', $days = '0'): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/booking/locallib.php');

        $returns = [];

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'printusers' => $printusers, 'days' => $days]
        );

        $bookings = $DB->get_records_select("booking", "course = {$courseid}");

        foreach ($bookings as $booking) {
            $ret = [];
            $cm = get_coursemodule_from_instance('booking', $booking->id);

            $options = [];

            if ($days > 0) {
                $timediff = strtotime('-' . $days . ' day', time());
                $options['coursestarttime'] = $timediff;
            }

            $context = context_module::instance($cm->id);

            if (strcmp($cm->visible, "1") == 0 || has_capability('mod/booking:bookforothers', $context)) {
                $bookingdata = singleton_service::get_instance_of_booking_by_cmid((int)$cm->id);

                if ($bookingdata->settings->showinapi == "1") {
                    $bookingdata->apply_tags();
                    $context = context_module::instance($cm->id);

                    $bookingdata->settings->intro = file_rewrite_pluginfile_urls(
                        $bookingdata->settings->intro,
                        'pluginfile.php',
                        $context->id,
                        'mod_booking',
                        'intro',
                        0
                    );

                    $manager = $DB->get_record('user', ['username' => $bookingdata->settings->bookingmanager]);

                    $ret['id'] = $bookingdata->settings->id;
                    $ret['cm'] = $bookingdata->cm->id;
                    $ret['timemodified'] = $bookingdata->settings->timemodified;
                    $ret['name'] = $bookingdata->settings->name;
                    $ret['intro'] = $bookingdata->settings->intro;
                    $ret['duration'] = $bookingdata->settings->duration;
                    $ret['points'] = $bookingdata->settings->points;
                    $ret['organizatorname'] = $bookingdata->settings->organizatorname;
                    $ret['eventtype'] = $bookingdata->settings->eventtype;
                    $ret['bookingmanagerid'] = $manager->id ?? 0;
                    $ret['bookingmanagername'] = $manager->firstname ?? '';
                    $ret['bookingmanagersurname'] = $manager->lastname ?? '';
                    $ret['bookingmanageremail'] = $manager->email ?? '';
                    $ret['myfilemanager'] = external_util::get_area_files(
                        $context->id,
                        'mod_booking',
                        'myfilemanager',
                        0,
                        false
                    );
                    $ret['categories'] = [];
                    $ret['options'] = [];

                    if ($bookingdata->settings->categoryid != '0' && $bookingdata->settings->categoryid != '') {
                        $categoryies = explode(',', $bookingdata->settings->categoryid);

                        if (!empty($categoryies) && count($categoryies) > 0) {
                            foreach ($categoryies as $category) {
                                $cat = [];
                                $cat['id'] = $category;
                                $cat['name'] = $DB->get_field('booking_category', 'name', ['id' => $category]);

                                $ret['categories'][] = $cat;
                            }
                        }
                    }

                    foreach ($bookingdata->get_all_options(0, 0, '', '*') as $record) {
                        $option = [];
                        $option['id'] = $record->id;
                        $option['text'] = $record->text;
                        $option['timemodified'] = $record->timemodified;
                        $option['maxanswers'] = $record->maxanswers;
                        $option['coursestarttime'] = $record->coursestarttime;
                        $option['courseendtime'] = $record->courseendtime;
                        $option['description'] = $record->description;
                        $option['location'] = $record->location;
                        $option['institution'] = $record->institution;
                        $option['address'] = $record->address;
                        $option['users'] = [];
                        $option['teachers'] = [];

                        $settings = singleton_service::get_instance_of_booking_option_settings($record->id);

                        if ($printusers) {
                            $ba = singleton_service::get_instance_of_booking_answers($settings);

                            foreach ($ba->usersonlist as $user) {
                                $tmpuser = [];
                                $ruser = singleton_service::get_instance_of_user((int)$user->userid);
                                $tmpuser['id'] = $ruser->id;
                                $tmpuser['username'] = $ruser->username;
                                $tmpuser['firstname'] = $ruser->firstname;
                                $tmpuser['lastname'] = $ruser->lastname;
                                $tmpuser['email'] = $ruser->email;

                                $option['users'][] = $tmpuser;
                            }
                        }

                        foreach ($settings->teachers as $user) {
                            $teacher = [];
                            $teacher['id'] = $user->userid ?? '';
                            $teacher['username'] = $user->username;
                            $teacher['firstname'] = $user->firstname;
                            $teacher['lastname'] = $user->lastname;
                            $teacher['email'] = $user->email;

                            $option['teachers'][] = $teacher;
                        }

                        $option['sessions'] = [];
                        foreach ($settings->sessions as $session) {
                            $option['sessions'][] = [
                                'id' => $session->id,
                                'coursestarttime' => $session->coursestarttime,
                                'courseendtime' => $session->courseendtime,
                            ];
                        }

                        $ret['options'][] = $option;
                    }

                    $returns[] = $ret;
                }
            }
        }
        return $returns;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'Booking ID'),
                    'cm' => new external_value(PARAM_INT, 'CM'),
                    'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    'name' => new external_value(PARAM_TEXT, 'Course name'),
                    'intro' => new external_value(PARAM_RAW, 'Description'),
                    'duration' => new external_value(PARAM_TEXT, 'Duration'),
                    'points' => new external_value(PARAM_RAW, 'Points'),
                    'organizatorname' => new external_value(PARAM_TEXT, 'Organizator name'),
                    'eventtype' => new external_value(PARAM_TEXT, 'Event type'),
                    'bookingmanagerid' => new external_value(PARAM_INT, 'Booking manager ID'),
                    'bookingmanagername' => new external_value(PARAM_TEXT, 'Booking manager name'),
                    'bookingmanagersurname' => new external_value(PARAM_TEXT, 'Booking manager surname'),
                    'bookingmanageremail' => new external_value(PARAM_TEXT, 'Booking manager e-mail'),
                    'myfilemanager' => new external_files('Attachment', VALUE_OPTIONAL),
                    'categories' => new external_multiple_structure(new external_single_structure(
                        [
                            'id' => new external_value(PARAM_INT, 'Category ID'),
                            'name' => new external_value(PARAM_TEXT, 'Category name'),
                        ]
                    )),
                    'options' => new external_multiple_structure(new external_single_structure(
                        [
                            'id' => new external_value(PARAM_INT, 'Option ID'),
                            'text' => new external_value(PARAM_TEXT, 'Description'),
                            'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                            'maxanswers' => new external_value(PARAM_INT, 'Max participants'),
                            'coursestarttime' => new external_value(PARAM_INT, 'Start time'),
                            'courseendtime' => new external_value(PARAM_INT, 'End time'),
                            'description' => new external_value(PARAM_RAW, 'Description'),
                            'location' => new external_value(PARAM_TEXT, 'Location'),
                            'institution' => new external_value(PARAM_TEXT, 'Institution'),
                            'address' => new external_value(PARAM_TEXT, 'Address'),
                            'users' => new external_multiple_structure(new external_single_structure(
                                [
                                    'id' => new external_value(PARAM_INT, 'User ID'),
                                    'username' => new external_value(PARAM_TEXT, 'Username'),
                                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                    'lastname' => new external_value(PARAM_TEXT, 'First'),
                                    'email' => new external_value(PARAM_TEXT, 'Email'),
                                ]
                            )),
                            'teachers' => new external_multiple_structure(new external_single_structure(
                                [
                                    'id' => new external_value(PARAM_INT, 'User ID'),
                                    'username' => new external_value(PARAM_TEXT, 'Username'),
                                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                    'lastname' => new external_value(PARAM_TEXT, 'First'),
                                    'email' => new external_value(PARAM_TEXT, 'Email'),
                                ]
                            )),
                            'sessions' => new external_multiple_structure(new external_single_structure(
                                [
                                    'id' => new external_value(PARAM_INT, 'User ID', VALUE_OPTIONAL),
                                    'coursestarttime' => new external_value(PARAM_INT, 'Coursestarttime', VALUE_OPTIONAL),
                                    'courseendtime' => new external_value(PARAM_INT, 'Courseendtime', VALUE_OPTIONAL),
                                ]
                            )),
                        ]
                    )),
                ]
            )
        );
    }
}
