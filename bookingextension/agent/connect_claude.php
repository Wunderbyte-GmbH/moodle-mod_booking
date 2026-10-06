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
 * "Connect with Claude" readiness page: checks every prerequisite for connecting an external AI
 * client to this site over MCP + OAuth 2.1 (provided by tool_oauthmcp) and explains how to finish.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../../config.php');

$context = context_system::instance();

require_login();
require_capability('bookingextension/agent:mcpaccess', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/connect_claude.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('claudeconnect_title', 'bookingextension_agent'));
$PAGE->set_heading(get_string('claudeconnect_title', 'bookingextension_agent'));

$readiness = new \bookingextension_agent\local\wizard\claude_connect_readiness((int)$USER->id);
$templatecontext = $readiness->get_report();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('bookingextension_agent/connect_claude', $templatecontext);
echo $OUTPUT->footer();
