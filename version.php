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
 *
 * @package mod_booking
 * @copyright 2012-2021 David Bogner <info@wunderbyte.at>, Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$plugin->version = 2021071100;
$plugin->requires = 2019111800; // Requires this Moodle version. Current: Moodle 3.8.
// Famous female characters: Diane Selwyn, Eva Thörnblad, Alex Kirkman, Piper Chapman.
// Lois Wilkerson, Audrey Horne, Lorelai Gilmore, Nairobi (Casa de Papel), Saga Norén.
$plugin->release = 'v6.0-Norén';
$plugin->maturity = MATURITY_STABLE;
$plugin->cron = 60;
$plugin->component = 'mod_booking';
