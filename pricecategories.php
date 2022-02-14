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

if ($mform->is_cancelled()) {
    // If cancelled, go back to general booking settings.
    redirect($settingsurl);

} else if ($data = $mform->get_data()) {

    $existingpricecategories = $DB->get_records('booking_pricecategories');

    if (empty($existingpricecategories)) {
        // There are no price categories yet.
        // Currently there can be up to nine price categories.
        for ($i = 1; $i <= MAX_PRICE_CATEGORIES; $i++) {

            $pricecategoryordernumx = 'pricecategoryordernum' . $i;
            $pricecategoryidentifierx = 'pricecategoryidentifier' . $i;
            $pricecategorynamex = 'pricecategoryname' . $i;
            $defaultvaluex = 'defaultvalue' . $i;
            $disablepricecategoryx = 'disablepricecategory' . $i;

            // Only add price categories if a name was entered.
            if (!empty($data->{$pricecategoryidentifierx})) {
                $pricecategory = new stdClass();
                $pricecategory->ordernum = $data->{$pricecategoryordernumx};
                $pricecategory->identifier = $data->{$pricecategoryidentifierx};
                $pricecategory->name = $data->{$pricecategorynamex};
                $pricecategory->defaultvalue = $data->{$defaultvaluex};
                $pricecategory->disabled = $data->{$disablepricecategoryx};

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
            if (count($pricecategorychanges['inserts']) > 0) {
                $DB->insert_records('booking_pricecategories', $pricecategorychanges['inserts']);
            }
        }
    }

    // In any case, invalidate the cache after updating price categories.
    cache_helper::purge_by_event('setbackpricecategories');

    redirect($pageurl, get_string('pricecategoriessaved', 'booking'), 5);

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

    $existingordernumbers = [];

    foreach ($oldpricecategories as $oldpricecategory) {
        $existingordernumbers[] = $oldpricecategory->ordernum;
    }

    foreach ($data as $key => $value) {
        if (preg_match('/pricecategoryid[0-9]/', $key)) {
            $counter = (int)substr($key, -1);

            if (in_array($counter, $existingordernumbers)) {

                // Create price category object and add to updates.
                $pricecategory = new stdClass();
                $pricecategory->id = $value;
                $pricecategory->ordernum = $data->{'pricecategoryordernum' . $counter};
                $pricecategory->identifier = $data->{'pricecategoryidentifier' . $counter};
                $pricecategory->name = $data->{'pricecategoryname' . $counter};
                $pricecategory->defaultvalue = $data->{'defaultvalue' . $counter};
                $pricecategory->disabled = $data->{'disablepricecategory' . $counter};

                $updates[] = $pricecategory;

            } else {
                // Create new price category and add to inserts.
                if (!empty($data->{'pricecategoryidentifier' . $counter})) {
                    $pricecategory = new stdClass();
                    $pricecategory->ordernum = $data->{'pricecategoryordernum' . $counter};
                    $pricecategory->identifier = $data->{'pricecategoryidentifier' . $counter};
                    $pricecategory->name = $data->{'pricecategoryname' . $counter};
                    $pricecategory->defaultvalue = $data->{'defaultvalue' . $counter};
                    $pricecategory->disabled = $data->{'disablepricecategory' . $counter};

                    $inserts[] = $pricecategory;
                }
            }
        }
    }

    return [
            'inserts' => $inserts,
            'updates' => $updates
    ];
}
