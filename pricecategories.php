<?php

use mod_booking\pricecategory_handler;
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

 require_once(__DIR__ . '/../../config.php');
 require_once($CFG->libdir . '/adminlib.php');
 require_once(__DIR__ . '/classes/local/pricecategory_handler.php');


 global $OUTPUT, $PAGE, $USER;

 // Sicherstellen, dass der Nutzer angemeldet ist.
 require_login();
 admin_externalpage_setup('modbookingpricecategories');

 // URLs definieren.
 $pageurl = new moodle_url('/mod/booking/pricecategories.php');
 $settingsurl = new moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);

 // Seite konfigurieren.
 $PAGE->set_url($pageurl);
 $PAGE->set_title(get_string('pricecategories', 'mod_booking'));
 $PAGE->set_heading(get_string('pricecategory', 'mod_booking'));

 // Handler initialisieren.
 $handler = new pricecategory_handler();

 // Formularverarbeitung.
if (($data = data_submitted()) && confirm_sesskey()) {
    $handler->process_pricecategories_form($data);
    cache_helper::purge_by_event('setbackpricecategories');
    redirect($pageurl, get_string('pricecategoriessaved', 'booking'), 5);
}

 // Seite ausgeben.
 echo $OUTPUT->header();
 echo $OUTPUT->heading(get_string('pricecategory', 'mod_booking'));
 echo get_string('pricecategoriessubtitle', 'mod_booking');

 $handler->display_form($pageurl); // Die Methode `display_form` muss im Handler existieren.

 echo $OUTPUT->footer();
