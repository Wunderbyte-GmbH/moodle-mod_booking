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
 * Functions to correctly upgrade mod_booking.
 *
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Function to upgrade old ids which where part of the field "text"
 * within booking options and move them into the new DB field 'identifier'.
 *
 * @return void
 */
function migrate_booking_option_identifiers() {
    global $DB;
    if ($separator = get_config('booking', 'uniqueoptionnameseparator')) {
        if ($recordstomigrate = $DB->get_records('booking_options')) {
            foreach ($recordstomigrate as $record) {
                // Records that do not contain the separator do not need to be changed.
                if (strpos($record->text, $separator) == false) {
                    continue;
                }
                list($name, $identifier) = explode($separator, $record->text);
                /* Example: MyOption#?#4eded74a => the name "MyOption" will be restored
                and the identifier "4eded74a" will be moved to the identifier field. */
                $record->identifier = $identifier;
                $record->text = $name;
                $DB->update_record('booking_options', $record);
            }
        }
    }
    return;
}
