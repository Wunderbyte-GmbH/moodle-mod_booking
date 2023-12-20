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
 * Booking module scheduled tasks definition
 *
 * @package    mod_booking
 * @copyright  2009-2023 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    ['classname' => 'mod_booking\task\remove_activity_completion',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    ['classname' => 'mod_booking\task\enrol_bookedusers_tocourse',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    ['classname' => 'mod_booking\task\send_reminder_mails',
        'blocking' => 0,
        'minute' => '7',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    ['classname' => 'mod_booking\task\send_notification_mails',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '7',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    // Clean the DB every sunday at 03:42 (the task removes unncessary artifacts).
    ['classname' => 'mod_booking\task\clean_booking_db',
        'blocking' => 0,
        'minute' => '42',
        'hour' => '3',
        'day' => '*',
        'dayofweek' => '0',
        'month' => '*',
    ],
];
