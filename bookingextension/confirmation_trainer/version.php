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
 * This file contains the version information for the
 * bookingextension_confirmation_trainer plugin.
 *
 * @package     bookingextension_confirmation_trainer
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer, Mahdi Poustini
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026020300;
$plugin->requires = 2024100700; // Requires this Moodle version. Current: Moodle 4.5.
$plugin->component = 'bookingextension_confirmation_trainer';
$plugin->supported = [405, 501];
$plugin->dependencies = [
    'mod_booking' => 2026020300,
];
