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
            'coursebooking' => [
                    'displaydata' => [
                    'icon' => $CFG->wwwroot . '/mod/booking/pix/icon.png', 'class' => ''
                    ],
                    'delegate' => 'CoreCourseModuleDelegate',
                    'method' => 'mobile_course_view',
                    'init' => 'mobile_init',
                    'offlinefunctions' => [
                    ]
            ]
        ],
        'lang' => [
            ['pluginname', 'booking'],
            ['showmybookingsonly', 'booking'],
            ['showmybookingsonly', 'booking'],
            ['mybookingsbooking', 'booking'],
            ['status', 'booking'],
            ['coursestarttime', 'booking'],
            ['managepresence', 'booking'],
            ['wrongqrcode', 'booking'],
            ['confirmpresence', 'booking'],
            ['modulename', 'booking'],
            ['offlinesyncedlater', 'booking']
        ]
    ]
];
