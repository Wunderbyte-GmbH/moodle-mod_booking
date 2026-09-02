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
 * Migration entry point (M1-M5, WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §3): inventories every
 * pending legacy chain task, reconstructs its state into real booking_waitlist_offers rows, then
 * removes the whole class of now-obsolete old-chain tasks. Idempotent by construction - the
 * cleanup happens in the same run as the reconstruction, so a second run() finds nothing left to
 * (re-)migrate (M4). Does NOT call progression::reconcile() itself - that is the caller's
 * responsibility (Phase 3's db/upgrade.php; the C1-C5 migration tests call it explicitly too),
 * keeping this class narrowly scoped to "migrate old state", not "drive new behaviour".
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\migration;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\task\expire_waitlist_offer_adhoc;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Migrates every pending legacy waitlist-progression chain into booking_waitlist_offers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_step {
    /** @var string[] the {task_adhoc}.classname values this migration inventories, in
     *  processing order - send_mail_by_rule_adhoc first, so its own (better-informed) offer for
     *  the directly-mailed user wins over that same user's confirm_bookinganswer companion task. */
    private const TASK_CLASSNAMES = [
        '\mod_booking\task\send_mail_by_rule_adhoc',
        '\mod_booking\task\confirm_bookinganswer_by_rule_adhoc',
    ];

    /**
     * Runs the migration. Static, no state, safe to call more than once (M4).
     *
     * @return void
     */
    public static function run(): void {
        global $DB;

        $readers = [
            new legacy_chain_reader_send_mail_interval(),
            new legacy_chain_reader_confirm_bookinganswer(),
        ];
        $repository = new db_waitlist_offer_repository();
        $clock = \core\di::get(\core\clock::class);

        foreach (self::TASK_CLASSNAMES as $classname) {
            $rs = $DB->get_recordset('task_adhoc', ['classname' => $classname]);
            foreach ($rs as $taskrecord) {
                foreach ($readers as $reader) {
                    if (!$reader->can_read($taskrecord)) {
                        continue;
                    }
                    try {
                        self::reconstruct($repository, $clock, $reader->extract($taskrecord));
                    } catch (\Throwable $e) {
                        // M3: a row that LOOKED readable but turned out broken (or points at
                        // data that no longer resolves) must never abort the whole migration -
                        // it simply stays un-migrated; the T7 heartbeat is the actual safety net.
                        debugging(
                            'upgrade_step: could not migrate task_adhoc id ' . $taskrecord->id
                                . ': ' . $e->getMessage(),
                            DEBUG_DEVELOPER
                        );
                    }
                    break; // First matching reader wins (legacy_chain_reader's Strategy contract).
                }
            }
            $rs->close();

            // M3: this whole class of old-chain tasks is obsolete post-migration - recognised
            // or not. Doing this in the SAME run as the reconstruction is what makes run()
            // idempotent (M4): a second call finds nothing left to (re-)migrate.
            $DB->delete_records('task_adhoc', ['classname' => $classname]);
        }
    }

    /**
     * Reconstructs one extracted legacy chain state into real offer rows.
     *
     * @param db_waitlist_offer_repository $repository
     * @param \core\clock $clock
     * @param legacy_chain_state $state
     * @return void
     */
    private static function reconstruct(
        db_waitlist_offer_repository $repository,
        \core\clock $clock,
        legacy_chain_state $state
    ): void {
        global $DB;

        if (!$DB->record_exists('booking_options', ['id' => $state->optionid])) {
            return; // C3: an orphaned chain pointing at a deleted option - nothing to migrate.
        }

        $roundid = $clock->time();
        foreach ($state->usersalreadytreated as $index => $userid) {
            if ($DB->record_exists('booking_waitlist_offers', ['optionid' => $state->optionid, 'userid' => $userid])) {
                // Already migrated - either a genuine re-run (M4), or (the common case for M1)
                // this same user's send_mail_interval companion confirm_bookinganswer task,
                // processed via the other reader in this same run. First writer wins.
                continue;
            }

            $answer = $DB->get_record('booking_answers', [
                'optionid' => $state->optionid,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);
            if (empty($answer) || (int) $answer->waitinglist !== MOD_BOOKING_STATUSPARAM_WAITINGLIST) {
                // No longer genuinely waiting (already booked, left, or never existed) - their
                // CURRENT, live booking_answers state is already what get_unbehandelte_waitinglist()/
                // get_open_offers() look at; there is nothing to reconstruct for them.
                continue;
            }

            // Preserve the old chain's own next-tick time as this offer's hard-expiry deadline
            // (WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §3.2) - the old system never expired offers
            // itself, so this is the most representative deadline continuity we have, not a
            // literal reconstruction of a deadline the old system never actually tracked.
            $expiresat = $state->nextruntime > 0 ? $state->nextruntime : $clock->time();
            $offer = $repository->create_offer(
                $state->optionid,
                $userid,
                $roundid,
                $index + 1,
                new offered(),
                $expiresat,
                $state->ruleid
            );

            // K4 must apply to a migrated offer exactly like a freshly-made one - no notification
            // (this is reconstructed history, not a new event), but a real expire task.
            $task = new expire_waitlist_offer_adhoc();
            $task->set_custom_data(['offerid' => $offer->id]);
            $task->set_next_run_time($expiresat);
            \core\task\manager::queue_adhoc_task($task);
        }
    }
}
