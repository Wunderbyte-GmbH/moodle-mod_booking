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
 * Meta course enrolment plugin event handler definition.
 *
 * @package mod_booking
 * @category event
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\local_wunderbyte_table\event\template_switched',
        'callback' => 'mod_booking_observer::template_switched',
    ],
    [
        'eventname' => '\core\event\user_created',
        'callback' => 'mod_booking_observer::user_created',
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback' => 'mod_booking_observer::user_updated',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => 'mod_booking_observer::user_deleted',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => 'mod_booking_observer::user_enrolment_deleted',
    ],
    [
        'eventname' => '\mod_booking\event\bookingoption_created',
        'callback' => 'mod_booking_observer::bookingoption_created',
    ],
    [
        'eventname' => '\mod_booking\event\bookingoption_updated',
        'callback' => 'mod_booking_observer::bookingoption_updated',
    ],
    [
        'eventname' => '\mod_booking\event\bookingoption_cancelled',
        'callback' => 'mod_booking_observer::bookingoption_cancelled',
    ],
    [
        'eventname' => '\mod_booking\event\bookingoptiondate_created',
        'callback' => 'mod_booking_observer::bookingoptiondate_created',
    ],
    [
        'eventname' => '\mod_booking\event\bookingoptiondate_deleted',
        'callback' => 'mod_booking_observer::bookingoptiondate_deleted',
    ],
    [
        'eventname' => '\mod_booking\event\custom_field_changed',
        'callback' => 'mod_booking_observer::custom_field_changed',
    ],
    [
        'eventname' => '\mod_booking\event\bookinganswer_cancelled',
        'callback' => 'mod_booking_observer::bookinganswer_cancelled',
    ],
    [
        'eventname' => '\mod_booking\event\bookingoption_completed',
        'callback' => 'mod_booking_observer::bookingoption_completed',
    ],
    [
        'eventname' => '\mod_booking\event\pricecategory_changed',
        'callback' => 'mod_booking_observer::pricecategory_changed',
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => 'mod_booking_observer::course_completed',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => 'mod_booking_observer::course_module_updated',
    ],
    [
        'eventname' => '\core\event\group_member_added',
        'callback' => 'mod_booking_observer::group_membership_changed',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => 'mod_booking_observer::group_membership_changed',
    ],
    [
        'eventname' => '*',
        'callback' => 'mod_booking_observer::execute_rule',
    ],
    [
        'eventname' => '\mod_booking\event\bookinganswer_presencechanged',
        'callback' => 'mod_booking_observer::bookinganswer_presencechanged',
    ],
    [
        'eventname' => '\mod_booking\event\bookinganswer_notesedited',
        'callback' => 'mod_booking_observer::bookinganswer_notesedited',
    ],
    [
        'eventname' => '\local_shopping_cart\event\item_added',
        'callback' => 'mod_booking_observer::shoppingcart_item_added',
    ],
];
