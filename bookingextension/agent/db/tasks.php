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
 * Scheduled task definitions for bookingextension_agent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\bookingextension_agent\task\cleanup_attachment_temp_files_adhoc',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\bookingextension_agent\task\cleanup_old_benchmark_runs_task',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '3',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\bookingextension_agent\task\cleanup_old_llm_debug_task',
        'blocking'  => 0,
        'minute'    => '45',
        'hour'      => '3',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\bookingextension_agent\task\cleanup_mcp_session_threads_task',
        'blocking'  => 0,
        'minute'    => '50',
        'hour'      => '3',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        // Incremental site-content index update (per-area cursor; prunes disabled areas).
        // Self-guards on the DB backend + provider, so it is a no-op while site search is off.
        'classname' => '\bookingextension_agent\task\rebuild_site_content_embeddings',
        'blocking'  => 0,
        'minute'    => '20',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
