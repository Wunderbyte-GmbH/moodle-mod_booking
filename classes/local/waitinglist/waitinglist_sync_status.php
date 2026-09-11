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

namespace mod_booking\local\waitinglist;

use context;
use context_module;
use mod_booking\bo_availability\conditions\optionhasstarted;
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_option_settings;
use mod_booking\price;
use mod_booking\singleton_service;
use stdClass;

/**
 * Single source of truth for the gates that govern booking_option::sync_waiting_list().
 *
 * The predicate methods here encode the exact conditions used inside
 * sync_waiting_list(); sync_waiting_list() calls them in place, and explain()
 * composes them into a structured, read-only report so a diagnosis (e.g. "I
 * reduced the seats but nobody was moved") can be answered deterministically
 * from engine state - never from phrase matching.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class waitinglist_sync_status {
    /** @var string Global waiting list off-switch is on. */
    const GATE_TURNOFF_GLOBAL = 'turnoffwaitinglist';

    /** @var string Automatic moving up after the option has started is turned off (and it has started). */
    const GATE_TURNOFF_AFTERSTART = 'turnoffwaitinglistaftercoursestart';

    /** @var string The optionhasstarted condition blocks (option started, instance disallows booking after start). */
    const GATE_OPTION_STARTED = 'optionhasstarted';

    /**
     * The single blocking gate that makes sync_waiting_list() return early, or null.
     *
     * Mirrors the early-exit condition in sync_waiting_list() 1:1 (same order, same
     * short-circuit). All three checks are side-effect free.
     *
     * @param booking_option_settings $settings
     * @return string|null one of the GATE_* constants, or null when the sync may proceed
     */
    public static function first_blocking_global_gate(booking_option_settings $settings): ?string {
        if (get_config('booking', 'turnoffwaitinglist')) {
            return self::GATE_TURNOFF_GLOBAL;
        }
        if (get_config('booking', 'turnoffwaitinglistaftercoursestart') && time() > $settings->coursestarttime) {
            return self::GATE_TURNOFF_AFTERSTART;
        }
        if (!(new optionhasstarted())->is_available($settings, 0)) {
            return self::GATE_OPTION_STARTED;
        }
        return null;
    }

    /**
     * Whether a priced option skips the given user in the sync (paid seats are never
     * moved automatically). Mirrors the price check used in every sync loop 1:1.
     *
     * @param booking_option_settings $settings
     * @param stdClass $user
     * @return bool
     */
    public static function paid_option_skips_user(booking_option_settings $settings, stdClass $user): bool {
        $price = price::get_price('option', $settings->id, $user);
        return !empty($settings->jsonobject->useprice) // This is important to check first!
            && isset($price['price'])
            && !empty((float)$price['price']);
    }

    /**
     * Whether the demotion/trim phase of the sync runs when the option was updated.
     * Mirrors the phase 2/3 gate in sync_waiting_list() 1:1.
     *
     * @param bool $optionupdated
     * @param context $context module context of the option
     * @return bool
     */
    public static function reduction_gate_open(bool $optionupdated, context $context): bool {
        return $optionupdated
            && has_capability('mod/booking:deleteresponses', $context)
            && !get_config('booking', 'keepusersbookedonreducingmaxanswers');
    }

    /**
     * Structured, read-only report of every gate for one option (optionally one user),
     * for diagnosing why the waiting list did or did not move.
     *
     * @param int $optionid
     * @param int $userid user to evaluate the price gate for; 0 uses the current user
     * @return array report with keys: optionid, haswaitinglist, counts, blockinggate,
     *               gates (bool per gate), and a machine-readable issue list
     */
    public static function explain(int $optionid, int $userid = 0): array {
        global $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id)) {
            return ['optionid' => $optionid, 'error' => 'optionnotfound'];
        }
        $context = context_module::instance($settings->cmid);
        $userid = empty($userid) ? (int)$USER->id : $userid;
        $user = singleton_service::get_instance_of_user($userid);

        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $bookedplaces = booking_answers::count_places($ba->get_usersonlist());
        $reservedplaces = booking_answers::count_places($ba->get_usersreserved());
        $waitingplaces = booking_answers::count_places($ba->get_usersonwaitinglist());

        $blockinggate = self::first_blocking_global_gate($settings);
        $haswaitinglist = (bool)($settings->limitanswers && !empty($settings->maxanswers));

        $issues = [];
        if ($blockinggate !== null) {
            $issues[] = $blockinggate;
        }
        if (!$haswaitinglist) {
            $issues[] = 'nowaitinglist';
        }
        if (self::paid_option_skips_user($settings, $user)) {
            $issues[] = 'paidoption';
        }
        if (get_config('booking', 'keepusersbookedonreducingmaxanswers')) {
            $issues[] = 'keepusersbooked';
        }
        if (!empty($settings->waitforconfirmation)) {
            $issues[] = 'waitforconfirmation';
        }
        if (!has_capability('mod/booking:deleteresponses', $context)) {
            $issues[] = 'missingdeleteresponses';
        }

        return [
            'optionid' => $optionid,
            'userid' => $userid,
            'haswaitinglist' => $haswaitinglist,
            'blockinggate' => $blockinggate,
            'counts' => [
                'booked' => $bookedplaces,
                'reserved' => $reservedplaces,
                'waiting' => $waitingplaces,
                'maxanswers' => (int)$settings->maxanswers,
                'maxoverbooking' => (int)$settings->maxoverbooking,
            ],
            'overbooked' => ($bookedplaces + $reservedplaces) > (int)$settings->maxanswers,
            'issues' => $issues,
        ];
    }
}
