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
 * Viewer for the documentation shipped in the docs/ folder of the plugin.
 *
 * Renders the markdown pages and serves the images and example files
 * referenced by them. Navigation works through the links of the pages
 * themselves, which are rewritten to viewer URLs.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\local\documentation\documentation_viewer;

require_once('../../config.php');

global $OUTPUT, $PAGE;

$file = optional_param('file', 'README.md', PARAM_PATH);

// No guest autologin.
require_login(0, false);

$context = context_system::instance();
require_capability('mod/booking:viewdocumentation', $context);

$resolved = documentation_viewer::resolve($file);

// Images and example files referenced by the documentation pages.
if ($resolved['type'] !== documentation_viewer::TYPE_MARKDOWN) {
    send_file(
        $resolved['path'],
        basename($resolved['path']),
        null,
        0,
        false,
        $resolved['type'] === documentation_viewer::TYPE_DOWNLOAD
    );
    // Not reached, send_file ends the request.
}

$page = documentation_viewer::render_page($file);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/booking/documentation.php', ['file' => $file]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('documentation', 'mod_booking') . ': ' . $page['title']);
$PAGE->set_heading(get_string('documentation', 'mod_booking'));

echo $OUTPUT->header();
echo html_writer::div($page['html'], 'mod_booking-documentation');
echo $OUTPUT->footer();
