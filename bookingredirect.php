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
 * This is a very simple script which allows us to pass a base64 encoded URL
 * as param and redirect to the decoded URL.
 * This was needed because it wasn't possible to stop the Moodle calendar exporter
 * from escaping "&" to "&amp;" HTML entitities which made it impossible
 * to open links within outlook events.
 */
global $DB, $CFG, $COURSE, $USER, $OUTPUT, $PAGE;

require_once(__DIR__ . '/../../config.php');

$encodedurl = required_param('encodedurl', PARAM_TEXT); // The base64 encoded URL.
$link = base64_decode($encodedurl);

// Check if it's actually a link.
if (filter_var($link, FILTER_VALIDATE_URL)) {
    // Now open the link.
    header("Location: $link");
    exit();
}

echo "The URL does not seem to be valid. Please contact a developer.";