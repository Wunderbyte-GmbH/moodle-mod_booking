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
 * Price categories settings
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\customfield\booking_pricecategories_handler;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// No guest autologin.
require_login(0, false);

admin_externalpage_setup('modbookingpricecategories');

$pageurl = new moodle_url('/mod/booking/pricecategories.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
        format_string($SITE->shortname) . ': ' . get_string('pricecategories', 'booking'));

// TODO: mform

echo $output->header(),
        $output->heading(new lang_string('pricecategory', 'mod_booking')),
        $output->render($outputpage),
        $output->footer();
