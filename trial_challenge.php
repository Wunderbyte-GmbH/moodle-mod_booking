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
 * The Wunderbyte server will call this URL with the nonce it received in the
 * trial request. We verify the nonce is in our short-lived MUC cache and then
 * echo it back as plain text. This proves to the Wunderbyte server that this
 * HTTP request really originates from the declared wwwroot domain.
 *
 * No Moodle session or login is required – this endpoint must be publicly
 * reachable.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

// Accept only GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die('Method Not Allowed');
}

$token = optional_param('token', '', PARAM_ALPHANUMEXT);

if (empty($token)) {
    http_response_code(400);
    die('Bad Request');
}

// Verify the nonce is in the short-lived cache created by request_trial_key.
$cache = cache::make('mod_booking', 'trialnonce');
$stored = $cache->get('nonce_' . $token);

if ($stored !== $token) {
    http_response_code(403);
    die('Forbidden');
}

// Echo the token as plain text – that is all the Wunderbyte server needs.
header('Content-Type: text/plain; charset=utf-8');
echo $token;
