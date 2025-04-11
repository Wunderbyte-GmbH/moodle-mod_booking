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
 * Function to correctly upgrade mod_booking.
 *
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Function to upgrade old ids which where part of the field "text"
 * within booking options and move them into the new DB field 'identifier'.
 * @return void
 */
function migrate_booking_option_identifiers_2022090802() {
    global $DB;
    if ($separator = get_config('booking', 'uniqueoptionnameseparator')) {
        if ($recordstomigrate = $DB->get_records('booking_options')) {
            foreach ($recordstomigrate as $record) {
                // Records that do not contain the separator do not need to be changed.
                if (strpos($record->text, $separator) == false) {
                    continue;
                }
                [$name, $identifier] = explode($separator, $record->text);
                /* Example: MyOption#?#4eded74a => the name "MyOption" will be restored
                and the identifier "4eded74a" will be moved to the identifier field. */
                $record->identifier = $identifier;
                $record->text = $name;
                $DB->update_record('booking_options', $record);
            }
        }
    }
}

/**
 * We renamed the column optionid to itemid,
 * so we need to set the area to "option" for each migrated row.
 * @return void
 */
function migrate_optionids_for_prices_2022112901() {
    global $DB;
    if ($recordstomigrate = $DB->get_records('booking_prices')) {
        foreach ($recordstomigrate as $record) {
            $record->area = 'option';
            $DB->update_record('booking_prices', $record);
        }
    }
}

/**
 * With the new view.php we also introduced a new way to configure fields
 * for the list of booking options. So after the new view gets introduced,
 * we need to set all fields so nothing disappears.
 */
function migrate_optionsfields_2023022800() {
    global $DB;
    if ($recordstomigrate = $DB->get_records('booking')) {
        foreach ($recordstomigrate as $record) {
            $record->optionsfields =
                'description,statusdescription,teacher,showdates,dayofweektime,location,institution,minanswers';
            $DB->update_record('booking', $record);
        }
    }
}

/**
 * Fix descriptionformat for all booking options.
 */
function fix_bookingoption_descriptionformat_2024022700() {
    global $DB;
    $DB->execute(
        "UPDATE {booking_options}
        SET descriptionformat = 1
        WHERE descriptionformat = 0"
    );
}

/**
 * Fix showlistoncoursepage for all booking instances.
 */
function fix_showlistoncoursepage_2024030801() {
    global $DB;
    $DB->execute(
        "UPDATE {booking}
        SET showlistoncoursepage = 1
        WHERE showlistoncoursepage = 2"
    );
}

/**
 * Migrate former bookingids to contextids.
 * @return void
 */
function migrate_contextids_2024040901() {
    global $DB;

    $DB->execute(
        "UPDATE {booking_rules}
        SET contextid = 1"
    );
}

/**
 * Make sure we have no NULL value in template id.
 *
 * @return [type]
 *
 */
function fix_booking_templateid() {

    global $DB;

    $sql = "SELECT id, templateid
            FROM {booking}
            WHERE templateid IS NULL";
    $records = $DB->get_records_sql($sql);

    foreach ($records as $record) {
        $record->templateid = 0;
        $DB->update_record('booking', $record);
    }
}

/**
 * Function to add the "places" information to all the existing booking_answer records.
 *
 * @return void
 *
 */
function fix_places_for_booking_answers() {

    global $DB;

    // Define your SQL update query.
    $sql = "UPDATE {booking_answers}
               SET places = 1
             WHERE places IS NULL";

    // Execute the query.
    $DB->execute($sql);
}

/**
 * Remove values form completiongradeitemnumber and completionpassgrade to avoid #779 error after #629.
 *
 * @return void
 */
function remove_completiongradeitemnumber_2025010803() {
    global $DB;

    $bookingmoduleid = $DB->get_field('modules', 'id', ['name' => 'booking']);

    // Define your SQL update query.
    $sql = "UPDATE {course_modules}
        SET completiongradeitemnumber = null, completionpassgrade = 0
        WHERE module = :bookigmodules";

    // Define the parameters for the query.
    $params = ['bookigmodules' => $bookingmoduleid];

    // Execute the query.
    $DB->execute($sql, $params);
}

/**
 * Initialize the timecreated field for booking_options.
 * @return void
 */
function booking_options_initialize_timecreated() {
    global $DB;

    $sql = "UPDATE {booking_options}
               SET timecreated = timemodified
             WHERE timecreated = 0";

    // Execute the query.
    $DB->execute($sql);
}
