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

$addons = array(

    "mod_booking" => array(
        'handlers' => array(
            'coursebooking' => array(
                'displaydata' => array(
                    'icon' => $CFG->wwwroot . '/mod/booking/pix/icon.gif', 'class' => ''
                ),
                'delegate' => 'CoreCourseModuleDelegate',
                'method' => 'mobile_course_view',
                'offlinefunctions' => array(
                )
            )
        ),
        'lang' => array(
            array('pluginname', 'booking'),
            array('showmybookingsonly', 'booking')
        )
    )
);