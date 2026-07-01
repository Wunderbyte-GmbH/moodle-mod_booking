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

namespace mod_booking\local\slotbooking;

use stdClass;

/**
 * Store for slot moves with a price difference (table booking_slot_moves).
 *
 * This is the single source of truth for an in-flight or committed slot move:
 *  - a PENDING row holds the target slots while the upgrade payment is in checkout
 *    (the capacity counter reads non-expired pending rows so the target slot is locked),
 *  - on a successful checkout the row is set to COMMITTED and linked to the cart identifier,
 *  - on abort / expiry / cancellation it is set to CANCELLED.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slot_move_store {
    /** @var int Move is held / awaiting checkout. */
    public const STATUS_PENDING = 0;

    /** @var int Move has been committed onto the booking answer. */
    public const STATUS_COMMITTED = 1;

    /** @var int Move was aborted, expired or cancelled. */
    public const STATUS_CANCELLED = 2;

    /** @var string Table name. */
    private const TABLE = 'booking_slot_moves';

    /**
     * Create a pending move (a held target-slot reservation tied to a checkout).
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id being moved
     * @param int $userid owner of the answer
     * @param array $newslots target slots, each ['start' => int, 'end' => int]
     * @param array $oldslots given-up slots (audit), same shape
     * @param float $pricedelta fixed price difference (new - old)
     * @param int $expiry hold expiry timestamp (= cart expirationtime)
     * @return int the new row id
     */
    public static function create_pending(
        int $optionid,
        int $baid,
        int $userid,
        array $newslots,
        array $oldslots,
        float $pricedelta,
        int $expiry
    ): int {
        global $DB;

        $now = time();
        $record = (object) [
            'optionid' => $optionid,
            'baid' => $baid,
            'userid' => $userid,
            'newslots' => json_encode(array_values($newslots)),
            'oldslots' => json_encode(array_values($oldslots)),
            'pricedelta' => $pricedelta,
            'status' => self::STATUS_PENDING,
            'expiry' => $expiry,
            'identifier' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Get a move row by id.
     *
     * @param int $id
     * @return stdClass|null
     */
    public static function get(int $id): ?stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Get the open (pending, non-expired) move for an answer, if any.
     *
     * @param int $baid booking answer id
     * @param int|null $now timestamp, defaults to time()
     * @return stdClass|null
     */
    public static function get_pending_for_answer(int $baid, ?int $now = null): ?stdClass {
        global $DB;

        $now = $now ?? time();
        $records = $DB->get_records_select(
            self::TABLE,
            'baid = :baid AND status = :status AND expiry > :now',
            ['baid' => $baid, 'status' => self::STATUS_PENDING, 'now' => $now],
            'timecreated DESC',
            '*',
            0,
            1
        );

        $record = $records ? reset($records) : null;
        return $record ?: null;
    }

    /**
     * Get the open (pending, non-expired) move for an option + user, if any.
     *
     * Used by the shopping_cart 'moveslot' callbacks, where the cart item is keyed by optionid
     * (so the ledger is option-traceable). The "one open move per answer" guard keeps this
     * unambiguous in the common case; if several exist (e.g. multiple bookings of one option),
     * the most recent is returned.
     *
     * @param int $optionid booking option id
     * @param int $userid owner
     * @param int|null $now timestamp, defaults to time()
     * @return stdClass|null
     */
    public static function get_pending_for_option_user(int $optionid, int $userid, ?int $now = null): ?stdClass {
        global $DB;

        $now = $now ?? time();
        $records = $DB->get_records_select(
            self::TABLE,
            'optionid = :optionid AND userid = :userid AND status = :status AND expiry > :now',
            ['optionid' => $optionid, 'userid' => $userid, 'status' => self::STATUS_PENDING, 'now' => $now],
            'timecreated DESC',
            '*',
            0,
            1
        );

        $record = $records ? reset($records) : null;
        return $record ?: null;
    }

    /**
     * Return the held target-slot ranges of all non-expired pending moves of an option.
     *
     * Used by the slot capacity counter to lock target slots while an upgrade is in checkout.
     *
     * @param int $optionid
     * @param int|null $now timestamp, defaults to time()
     * @return array list of ['moveid' => int, 'baid' => int, 'userid' => int, 'slots' => [['start','end'],...]]
     */
    public static function get_active_holds_for_option(int $optionid, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $records = $DB->get_records_select(
            self::TABLE,
            'optionid = :optionid AND status = :status AND expiry > :now',
            ['optionid' => $optionid, 'status' => self::STATUS_PENDING, 'now' => $now]
        );

        $holds = [];
        foreach ($records as $record) {
            $holds[] = [
                'moveid' => (int) $record->id,
                'baid' => (int) $record->baid,
                'userid' => (int) $record->userid,
                'slots' => self::decode_slots($record->newslots),
            ];
        }
        return $holds;
    }

    /**
     * Mark a move as committed and link it to the cart checkout identifier.
     *
     * @param int $id
     * @param int|null $identifier shopping cart checkout identifier
     * @return void
     */
    public static function commit(int $id, ?int $identifier = null): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_COMMITTED,
            'identifier' => $identifier,
            'timemodified' => time(),
        ]);
    }

    /**
     * Mark a move as cancelled (abort / expiry / answer cancellation).
     *
     * @param int $id
     * @return void
     */
    public static function cancel(int $id): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_CANCELLED,
            'timemodified' => time(),
        ]);
    }

    /**
     * Mark all expired pending moves as cancelled (housekeeping; the cart's expiry task
     * normally cancels them via unload, this is the safety net).
     *
     * @param int|null $now timestamp, defaults to time()
     * @return void
     */
    public static function purge_expired(?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $DB->set_field_select(
            self::TABLE,
            'status',
            self::STATUS_CANCELLED,
            'status = :status AND expiry <= :now',
            ['status' => self::STATUS_PENDING, 'now' => $now]
        );
    }

    /**
     * Decode a stored slots JSON blob into a normalised array of ['start' => int, 'end' => int].
     *
     * @param string|null $json
     * @return array
     */
    public static function decode_slots(?string $json): array {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $slots = [];
        foreach ($decoded as $slot) {
            if (isset($slot['start'], $slot['end'])) {
                $slots[] = ['start' => (int) $slot['start'], 'end' => (int) $slot['end']];
            }
        }
        return $slots;
    }
}
