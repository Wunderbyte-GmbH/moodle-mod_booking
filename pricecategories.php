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
 use mod_booking\form\pricecategories_form;
 use mod_booking\output\pricecategories;
 use mod_booking\local\pricecategories_handler;
 require_once(__DIR__ . '/../../config.php');
 require_once($CFG->libdir . '/adminlib.php');
 require_once(__DIR__ . '/classes/local/pricecategories_handler.php');


 global $OUTPUT, $PAGE, $USER;

 // Sicherstellen, dass der Nutzer angemeldet ist.
// No guest autologin.
require_login(0, false);
admin_externalpage_setup('modbookingpricecategories');

// URLs definieren.
$pageurl = new moodle_url('/mod/booking/pricecategories.php');
$settingsurl = new moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);

// Seite konfigurieren.
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('pricecategories', 'mod_booking'));
$PAGE->set_heading(get_string('pricecategory', 'mod_booking'));

// Handler initialisieren.
$handler = new pricecategories_handler();

$mform = new pricecategories_form();
$mform->set_data_for_dynamic_submission();

$PAGE->requires->js_call_amd(
    'mod_booking/dynamicpricecategoriesform',
    'init',
    ['[data-region=pricecategoriesformcontainer]', pricecategories_form::class]
);

// Seite ausgeben.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pricecategory', 'mod_booking'));
echo get_string('pricecategoriessubtitle', 'mod_booking');

$output = $PAGE->get_renderer('mod_booking');
$data = new pricecategories($mform->render());
echo $output->render_pricecategories($data);

echo $OUTPUT->footer();
