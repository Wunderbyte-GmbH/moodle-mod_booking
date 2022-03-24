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

use mod_booking\form\semesters_form;

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

$mform = new semesters_form($pageurl);

if ($mform->is_cancelled()) {
    // If cancelled, go back to general booking settings.
    redirect($settingsurl);

} else if ($data = $mform->get_data()) {

    $existingsemesters = $DB->get_records('booking_semesters');

    if (empty($existingsemesters)) {
        // There are no semesters yet.
        // There can be up to MAX_SEMESTERS semesters.
        for ($i = 1; $i <= MAX_SEMESTERS; $i++) {

            // Use 2 digits, so we can have more than 9 semesters.
            $j = sprintf('%02d', $i);

            $semesteridentifierx = 'semesteridentifier' . $j;
            $semesternamex = 'semestername' . $j;
            $semesterstartx = 'semesterstart' . $j;
            $semesterendx = 'semesterend' . $j;

            // Only add semesters if an identifier was entered.
            if (!empty($data->{$semesteridentifierx})) {
                $semester = new stdClass();
                $semester->identifier = $data->{$semesteridentifierx};
                $semester->name = $data->{$semesternamex};
                $semester->start = $data->{$semesterstartx};
                $semester->end = $data->{$semesterendx};

                $DB->insert_record('booking_semesters', $semester);
            }
        }
    } else {
        if ($semesterchanges = semesters_get_changes($data)) {
            foreach ($semesterchanges['updates'] as $record) {
                $DB->update_record('booking_semesters', $record);

                // Invalidate semester cache on update.
                cache_helper::invalidate_by_event('setbacksemesters', [$record->id]);
            }
            foreach ($semesterchanges['deletes'] as $recordid) {
                $DB->delete_records('booking_semesters', ['id' => $recordid]);

                // Invalidate semester cache on delete.
                cache_helper::invalidate_by_event('setbacksemesters', [$recordid]);
            }
            if (count($semesterchanges['inserts']) > 0) {
                $DB->insert_records('booking_semesters', $semesterchanges['inserts']);
            }
        }
    }

    redirect($pageurl, get_string('semesterssaved', 'booking'), 5);
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(new lang_string('semesters', 'mod_booking'));

    echo get_string('semesterssubtitle', 'booking');

    // Show the mform.
    $mform->display();

    echo $OUTPUT->footer();
}

/**
 * Helper function to return arrays containing all relevant semesters update changes.
 * The returned arrays will have the prepared stdClasses for update and insert in the
 * booking_semesters table.
 *
 * @param $oldsemesters the existing semesters
 * @param $data the form data
 * @return array
 */
function semesters_get_changes($data) {

    $updates = [];
    $inserts = [];
    $deletes = [];

    foreach ($data as $key => $value) {
        if (preg_match('/semesterid[0-9]{2}/', $key)) {
            $j = substr($key, -2, 2); // Get the 2 digit counter as string.

            // First check if the field existed before.
            if ($value != 0) {

                // If the delete checkbox has been set, add to deletes.
                if ($data->{'deletesemester' . $j} == 1) {
                    $deletes[] = $value; // The ID of the semester that needs to be deleted.
                    continue;
                }

                // Create semester object and add to updates.
                $semester = new stdClass();
                $semester->id = $value; // For update, ID is needed.
                $semester->identifier = $data->{'semesteridentifier' . $j};
                $semester->name = $data->{'semestername' . $j};
                $semester->start = $data->{'semesterstart' . $j};
                $semester->end = $data->{'semesterend' . $j};

                $updates[] = $semester;

            } else {
                // Create new semester and add to inserts.
                if (!empty($data->{'semesteridentifier' . $j})) {
                    $semester = new stdClass();
                    // No ID is set when inserting.
                    $semester->identifier = $data->{'semesteridentifier' . $j};
                    $semester->name = $data->{'semestername' . $j};
                    $semester->start = $data->{'semesterstart' . $j};
                    $semester->end = $data->{'semesterend' . $j};

                    $inserts[] = $semester;
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
