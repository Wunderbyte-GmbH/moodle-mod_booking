<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Cache definitions for bookingextension_agent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$definitions = [
    'aiprivacynames' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 5,
        'ttl' => 900,
    ],
    'trialnonce' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => false,
        'ttl' => 600,
    ],
    'attachment_tokens' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => false,
        'ttl' => 1800,
    ],
    // Per-user MCP tool-call counters (mcp_execution_service rate limit), keyed by
    // user + minute window. Short TTL — entries are only relevant within their window.
    'mcpratelimit' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => false,
        'ttl' => 120,
    ],
    // Site-search governance effort estimates (index_scope_estimator, blueprint §5b.4), keyed by
    // area + scope (+ red threshold, since the counting abort depends on it). Keys carry '|' and
    // '-', so no simplekeys.
    'sitesearchestimates' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false,
        'staticacceleration' => false,
        'ttl' => 600,
    ],
];
