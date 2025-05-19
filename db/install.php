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
 * Booking module databese install script.
 *
 * @package    mod_booking
 * @copyright  2009-2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * XMLDB Booking install function.
 * @return void
 */
function xmldb_booking_install() {
    global $DB;
     // Check if the table exists before inserting.
    if (!PHPUNIT_TEST && !(defined('BEHAT_SITE_RUNNING')) && $DB->get_manager()->table_exists('booking_pricecategories')) {
        // Check if a default category already exists.
        if (!$DB->record_exists('booking_pricecategories', ['identifier' => 'default'])) {
            // Define the default price category.
            $defaultcategory = new stdClass();
            $defaultcategory->ordernum = 1;
            $defaultcategory->identifier = 'default';
            $defaultcategory->name = 'Price';
            $defaultcategory->defaultvalue = 0.00;
            $DB->insert_record('booking_pricecategories', $defaultcategory);
        }
    }
}
