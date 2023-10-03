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

$functions = [
    'mod_booking_enrol_user' => [
        'classname' => 'mod_booking\external\enrol_user',
        'description' => 'Enrol user via AJAX',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE, 'local_mobile'],
    ],
    'mod_booking_unenrol_user' => [
        'classname' => 'mod_booking\external\unenrol_user',
        'description' => 'Unenrol user via AJAX',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE, 'local_mobile'],
    ],
    'mod_booking_update_bookingnotes' => [
        'classname' => 'mod_booking\external\update_bookingnotes',
        'description' => 'Update the booking notes via AJAX',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/booking:readresponses',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE, 'local_mobile'],
    ],
    'mod_booking_addbookingoption' => [ // Function will be added manually to service, only for admin use.
        'classname' => 'mod_booking\external\addbookingoption',
        'description' => 'Add Booking option',
        'type' => 'write',
        'capabilities' => '',
        'ajax' => false,
    ],
    'mod_booking_categories' => [
        'classname' => 'mod_booking\external\categories',
        'description' => 'Return categories for course id.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],
    'mod_booking_bookings' => [
        'classname' => 'mod_booking\external\bookings',
        'description' => 'Return bookings for course id.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],
    'mod_booking_instancetemplate' => [
        'classname' => 'mod_booking\external\instancetemplate',
        'description' => 'Read booking instance template.',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => true,
    ],
    'mod_booking_optiontemplate' => [
        'classname' => 'mod_booking\external\optiontemplate',
        'description' => 'Read option templatee.',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => true,
    ],
    'mod_booking_get_booking_option_description' => [
        'classname' => 'mod_booking\external\get_booking_option_description',
        'description' => 'Get booking option decription for a special option and user',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => true,
    ],
    'mod_booking_toggle_notify_user' => [
        'classname' => 'mod_booking\external\toggle_notify_user',
        'description' => 'Puts user on and off the notification list',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => true,
    ],
    'mod_booking_load_pre_booking_page' => [
        'classname' => 'mod_booking\external\load_pre_booking_page',
        'description' => 'Loads the injected pre booking page from the right bo_condition',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => true,
    ],
    'mod_booking_bookit' => [
        'classname' => 'mod_booking\external\bookit',
        'description' => 'Book option or suboption via ajax',
        'type' => 'write',
        'capabilities' => '',
        'ajax' => true,
    ],
    'mod_booking_init_comments' => [
        'classname' => 'mod_booking\external\init_comments',
        'description' => 'Init commenting',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => true,
    ],
    'mod_booking_search_booking_options' => [
        'classname' => 'mod_booking\external\search_booking_options',
        'description' => 'Search a list of all booking options',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => 1,
    ],
    'mod_booking_search_users' => [
            'classname' => 'mod_booking\external\search_users',
            'description' => 'Search a list of all users',
            'type' => 'read',
            'capabilities' => '',
            'ajax' => 1,
    ],
    'mod_booking_search_teachers' => [
        'classname' => 'mod_booking\external\search_teachers',
        'description' => 'Search a list of booking teachers',
        'type' => 'read',
        'capabilities' => '',
        'ajax' => 1,
    ],
];

$services = [
    'Booking module API' => [ // Very important, don't rename or will break local_bookingapi plugin!!!
        'functions' => ['mod_booking_bookings', 'mod_booking_categories'],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];
