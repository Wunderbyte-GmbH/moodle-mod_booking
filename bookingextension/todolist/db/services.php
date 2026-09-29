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
 * Web services for bookingextension_todolist.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'bookingextension_todolist_toggle_todolist_item' => [
        'classname' => 'bookingextension_todolist\\external\\toggle_todolist_item',
        'methodname' => 'execute',
        'description' => 'Toggle a todo list item and return refreshed rendering context.',
        'type' => 'write',
        'capabilities' => 'bookingextension/todolist:checktodolist',
        'ajax' => 1,
    ],
];

$services = [];
