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
 * TODO describe file simulate_ongoing_booking
 *
 * @package    mod_booking
 * @copyright  2025 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// For this file to use the same cache as the other thread...
// Change $CFG->cachedir = '/var/www/cache'; to $CFG->cachedir = '/var/www/phpunitdata/cache';

define('CLI_SCRIPT', true);
// require(__DIR__ . '/../../../../lib/phpunit/bootstrap.php'); // Relative path from your plugin to Moodle root.
require(__DIR__ . '/../../../../config.php'); // Adjust path to Moodle root.

global $DB, $USER;

// set_config('turnoffwaitinglist', 1, 'mod_booking');

// Compute the PHPUnit test DB name.
if (!empty($CFG->phpunit_prefix)) {
    $phpunitdbname = 'moodle'; // replace 'moodle' with your normal DB name

    // Dispose existing DB connection
    $DB->dispose();

    // Reconnect to PHPUnit DB
    $DB = moodle_database::get_driver_instance(
        $CFG->dbtype,
        $CFG->dblibrary,
        true
    );
    $DB->connect(
        $CFG->dbhost,
        $CFG->dbuser,
        $CFG->dbpass,
        $phpunitdbname, // <- use the PHPUnit DB
        $CFG->phpunit_prefix,
        $CFG->dboptions
    );
}

// We fetch the option id from the DB.
$record = $DB->get_record('booking_options', []);
$optionid = $record->id;

$cache = \cache::make('mod_booking', 'bookingoptionsettings');
$cachedoption = $cache->get($optionid);
// $cache->purge();

// We fetch all the students from the $DB.
$users = $DB->get_records('user', [], 'id DESC');
$successfullybooked = [];
$notsuccessfullybooked = [];

foreach ($users as $user) {
    // We don't want to run this for Admin or guest.
    if ($user->id < 3) {
        continue;
    }

    usleep(20000);

    $USER = \core_user::get_user($user->id);
    // We alsways make sure we hit the cache, not the singleton.
    \mod_booking\singleton_service::destroy_instance();
    // Build the cache again, if it's purged elsewhere, we hit the db.
    $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($optionid);
    $ba = \mod_booking\singleton_service::get_instance_of_booking_answers($settings);
    $boinfo = new \mod_booking\bo_availability\bo_info($settings);

    [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, true);

    if ($id == MOD_BOOKING_BO_COND_PRICEISSET) {
        // Book the users.
        \mod_booking\booking_bookit::answer_booking_option('option', $settings->id, MOD_BOOKING_STATUSPARAM_BOOKED, $user->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, true);
        $successfullybooked[] = $user;
        continue;
    } else if ($id == MOD_BOOKING_BO_COND_BOOKITBUTTON) {
        \mod_booking\booking_bookit::bookit('option', $settings->id, $user->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, true);
        if ($id == MOD_BOOKING_BO_COND_CONFIRMBOOKIT) {
            \mod_booking\booking_bookit::bookit('option', $settings->id, $user->id);
            $successfullybooked[] = $user;
            continue;
        }
    }
    $notsuccessfullybooked[] = $user;

    $sql = "SELECT COUNT(ID) FROM {booking_answers} where waitinglist IN (0,2)";
    $count = $DB->count_records_sql($sql);
    if ($count >= 101) {
        usleep(2000);
    }
}

unset($successfullybooked);
