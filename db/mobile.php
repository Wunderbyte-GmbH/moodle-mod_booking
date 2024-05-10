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
defined('MOODLE_INTERNAL') || die();

$addons = [
    "mod_booking" => [
        'handlers' => [
            'bookingslist' => [
                'displaydata' => [
                'icon' => $CFG->wwwroot . '/mod/booking/pix/icon.png', 'class' => ''
                ],
                'delegate' => 'CoreMainMenuDelegate',
                'method' => 'mobile_system_view',
                'offlinefunctions' => [
                ]
            ],
            'coursebooking' => [
                    'displaydata' => [
                    'icon' => $CFG->wwwroot . '/mod/booking/pix/icon.png', 'class' => ''
                    ],
                    'delegate' => 'CoreCourseModuleDelegate',
                    'method' => 'mobile_course_view',
                    'offlinefunctions' => [
                    ]
            ],
            'mybookingslist' => [ // Handler unique name (alphanumeric).
                'displaydata' => [
                    'title' => 'showmybookingsonly',
                    'icon' => 'document',
                    'class' => '',
                ],
                'delegate' => 'CoreMainMenuDelegate', // Delegate (where to display the link to the plugin).
                'method' => 'mobile_mybookings_list', // Main function in \mod_certificate\output\mobile.
                'offlinefunctions' => [
                ], // Function that needs to be downloaded for offline.
            ]
        ],
        'lang' => [
            ['pluginname', 'booking'],
            ['showmybookingsonly', 'booking'],
            ['showmybookingsonly', 'booking'],
            ['mybookingsbooking', 'booking'],
            ['details', 'mod_booking'],
            ['show_dates', 'mod_booking'],
            ['mobileapp_price', 'mod_booking'],
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
            ['booked', 'mod_booking'],
        ]
    ]
];
