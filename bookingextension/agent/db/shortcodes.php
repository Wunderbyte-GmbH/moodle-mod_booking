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
 * Shortcode definitions for bookingextension_agent.
 *
 * Moodle's shortcode filter (filter_shortcodes) reads this file to discover
 * which shortcode tags the plugin provides and which class/method handles each.
 * Lets the AI agent be embedded inline anywhere (a label, page or text field)
 * instead of only via the navbar magic wand.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$shortcodes = [
    'wbbagent' => [
        'callback' => 'bookingextension_agent\shortcodes::wbbagent',
        'description' => 'aiinstructions_shortcode',
    ],
];
