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
 * T7 self-healing (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §4.2): periodically re-reconciles
 * options whose trigger got lost (crashed cron, a bug in a trigger adapter, whatever the cause).
 * The cron entry itself fires every 5 minutes (the hard floor, db/tasks.php) - the actual
 * effective interval is admin-configurable (default 15 minutes, clamped to never go below that
 * same 5-minute floor) via a stored last-run timestamp, so the cron schedule stays fixed while
 * the effective cadence stays tunable.
 *
 * Also drives waitlist-recycling: for options with waitlistrecycling enabled, once the waiting
 * list is fully flagged (everyone still waiting is locked out, K7 declined or K4 expired), the
 * K4 expiry-locks (only those, never a K7 decline-lock) are reset and the option is reconciled
 * again - see db_waitlist_offer_repository::find_recyclable_options()/reset_expired_locks().
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\progression_factory;

/**
 * Finds and re-reconciles stalled options, and resets/re-reconciles fully-flagged
 * waitlist-recycling options.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class waitlist_heartbeat_task extends \core\task\scheduled_task {
    /** @var int T7: never run more often than every 5 minutes, regardless of configuration. */
    const MIN_INTERVAL_SECONDS = 300;

    /** @var int T7: default effective interval if nothing is configured. */
    const DEFAULT_INTERVAL_SECONDS = 900;

    /**
     * Task name shown in the admin task list.
     *
     * @return \lang_string|string
     */
    public function get_name() {
        return get_string('taskwaitlistheartbeat', 'mod_booking');
    }

    /**
     * Self-heals every genuinely stalled option, and resets/re-reconciles every fully-flagged
     * waitlist-recycling option - both throttled to the configured effective interval.
     *
     * @return void
     */
    public function execute() {
        $clock = \core\di::get(\core\clock::class);
        $now = $clock->time();

        $configured = (int) get_config('booking', 'waitlistheartbeatinterval');
        $interval = max(self::MIN_INTERVAL_SECONDS, $configured ?: self::DEFAULT_INTERVAL_SECONDS);

        $lastrun = (int) get_config('booking', 'waitlistheartbeatlastrun');
        if ($lastrun && ($now - $lastrun) < $interval) {
            return;
        }
        set_config('waitlistheartbeatlastrun', $now, 'booking');

        $repository = new db_waitlist_offer_repository();
        foreach ($repository->find_stalled_options() as $optionid) {
            progression_factory::get()->reconcile((int) $optionid, 'heartbeat');
        }

        foreach ($repository->find_recyclable_options() as $optionid) {
            $repository->reset_expired_locks((int) $optionid);
            progression_factory::get()->reconcile((int) $optionid, 'waitlist:recycled');
        }

        // Type 2 ("open after full pass"): list has been fully processed once, spot still
        // unclaimed - from now on directly bookable for everyone except K7-blocked users (see
        // onwaitinglist::is_available()). No reconcile() needed - progression::reconcile()
        // would immediately return anyway because of the flag just set.
        foreach ($repository->find_open_mode_activation_candidates() as $optionid) {
            $repository->activate_open_mode((int) $optionid);
        }

        // Spot has meanwhile been taken (free capacity back to 0) - deactivate the mode so
        // future new waitlist joins are handled normally via offer again.
        foreach ($repository->find_open_mode_options_to_deactivate() as $optionid) {
            $repository->deactivate_open_mode((int) $optionid);
        }
    }
}
