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
 * Web service for bookingextension_agent AI functions
 *
 * @package bookingextension_agent
 * @subpackage db
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'bookingextension_agent_ai_send_message' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_send_message',
        'methodname'  => 'execute',
        'description' => 'Send a user message to the AI booking agent and receive its response.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_privacy_precheck' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_privacy_precheck',
        'methodname'  => 'execute',
        'description' => 'Run privacy anonymization precheck on user text before forwarding to AI.',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_confirm_run' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_confirm_run',
        'methodname'  => 'execute',
        'description' => 'Confirm a proposed AI run and enqueue asynchronous execution.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_discard_pending' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_discard_pending',
        'methodname'  => 'execute',
        'description' => 'Discard the current pending confirmation and skip its queue items.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_poll_thread' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_poll_thread',
        'methodname'  => 'execute',
        'description' => 'Return all messages in an AI conversation thread.',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_get_thread_debug_logs' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_get_thread_debug_logs',
        'methodname'  => 'execute',
        'description' => 'Fetch raw LLM debug logs for a conversation thread (debug mode only).',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_get_doc_content' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_get_doc_content',
        'methodname'  => 'execute',
        'description' => 'Load a booking/docs markdown file and return it as rendered HTML for the AI preview pane.',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_request_trial_key' => [
        'classname'   => '\\bookingextension_agent\\external\\request_trial_key',
        'methodname'  => 'execute',
        'description' => 'Create a short-lived trial challenge nonce and return trial onboarding status.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_activate_trial_context' => [
        'classname'   => '\\bookingextension_agent\\external\\activate_trial_context',
        'methodname'  => 'execute',
        'description' => 'Enable AI tools for this course and booking module after trial onboarding.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_configure_provider_from_existing' => [
        'classname'   => '\\bookingextension_agent\\external\\configure_provider_from_existing',
        'methodname'  => 'execute',
        'description' => 'Configure the Wunderbyte provider from an existing third-party provider\'s credentials.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_upload_attachment' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_upload_attachment',
        'methodname'  => 'execute',
        'description' => 'Upload a file attachment (image or PDF) for use in an AI agent conversation.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_store_provider_apikey' => [
        'classname'   => '\\bookingextension_agent\\external\\store_provider_apikey',
        'methodname'  => 'execute',
        'description' => 'Store a purchased Wunderbyte API key on the provider instance.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:requesttrial',
        'ajax'        => 1,
    ],
    'bookingextension_agent_set_debug_mode' => [
        'classname'   => '\\bookingextension_agent\\external\\set_debug_mode',
        'methodname'  => 'execute',
        'description' => 'Toggle the site-wide agent debug mode.',
        'type'        => 'write',
        'capabilities' => 'moodle/site:config',
        'ajax'        => 1,
    ],
];

// No plugin-defined web service.
//
// The functions above are the agent's own in-page plumbing (chat, polling, trial
// onboarding). They are invoked from the browser through core/ajax, which resolves
// each function by name via external_api::call_external_function() and does not
// require service membership -- so they need no named service to work.
//
// External MCP clients (Claude) reach the agent's skills through the
// collect_tool_providers hook (mcp_hook_tool_provider), which is independent of any
// web service. The single service used to publish additional Moodle functions as MCP
// tools is the dedicated "MCP server (tool_oauthmcp)" service, managed in that plugin's
// settings. The former fixed "Booking AI Agent" / "Booking AI Agent MCP" services are
// therefore no longer declared; external_update_descriptions() removes the stale rows
// on upgrade.
$services = [];
