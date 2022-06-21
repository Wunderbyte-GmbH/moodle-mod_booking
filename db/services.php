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
 * Web service for mod booking
 *
 * @package mod_booking
 * @subpackage db
 * @since Moodle 3.4
 * @copyright 2018 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$functions = array(
    'mod_booking_update_bookingnotes' => array('classname' => 'mod_booking\external',
        'methodname' => 'update_bookingnotes', 'description' => 'Update the booking notes via AJAX',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'mod/booking:readresponses',
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE, 'local_mobile')),
    'mod_booking_enrol_user' => array('classname' => 'mod_booking\external',
        'methodname' => 'enrol_user', 'description' => 'Enrol user via AJAX', 'type' => 'write',
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE, 'local_mobile')),
    'mod_booking_unenrol_user' => array('classname' => 'mod_booking\external',
        'methodname' => 'unenrol_user', 'description' => 'Unenrol user via AJAX', 'type' => 'write',
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE, 'local_mobile')),
    'mod_booking_bookings' => array(
        'classname'   => 'mod_booking_external',
        'methodname'  => 'bookings',
        'classpath'   => 'mod/booking/externallib.php',
        'description' => 'Return bookings for course id.',
        'type'        => 'read',
    ),
    'mod_booking_categories' => array(
        'classname'   => 'mod_booking_external',
        'methodname'  => 'categories',
        'classpath'   => 'mod/booking/externallib.php',
        'description' => 'Return categories for course id.',
        'type'        => 'read',
    ),
    'mod_booking_instancetemplate' => array(
        'classname' => 'mod_booking\external',
        'methodname' => 'instancetemplate',
        'description' => 'Read booking instance template.',
        'type' => 'read',
        'ajax' => true
    ),
    'mod_booking_optiontemplate' => array(
        'classname' => 'mod_booking\external',
        'methodname' => 'optiontemplate',
        'description' => 'Read option template.',
        'type' => 'read',
        'ajax' => true
    ),
    'mod_booking_addbookingoption' => array( // Function will be added manually to service, only for admin use.
        'classname' => 'mod_booking\external',
        'methodname' => 'addbookingoption',
        'description' => 'Add Booking option',
        'type' => 'write',
        'ajax' => false
    ),
    // Bugfix #192 - This is actually not implemented in this version.
    'mod_booking_get_booking_option_description' => array(
        'classname' => 'mod_booking\external',
        'methodname' => 'get_booking_option_description',
        'description' => 'Bugfix #192 - This is actually not implemented in this version.',
        'type' => 'read',
        'ajax' => true
    )
);

$services = array(
    'Booking module API' => array( // Very importnant, don't rename or will broke local_bookingapi plugin!!!
        'functions' => array ('mod_booking_bookings', 'mod_booking_categories'),
        'restrictedusers' => 0,
        'enabled' => 1,
    )
);
