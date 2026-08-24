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
 * DB implementation of waitlist_offer_repository (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md
 * §3.2), backed by booking_waitlist_offers/booking_waitlist_declines (db/install.xml).
 *
 * "Unbehandelt" (get_unbehandelte_waitinglist()) is scoped to OPEN offers only, not to a specific
 * round. Both a declined AND an expired offer permanently lock the user out of future offers for
 * this option (booking_waitlist_declines) - nobody who has ever received an offer for an option
 * gets asked again, whether they actively declined or simply let the deadline pass (explicit
 * Georg decision, 2026-08-20 - supersedes an earlier, unconfirmed assumption made while building
 * this class that only active declines should lock permanently). The caller (progression) is
 * responsible for computing the exclude list itself via get_permanently_declined_userids() and
 * passing it in - this repository does not apply that exclusion automatically.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\local\waitlist\offer_statuses\pending;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\local\waitlist\offer_statuses\accepted;
use mod_booking\local\waitlist\offer_statuses\declined;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\local\waitlist\offer_statuses\skipped;
use mod_booking\local\waitlist\offer_statuses\autobooked;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Reads/writes booking_waitlist_offers and booking_waitlist_declines.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class db_waitlist_offer_repository implements waitlist_offer_repository {
    /** @var \core\clock */
    private $clock;

    /**
     * Constructs the repository, optionally with an explicit clock.
     * @param \core\clock|null $clock defaults to the globally registered clock (§5.1) - lets
     *   tests pick it up automatically via mock_clock_with_frozen()/mock_clock_with_incrementing()
     *   without needing to pass anything explicitly.
     */
    public function __construct(?\core\clock $clock = null) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * All currently open (non-terminal) offers for an option.
     *
     * @param int $optionid
     * @return waitlist_offer[]
     */
    public function get_open_offers(int $optionid): array {
        global $DB;

        $sql = "SELECT *
                  FROM {booking_waitlist_offers}
                 WHERE optionid = :optionid
                   AND status IN (:pendingcode, :offeredcode)
              ORDER BY sortorder ASC, id ASC";
        $params = [
            'optionid' => $optionid,
            'pendingcode' => (new pending())->get_code(),
            'offeredcode' => (new offered())->get_code(),
        ];

        $records = $DB->get_records_sql($sql, $params);
        return array_values(array_map([$this, 'hydrate'], $records));
    }

    /**
     * Waiting-list candidates who have no OPEN offer for this option, excluding the given user
     * ids (typically the K7 permanently-declined, computed by the caller). Ordered by original
     * join time, then id as tie-break (O1/O2).
     *
     * @param int $optionid
     * @param int[] $excludeuserids
     * @return \stdClass[] each with ->userid, ->baid, ->jointime
     */
    public function get_unbehandelte_waitinglist(int $optionid, array $excludeuserids): array {
        global $DB;

        $excludesql = '';
        $excludeparams = [];
        if (!empty($excludeuserids)) {
            [$excludesql, $excludeparams] = $DB->get_in_or_equal(
                array_values($excludeuserids),
                SQL_PARAMS_NAMED,
                'ex',
                false
            );
            $excludesql = "AND ba.userid $excludesql";
        }

        $sql = "SELECT ba.userid AS userid, MAX(ba.id) AS baid, MIN(ba.timemodified) AS jointime
                  FROM {booking_answers} ba
                 WHERE ba.optionid = :optionid
                   AND ba.waitinglist = :waitinglist
                   AND NOT EXISTS (
                         SELECT 1
                           FROM {booking_waitlist_offers} bwo
                          WHERE bwo.optionid = ba.optionid
                            AND bwo.userid = ba.userid
                            AND bwo.status IN (:pendingcode, :offeredcode)
                       )
                   $excludesql
              GROUP BY ba.userid
              ORDER BY jointime ASC, baid ASC";

        $params = array_merge(
            [
                'optionid' => $optionid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'pendingcode' => (new pending())->get_code(),
                'offeredcode' => (new offered())->get_code(),
            ],
            $excludeparams
        );

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Creates a new offer/decision row.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $roundid
     * @param int $sortorder
     * @param offer_status $status
     * @param int $expiresat
     * @param int $ruleid
     * @return waitlist_offer
     */
    public function create_offer(
        int $optionid,
        int $userid,
        int $roundid,
        int $sortorder,
        offer_status $status,
        int $expiresat = 0,
        int $ruleid = 0
    ): waitlist_offer {
        global $DB;

        $now = $this->clock->time();
        $record = new \stdClass();
        $record->optionid = $optionid;
        $record->userid = $userid;
        $record->baid = $this->find_baid($optionid, $userid);
        $record->roundid = $roundid;
        $record->status = $status->get_code();
        $record->sortorder = $sortorder;
        $record->offeredat = $now;
        $record->expiresat = $expiresat;
        $record->ruleid = $ruleid;
        $record->version = 1;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $record->id = $DB->insert_record('booking_waitlist_offers', $record);

        return $this->hydrate($record);
    }

    /**
     * Transitions an existing offer to a new status. Throws if the transition is not allowed
     * by offer_status::can_transition_to(), or if the row was concurrently modified since
     * $offer was loaded (optimistic locking, §5.3).
     *
     * @param waitlist_offer $offer
     * @param offer_status $newstatus
     * @return void
     */
    public function transition(waitlist_offer $offer, offer_status $newstatus): void {
        global $DB;

        if (!$offer->status->can_transition_to($newstatus)) {
            throw new \coding_exception(
                'Invalid waitlist offer transition from ' . get_class($offer->status) .
                ' to ' . get_class($newstatus) . ' for offer id ' . $offer->id . '.'
            );
        }

        // Note: checked BEFORE writing, not after - $DB->execute() never portably reports an
        // affected-row count (confirmed in the pgsql driver: it always just returns true), so a
        // post-write check cannot reliably tell "our write applied" apart from "an unrelated
        // change coincidentally landed on the same expected version number" (§5.3).
        $currentversion = $DB->get_field('booking_waitlist_offers', 'version', ['id' => $offer->id], MUST_EXIST);
        if ((int) $currentversion !== $offer->version) {
            throw new \coding_exception(
                'Optimistic lock conflict: waitlist offer id ' . $offer->id .
                ' was already modified by another process (expected version ' . $offer->version .
                ', found ' . $currentversion . ').'
            );
        }

        $now = $this->clock->time();
        $DB->execute(
            "UPDATE {booking_waitlist_offers}
                SET status = :newstatus, version = version + 1, timemodified = :now
              WHERE id = :id AND version = :version",
            [
                'newstatus' => $newstatus->get_code(),
                'now' => $now,
                'id' => $offer->id,
                'version' => $offer->version,
            ]
        );

        if ($newstatus instanceof declined || $newstatus instanceof expired) {
            $this->lock_permanently($offer->optionid, $offer->userid, $newstatus->get_code());
        }
    }

    /**
     * Whether a user is permanently locked out of offers for this option (K7).
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    public function is_permanently_declined(int $optionid, int $userid): bool {
        global $DB;
        return $DB->record_exists('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $userid]);
    }

    /**
     * Whether a user is locked out specifically via an ACTIVE decline (K7) - see interface
     * docblock.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    public function is_actively_declined(int $optionid, int $userid): bool {
        global $DB;
        return $DB->record_exists('booking_waitlist_declines', [
            'optionid' => $optionid,
            'userid' => $userid,
            'reason' => (new declined())->get_code(),
        ]);
    }


    /**
     * All user ids permanently locked out of offers for this option (K7).
     *
     * @param int $optionid
     * @return int[]
     */
    public function get_permanently_declined_userids(int $optionid): array {
        global $DB;
        return array_values($DB->get_fieldset_select(
            'booking_waitlist_declines',
            'userid',
            'optionid = :optionid',
            ['optionid' => $optionid]
        ));
    }

    /**
     * Whether a user is still actually on the waiting list right now - a live re-check, since
     * get_unbehandelte_waitinglist() returns a snapshot that can go stale mid-reconcile() (K8).
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    public function is_still_on_waitinglist(int $optionid, int $userid): bool {
        global $DB;
        return $DB->record_exists('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
    }

    /**
     * Loads a single offer by id.
     *
     * @param int $id
     * @return waitlist_offer|null null if no such offer exists (anymore).
     */
    public function get_offer_by_id(int $id): ?waitlist_offer {
        global $DB;
        $record = $DB->get_record('booking_waitlist_offers', ['id' => $id], '*', IGNORE_MISSING);
        if (empty($record)) {
            return null;
        }
        return $this->hydrate($record);
    }


    /**
     * Finds options that are genuinely stalled - see interface docblock. Builds a fresh
     * capacity_calculator locally (not a constructor dependency, to avoid a circular DI wiring
     * with progression_factory) to reuse its authoritative free-capacity formula rather than
     * re-deriving an approximate one here.
     *
     * @return int[]
     */
    public function find_stalled_options(): array {
        global $DB;

        $sql = "SELECT DISTINCT ba.optionid
                  FROM {booking_answers} ba
                 WHERE ba.waitinglist = :waitinglist
                   AND NOT EXISTS (
                         SELECT 1
                           FROM {booking_waitlist_offers} bwo
                          WHERE bwo.optionid = ba.optionid
                            AND bwo.status IN (:pendingcode, :offeredcode)
                       )";
        $candidateoptionids = $DB->get_fieldset_sql($sql, [
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'pendingcode' => (new pending())->get_code(),
            'offeredcode' => (new offered())->get_code(),
        ]);

        $capacity = new capacity_calculator($this);
        $stalled = [];
        foreach ($candidateoptionids as $optionid) {
            if ($capacity->free_capacity((int) $optionid) > 0) {
                $stalled[] = (int) $optionid;
            }
        }
        return $stalled;
    }


    /**
     * Waitlist-recycling: removes the K4 (expired) lock for every user currently locked out on
     * this option. Never touches reason=declined (K7) rows - those are permanent regardless.
     *
     * @param int $optionid
     * @return void
     */
    public function reset_expired_locks(int $optionid): void {
        global $DB;
        $DB->delete_records('booking_waitlist_declines', [
            'optionid' => $optionid,
            'reason' => (new expired())->get_code(),
        ]);
    }

    /**
     * Finds options where waitlistrecycling is enabled AND the waiting list is currently fully
     * flagged: at least one person is still waiting, nobody has an open (pending/offered) offer,
     * and every remaining waiter is locked out (declined or expired - either reason counts here,
     * since a wholly declined list has nothing to reset either, reset_expired_locks() would just
     * be a no-op for it).
     *
     * @return int[] option ids
     */
    public function find_recyclable_options(): array {
        global $DB;
        $sql = "SELECT DISTINCT bo.id
                  FROM {booking_options} bo
                  JOIN {booking_answers} ba ON ba.optionid = bo.id AND ba.waitinglist = :waitinglist
                 WHERE bo.waitlistrecycling = 1
                   AND NOT EXISTS (
                         SELECT 1
                           FROM {booking_answers} ba2
                          WHERE ba2.optionid = bo.id
                            AND ba2.waitinglist = :waitinglist2
                            AND NOT EXISTS (
                                  SELECT 1
                                    FROM {booking_waitlist_declines} bwd
                                   WHERE bwd.optionid = bo.id AND bwd.userid = ba2.userid
                                )
                       )
                   AND NOT EXISTS (
                         SELECT 1
                           FROM {booking_waitlist_offers} bwo
                          WHERE bwo.optionid = bo.id
                            AND bwo.status IN (:pendingcode, :offeredcode)
                       )";
        return array_map('intval', $DB->get_fieldset_sql($sql, [
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'waitinglist2' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'pendingcode' => (new pending())->get_code(),
            'offeredcode' => (new offered())->get_code(),
        ]));
    }

    /**
     * Typ 2 ("offen nach Durchlauf"): whether this option's freed seat is currently open for
     * direct booking by anyone except K7-permanently-declined.
     *
     * @param int $optionid
     * @return bool
     */
    public function is_open_mode_active(int $optionid): bool {
        global $DB;
        return (bool) $DB->get_field('booking_options', 'waitlistopenmode', ['id' => $optionid]);
    }

    /**
     * Activates Typ-2 open mode for this option.
     *
     * @param int $optionid
     * @return void
     */
    public function activate_open_mode(int $optionid): void {
        global $DB;
        $DB->set_field('booking_options', 'waitlistopenmode', 1, ['id' => $optionid]);
    }

    /**
     * Deactivates Typ-2 open mode for this option.
     *
     * @param int $optionid
     * @return void
     */
    public function deactivate_open_mode(int $optionid): void {
        global $DB;
        $DB->set_field('booking_options', 'waitlistopenmode', 0, ['id' => $optionid]);
    }

    /**
     * Finds options where waitlistrecycling=2, open mode is not yet active, and the waiting list
     * is currently fully flagged - same condition as find_recyclable_options(), just scoped to
     * waitlistrecycling=2 instead of =1.
     *
     * @return int[] option ids
     */
    public function find_open_mode_activation_candidates(): array {
        global $DB;
        $sql = "SELECT DISTINCT bo.id
                  FROM {booking_options} bo
                  JOIN {booking_answers} ba ON ba.optionid = bo.id AND ba.waitinglist = :waitinglist
                 WHERE bo.waitlistrecycling = 2
                   AND bo.waitlistopenmode = 0
                   AND NOT EXISTS (
                         SELECT 1
                           FROM {booking_answers} ba2
                          WHERE ba2.optionid = bo.id
                            AND ba2.waitinglist = :waitinglist2
                            AND NOT EXISTS (
                                  SELECT 1
                                    FROM {booking_waitlist_declines} bwd
                                   WHERE bwd.optionid = bo.id AND bwd.userid = ba2.userid
                                )
                       )
                   AND NOT EXISTS (
                         SELECT 1
                           FROM {booking_waitlist_offers} bwo
                          WHERE bwo.optionid = bo.id
                            AND bwo.status IN (:pendingcode, :offeredcode)
                       )";
        return array_map('intval', $DB->get_fieldset_sql($sql, [
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'waitinglist2' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'pendingcode' => (new pending())->get_code(),
            'offeredcode' => (new offered())->get_code(),
        ]));
    }

    /**
     * Finds options currently in Typ-2 open mode whose freed seat has actually been taken (free
     * capacity is back to 0). Builds a fresh capacity_calculator locally, same reasoning as
     * find_stalled_options() - avoids a circular DI wiring with progression_factory.
     *
     * @return int[] option ids
     */
    public function find_open_mode_options_to_deactivate(): array {
        global $DB;
        $candidateoptionids = $DB->get_fieldset_select(
            'booking_options',
            'id',
            'waitlistopenmode = 1'
        );

        $capacity = new capacity_calculator($this);
        $todeactivate = [];
        foreach ($candidateoptionids as $optionid) {
            if ($capacity->free_capacity((int) $optionid) <= 0) {
                $todeactivate[] = (int) $optionid;
            }
        }
        return $todeactivate;
    }

    /**
     * Inserts a lock row, unless one already exists. $reason (the offer_status code that
     * triggered the lock) determines resettability - reason=declined (K7) is never reset;
     * reason=expired (K4) is reset by reset_expired_locks() when waitlistrecycling applies.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $reason the triggering offer_status::get_code() (declined or expired).
     * @return void
     */
    private function lock_permanently(int $optionid, int $userid, int $reason): void {
        global $DB;
        if ($DB->record_exists('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $userid])) {
            return;
        }
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $userid,
            'reason' => $reason,
            'timecreated' => $this->clock->time(),
        ]);
    }

    /**
     * Finds the booking_answers.id of a user's (most recent) waiting-list answer for an option.
     *
     * @param int $optionid
     * @param int $userid
     * @return int 0 if no matching answer is found.
     */
    private function find_baid(int $optionid, int $userid): int {
        global $DB;
        $sql = "SELECT MAX(id)
                  FROM {booking_answers}
                 WHERE optionid = :optionid AND userid = :userid AND waitinglist = :waitinglist";
        $baid = $DB->get_field_sql($sql, [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        return $baid ? (int) $baid : 0;
    }

    /**
     * Maps a raw DB record into a typed waitlist_offer, resolving the status code into a
     * concrete offer_status.
     *
     * @param \stdClass $record
     * @return waitlist_offer
     */
    private function hydrate(\stdClass $record): waitlist_offer {
        return new waitlist_offer(
            (int) $record->id,
            (int) $record->optionid,
            (int) $record->userid,
            (int) $record->baid,
            (int) $record->roundid,
            $this->status_from_code((int) $record->status),
            (int) $record->sortorder,
            (int) $record->offeredat,
            (int) $record->expiresat,
            (int) $record->ruleid,
            (int) $record->version,
            (int) $record->timecreated,
            (int) $record->timemodified
        );
    }

    /**
     * Resolves a persisted status code into its concrete offer_status implementation.
     *
     * @param int $code
     * @return offer_status
     */
    private function status_from_code(int $code): offer_status {
        return match ($code) {
            0 => new pending(),
            1 => new offered(),
            2 => new accepted(),
            3 => new declined(),
            4 => new expired(),
            5 => new skipped(),
            6 => new autobooked(),
            default => throw new \coding_exception("Unknown offer_status code: {$code}"),
        };
    }
}
