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
 * "Unbehandelt" (get_unbehandelte_waitinglist()) is scoped to OPEN offers only, not to a
 * specific round: a candidate whose only existing row is terminal-but-not-declined (e.g.
 * expired, K4) must be able to reappear as a candidate in a later round - only declined is a
 * permanent lock (K7), enforced separately via booking_waitlist_declines. The caller
 * (progression, later) is responsible for computing the K7 exclude list itself via
 * is_permanently_declined() and passing it in - this repository does not apply that exclusion
 * automatically.
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

        if ($newstatus instanceof declined) {
            $this->lock_permanently($offer->optionid, $offer->userid);
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
     * Inserts a permanent K7 lock row, unless one already exists.
     *
     * @param int $optionid
     * @param int $userid
     * @return void
     */
    private function lock_permanently(int $optionid, int $userid): void {
        global $DB;
        if ($DB->record_exists('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $userid])) {
            return;
        }
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $userid,
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
