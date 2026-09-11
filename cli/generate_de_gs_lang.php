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
 * Regenerates lang/de_gs/booking.php from lang/de/booking.php (gender slash spelling).
 *
 * Run this whenever the German strings change:
 *   php mod/booking/cli/generate_de_gs_lang.php
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$target = $CFG->dirroot . '/mod/booking/lang/de_gs/booking.php';
$content = \mod_booking\local\genderslash::render_lang_file();

if (file_put_contents($target, $content) === false) {
    cli_error('Could not write ' . $target);
}

$count = substr_count($content, "\n\$string[");
cli_writeln("Regenerated {$target} ({$count} strings).");
