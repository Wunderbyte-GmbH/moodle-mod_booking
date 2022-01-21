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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $OUTPUT;

// No guest autologin.
require_login(0, false);

admin_externalpage_setup('modbookingpricecategories');

$settingsurl = new moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);

$pageurl = new moodle_url('/mod/booking/pricecategories.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('pricecategories', 'booking')
);

$mform = new pricecategories_form($pageurl);

if ($data = $mform->get_data()) {

    $existingpricecategories = $DB->get_records('booking_pricecategories');

    if (empty($existingpricecategories)) {
        // There are no price categories yet.
        // Currently there can be up to nine price categories.
        for ($i = 1; $i <= MAX_PRICE_CATEGORIES; $i++) {

            $pricecategorynamex = 'pricecategoryname' . $i;
            $pricecategorydescriptionx = 'pricecategorydescription' . $i;

            // Only add price categories if a name was entered.
            if (!empty($data->{$pricecategorynamex})) {
                $pricecategory = new stdClass();
                $pricecategory->pricecategory = $data->{$pricecategorynamex};
                $pricecategory->description = $data->{$pricecategorydescriptionx};

                $DB->insert_record('booking_pricecategories', $pricecategory);
            }
        }
    } else {

        // There are already existing price categories.
        // So we need to check for changes.
        $oldpricecategories = $DB->get_records('booking_pricecategories');

        if ($pricecategorychanges = pricecategories_get_changes($oldpricecategories, $data)) {
            foreach ($pricecategorychanges['updates'] as $record) {
                $DB->update_record('booking_pricecategories', $record);
            }
            foreach ($pricecategorychanges['deletes'] as $record) {
                $DB->delete_records('booking_pricecategories', ['id' => $record]);
            }
            if (count($pricecategorychanges['inserts']) > 0) {
                $DB->insert_records('booking_pricecategories', $pricecategorychanges['inserts']);
            }
        }
    }
    // After saving, go back to booking settings.
    redirect($settingsurl, get_string('pricecategoriessaved', 'booking'), 5);

} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(new lang_string('pricecategory', 'mod_booking'));

    echo get_string('pricecategoriessubtitle', 'booking');

    // Show the mform.
    $mform->display();

    echo $OUTPUT->footer();
}

/**
 * Helper function to return arrays containing all relevant pricecategories update changes.
 * The returned arrays will have the prepared stdClasses for update and insert in booking_pricecategories table.
 *
 * @param $oldpricecategories the existing price categories
 * @param $data the form data
 * @return array
 */
function pricecategories_get_changes($oldpricecategories, $data) {

    $updates = [];
    $inserts = [];
    $deletes = [];

    foreach ($data as $key => $value) {
        if (strpos($key, 'pricecategoryid') !== false) {

            $counter = (int)substr($key, -1);

            // First check if the field existed before.
            if ($value != 0 && $oldcategory = $oldpricecategories[$value]) {

                // If the delete checkbox has been set, add to deletes.
                if (isset($data->{'deletepricecategory' . $counter})
                    && $data->{'deletepricecategory' . $counter} == 1) {

                    $deletes[] = $value; // The ID of the price category that needs to be deleted.
                    continue;
                }

                $haschange = false;

                // Check if the name of the price category has changed.
                if ($oldcategory->pricecategory != $data->{'pricecategoryname' . $counter}) {

                    $haschange = true;

                }

                // Check if the description of the price category has changed.
                if (!empty($data->{'pricecategorydescription' . $counter}) &&
                    $oldcategory->description != $data->{'pricecategorydescription' . $counter}) {

                    $haschange = true;

                }

                if ($haschange) {

                    // Create price category object and add to updates.
                    $pricecategory = new stdClass();
                    $pricecategory->id = $value;
                    $pricecategory->pricecategory = $data->{'pricecategoryname' . $counter};
                    $pricecategory->description = $data->{'pricecategorydescription' . $counter};

                    $updates[] = $pricecategory;
                }
            } else {
                // Create new price category and add to inserts.
                if (!empty($data->{'pricecategoryname' . $counter})) {
                    $pricecategory = new stdClass();
                    $pricecategory->pricecategory = $data->{'pricecategoryname' . $counter};
                    $pricecategory->description = $data->{'pricecategorydescription' . $counter};

                    $inserts[] = $pricecategory;
                }
            }
        }
    }

    return [
            'inserts' => $inserts,
            'updates' => $updates,
            'deletes' => $deletes
    ];
}

