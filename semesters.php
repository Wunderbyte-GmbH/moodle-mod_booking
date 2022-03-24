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
 * Semesters settings
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\form\dynamicsemestersform;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $OUTPUT;

// No guest autologin.
require_login(0, false);

admin_externalpage_setup('modbookingsemesters');

$settingsurl = new moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);

$pageurl = new moodle_url('/mod/booking/semesters.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('semesters', 'booking')
);

$form = new dynamicsemestersform($pageurl, null, 'post', '', [], true, ['arg1' => 'val1']);

// Set the form data with the same method that is called when loaded from JS.
// It should correctly set the data for the supplied arguments.
$form->set_data_for_dynamic_submission();

echo $OUTPUT->header();
echo $OUTPUT->heading(new lang_string('semesters', 'mod_booking'));

echo get_string('semesterssubtitle', 'booking');

// Render the form in a specific container, there should be nothing else in the same container.
echo html_writer::div($form->render(), '', ['id' => 'formcontainer']);

echo $OUTPUT->footer();
