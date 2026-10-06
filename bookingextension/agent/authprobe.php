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
 * Webserver diagnostics probe: does an Authorization header survive the webserver?
 *
 * Apache with PHP-FPM strips the Authorization header by default, which silently breaks
 * every Bearer-authenticated MCP request (token AND OAuth) with an opaque auth challenge.
 * The Connect-with-Claude readiness page calls this endpoint server-side with a dummy
 * Authorization header and shows a red check with the fix when it does not arrive.
 *
 * Deliberately session-free and side-effect-free: it reports ONLY whether a header was
 * received (never its value), so it needs no login — there is nothing here to protect.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing -- public, read-only diagnostics probe (see docblock).

define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../../../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(['authheader' => !empty($_SERVER['HTTP_AUTHORIZATION'])]);
