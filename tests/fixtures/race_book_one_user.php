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
 * Realistic concurrency fixture: one standalone process books exactly ONE user onto
 * the first booking option, at a randomized moment within a shared time window, so
 * many such processes contend on the same few seats like real users clicking at once.
 *
 * Adopts the PHPUnit environment via PHPUNIT_UTIL (returns before reset_all_data, so
 * the test's committed data survives) for a shared DB + cache namespace.
 *
 * argv: [1]=marker(json out)  [2]=userid  [3]=gofile  [4]=window(ms)
 *
 * Barrier: after bootstrap the process writes "<marker>.ready" and busy-waits until the
 * test creates <gofile> (containing the GO epoch). Only then does it jitter within the
 * window and book — so the window opens only once ALL processes are bootstrapped.
 *
 * The marker JSON records the actual booking start/end timestamps so the test can
 * measure the booking-phase wall-clock (excluding bootstrap and the deliberate jitter).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal
define('PHPUNIT_UTIL', true);
require(__DIR__ . '/../../../../../vendor/autoload.php');
require(__DIR__ . '/../../../../lib/phpunit/bootstrap.php');
// phpcs:enable moodle.Files.MoodleInternal

global $DB, $USER;

$marker = $argv[1] ?? '';
$userid = (int) ($argv[2] ?? 0);
$gofile = $argv[3] ?? '';
$windowms = (int) ($argv[4] ?? 0);

$result = ['userid' => $userid];

try {
    // Barrier: signal that we are bootstrapped and ready, then wait for the GO file.
    if ($marker) {
        file_put_contents($marker . '.ready', '1');
    }
    $startepoch = 0.0;
    $waitdeadline = microtime(true) + 120;
    while (microtime(true) < $waitdeadline) {
        if ($gofile && is_file($gofile)) {
            $startepoch = (float) file_get_contents($gofile);
            break;
        }
        usleep(5000);
    }
    if (empty($startepoch)) {
        throw new \RuntimeException('GO signal never arrived');
    }

    // Pick a randomized moment within [GO, GO + window].
    $target = $startepoch + (mt_rand(0, max(1, $windowms)) / 1000.0);
    $now = microtime(true);
    if ($target > $now) {
        usleep((int) round(($target - $now) * 1e6));
    }

    $record = $DB->get_record('booking_options', [], '*', IGNORE_MULTIPLE);
    $optionid = (int) $record->id;
    $USER = \core_user::get_user($userid);

    // Hit the shared cache, not the in-request singleton.
    \mod_booking\singleton_service::destroy_instance();
    $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($optionid);
    $boinfo = new \mod_booking\bo_availability\bo_info($settings);

    // Measure only the booking work (jitter already elapsed). Only try to take a SEAT
    // (BOOKITBUTTON); let the booking logic decide booked vs waiting list. Overbooking
    // is then: more than maxanswers end up booked.
    $bookstart = microtime(true);
    [$id] = $boinfo->is_available($settings->id, $userid, true);
    if ($id == MOD_BOOKING_BO_COND_BOOKITBUTTON) {
        \mod_booking\booking_bookit::bookit('option', $settings->id, $userid);
        // The confirm step is a SEPARATE request in production -> fresh singleton, so the
        // capacity check at the actual booking reads the current count, not the armed view.
        \mod_booking\singleton_service::destroy_instance();
        $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($optionid);
        $boinfo = new \mod_booking\bo_availability\bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $userid, true);
        if ($id == MOD_BOOKING_BO_COND_CONFIRMBOOKIT) {
            \mod_booking\booking_bookit::bookit('option', $settings->id, $userid);
        }
    }
    $bookend = microtime(true);

    $result['start'] = $bookstart;
    $result['end'] = $bookend;
    $result['finalstatus'] = $id;
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

if ($marker) {
    file_put_contents($marker, json_encode($result));
}
