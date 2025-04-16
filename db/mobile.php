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
 * Booking module mobile app features
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$addons = [
    "mod_booking" => [
        'handlers' => [
            'coursebooking' => [
                    'displaydata' => [
                        'icon' => $CFG->wwwroot . '/mod/booking/pix/icon.png',
                        'class' => '',
                    ],
                    'delegate' => 'CoreCourseModuleDelegate',
                    'method' => 'mobile_course_view',
                    'offlinefunctions' => [
                    ],
                ],
        ],
        'lang' => [
            ['pluginname', 'booking'],
            ['showmybookingsonly', 'booking'],
            ['mybookingsbooking', 'booking'],
            ['details', 'mod_booking'],
            ['showdates', 'mod_booking'],
            ['mobileappprice', 'mod_booking'],
            ['status', 'booking'],
            ['coursestarttime', 'booking'],
            ['courseendtime', 'booking'],
            ['description', 'mod_booking'],
            ['location', 'mod_booking'],
            ['address', 'mod_booking'],
            ['firstname', 'mod_booking'],
            ['lastname', 'mod_booking'],
            ['email', 'mod_booking'],
            ['next', 'mod_booking'],
            ['previous', 'mod_booking'],
            ['booking:choose', 'mod_booking'],
            ['areyousure:book', 'mod_booking'],
            ['cancelmyself', 'mod_booking'],
            ['areyousure:cancel', 'mod_booking'],
            ['booked', 'mod_booking'],
            ['mobilenotification', 'mod_booking'],
            ['mobilesubmittedsuccess', 'mod_booking'],
            ['mobileresetsubmission', 'mod_booking'],
            ['mobilesetsubmission', 'mod_booking'],
            ['mobilefieldrequired', 'mod_booking'],
            ['coursestart', 'mod_booking'],
            ['gotomoodlecourse', 'mod_booking'],
            ['showdescription', 'mod_booking'],
        ],
    ],
];
