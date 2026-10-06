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
 * Public back-channel challenge endpoint for Wunderbyte trial key verification.
 *
 * @package bookingextension_agent
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Pure machine-to-machine callback: the trial service fetches this URL and expects the
// nonce echoed back, without following redirects. No session is needed (the nonce lives
// in the application cache), and hooks that redirect anonymous visitors (e.g. the
// shopping_cart guest-checkout auto-login) skip cookie-less scripts - so declaring
// NO_MOODLE_COOKIES keeps this endpoint redirect-free under any site configuration.
define('NO_MOODLE_COOKIES', true);

// phpcs:disable moodle.Files.RequireLogin.Missing
require_once(__DIR__ . '/../../../../config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die('Method Not Allowed');
}

$token = optional_param('token', '', PARAM_ALPHANUMEXT);
if ($token === '') {
    http_response_code(400);
    die('Bad Request');
}

$cache = cache::make('bookingextension_agent', 'trialnonce');
$stored = $cache->get('nonce_' . $token);

if ($stored !== $token) {
    http_response_code(403);
    die('Forbidden');
}

// Single-use: the trial service performs exactly one back-channel challenge per nonce, so
// consume it immediately on the first valid echo. This closes the replay window (the nonce
// otherwise stayed valid for its full cache TTL and could be echoed repeatedly).
$cache->delete('nonce_' . $token);

header('Content-Type: text/plain; charset=utf-8');
echo $token;
