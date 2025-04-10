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
 * Shortcodes for mod booking
 *
 * @package mod_booking
 * @subpackage db
 * @since Moodle 4.1
 * @copyright 2023 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$shortcodes = [
    'recommendedin' => [
        'callback' => 'mod_booking\shortcodes::recommendedin',
        'description' => 'recommendedin',
    ],
    'courselist' => [
        'callback' => 'mod_booking\shortcodes::courselist',
        'description' => 'courselist',
    ],
    'mycourselist' => [
        'callback' => 'mod_booking\shortcodes::mycourselist',
        'description' => 'mycourselist',
    ],
    'allbookingoptions' => [
        'callback' => 'mod_booking\shortcodes::allbookingoptions',
        'description' => 'bookingoptionsall',
    ],
    'fieldofstudyoptions' => [
        'callback' => 'mod_booking\shortcodes::fieldofstudyoptions',
        'description' => 'fieldofstudyoptions',
    ],
    'fieldofstudycohortoptions' => [
        'callback' => 'mod_booking\shortcodes::fieldofstudycohortoptions',
        'description' => 'fieldofstudycohortoptions',
    ],
    'bulkoperations' => [
        'callback' => 'mod_booking\shortcodes::bulkoperations',
        'description' => 'bulkoperations',
    ],
    'linkbacktocourse' => [
        'callback' => 'mod_booking\shortcodes::linkbacktocourse',
        'description' => 'linkbacktocourse',
    ],
];
