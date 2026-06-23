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
 * Language configuration for the German gender-fair variant 'de_gs' (Deutsch mit Genderslash).
 *
 * Child language of 'de': only strings that differ are overridden (mod_booking ships the
 * slash-spelled strings in mod/booking/lang/de_gs/booking.php); everything else is inherited.
 *
 * OPTIONAL / MANUAL ACTIVATION:
 * The variant is NOT enabled automatically. To make it selectable on a site, a site administrator
 * has to copy this file to:
 *     <moodledata>/lang/de_gs/langconfig.php
 * Moodle only lists a language when its langconfig.php exists under moodledata; plugin lang
 * folders are not scanned for the language list. After copying, purge caches and add the
 * language under Site administration > Language > Language packs / Language settings.
 * Works the same on Moodle 4.5 and 5.x.
 *
 * @package     mod_booking
 * @category    string
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['parentlanguage'] = 'de';
$string['thislanguage'] = 'Deutsch mit Genderslash';
$string['thislanguageint'] = 'German (gender-slash)';
