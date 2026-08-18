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
 * A single row from booking_waitlist_offers (db/install.xml), typed
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.1). Pure data holder, no business logic and
 * no DB access - deliberately DB-agnostic, so it stays trivially usable in unit tests without
 * any database setup.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Immutable value object for one waitlist-offer row.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class waitlist_offer {
    /** @var int */
    public $id;

    /** @var int */
    public $optionid;

    /** @var int */
    public $userid;

    /** @var int booking_answers.id this offer/autobooking is about. */
    public $baid;

    /** @var int Groups all decisions made in the same reconcile() pass. */
    public $roundid;

    /** @var offer_status */
    public $status;

    /** @var int Frozen at round start (O1-O3, O5), never changed afterward. */
    public $sortorder;

    /** @var int 0 until an offer is actually made. */
    public $offeredat;

    /** @var int 0 = no deadline (e.g. pending/autobooked). */
    public $expiresat;

    /** @var int */
    public $ruleid;

    /** @var int Optimistic locking counter. */
    public $version;

    /** @var int */
    public $timecreated;

    /** @var int */
    public $timemodified;

    /**
     * Constructs one immutable waitlist-offer value object.
     *
     * @param int $id
     * @param int $optionid
     * @param int $userid
     * @param int $baid
     * @param int $roundid
     * @param offer_status $status
     * @param int $sortorder
     * @param int $offeredat
     * @param int $expiresat
     * @param int $ruleid
     * @param int $version
     * @param int $timecreated
     * @param int $timemodified
     */
    public function __construct(
        int $id,
        int $optionid,
        int $userid,
        int $baid,
        int $roundid,
        offer_status $status,
        int $sortorder,
        int $offeredat,
        int $expiresat,
        int $ruleid,
        int $version,
        int $timecreated,
        int $timemodified
    ) {
        $this->id = $id;
        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->baid = $baid;
        $this->roundid = $roundid;
        $this->status = $status;
        $this->sortorder = $sortorder;
        $this->offeredat = $offeredat;
        $this->expiresat = $expiresat;
        $this->ruleid = $ruleid;
        $this->version = $version;
        $this->timecreated = $timecreated;
        $this->timemodified = $timemodified;
    }
}
