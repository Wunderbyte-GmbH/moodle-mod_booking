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

use mod_booking\form\optionformconfig_form;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $OUTPUT;

// No guest autologin.
require_login(0, false);

admin_externalpage_setup('modbookingoptionformconfig');

$settingsurl = new moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);

$pageurl = new moodle_url('/mod/booking/optionformconfig.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('optionformconfig', 'mod_booking')
);

// This is the actual configuration form.
$mform = new optionformconfig_form($pageurl);

if ($mform->is_cancelled()) {
    // If cancelled, go back to general booking settings.
    redirect($settingsurl);

} else if ($data = $mform->get_data()) {

    // TODO: Insert, delete or update data in DB.
    foreach ($data as $key => $value) {

        // Remove 'cfg_' part of the key.
        $key = str_replace('cfg_', '', $key);

        // Do not write empty keys into DB.
        if (empty($key)) {
            continue;
        }

        // Do not add special elements like "submitbutton".
        if (!is_int($value)) {
            continue;
        }

        $el = new stdClass;
        $el->elementname = $key;
        $el->active = $value;

        if ($dbrecord = $DB->get_record('booking_optionformconfig', ['elementname' => $key])) {
            // Record exists: Update.
            $el->id = $dbrecord->id;
            $DB->update_record('booking_optionformconfig', $el);
        } else {
            // New record: Insert.
            $DB->insert_record('booking_optionformconfig', $el);
        }
    }

    redirect($pageurl, get_string('optionformconfigsaved', 'mod_booking'), 5);

} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(new lang_string('optionformconfig', 'mod_booking'));

    // Dismissible alert.
    echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">' .
    get_string('optionformconfigsubtitle', 'mod_booking') .
    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">&times;</span>
    </button>
    </div>';

    if (!$firstinstancefound = $DB->get_records('booking', null, '', '*', 0, 1)) {
        echo html_writer::div(get_string('optionformconfig:nobooking', 'mod_booking'), 'alert alert-danger');
    } else {
        $mform->display();
    }

    echo $OUTPUT->footer();
}
