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

/* List of handlers */
$handlers = array (
    'booking_confirmed' => array (
        'handlerfile'      => '/mod/booking/lib.php',
        'handlerfunction'  => 'booking_booking_confirmed',
        'schedule'         => 'cron',
        'internal'         => 1,
    ),
    'booking_deleted' => array (
    		'handlerfile'      => '/mod/booking/lib.php',
    		'handlerfunction'  => 'booking_booking_deleted',
    		'schedule'         => 'cron',
    		'internal'         => 1,
    ),
);
