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
 * Hook callbacks for bookingextension_agent.
 *
 * Moodle scans db/hooks.php of every installed plugin — subplugins included —
 * which is what lets this booking subplugin act on global page generation
 * (navbar magic wand) without a separate local plugin. When the agent is
 * extracted into local_wizard, this registration moves there.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \bookingextension_agent\local\hooks\page_injection::class . '::extend_head',
    ],
    [
        // Publish the skill catalog as native MCP tools when tool_oauthmcp is present.
        // The hook is only ever dispatched by tool_oauthmcp, so the callback stays inert
        // (and its tool_oauthmcp type references stay unloaded) without that plugin.
        'hook' => \tool_oauthmcp\hook\collect_tool_providers::class,
        'callback' => \bookingextension_agent\hook\tool_provider_listener::class . '::collect',
    ],
];
