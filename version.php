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
 * Module Booking.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>,
 * @author David Bogner, Georg Maißer, Bernhard Fischer, Magdalena Holczik, Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version = 2025080500;
$plugin->requires = 2022112800; // Requires this Moodle version. Current: Moodle 4.1.
$plugin->release = '8.15.1';
$plugin->maturity = MATURITY_STABLE;
$plugin->component = 'mod_booking';
$plugin->supported = [401, 405];
$plugin->dependencies = [
    'local_wunderbyte_table' => 2025060600,
];
