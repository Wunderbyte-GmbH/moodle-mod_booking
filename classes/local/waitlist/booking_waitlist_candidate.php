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
 * One waiting-list candidate under consideration by a booking_decision_strategy
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.1). Pure data holder, no business logic.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Value object wrapping a candidate for a decision.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_waitlist_candidate {
    /** @var int */
    public $optionid;

    /** @var int */
    public $userid;

    /** @var int booking_answers.id of this candidate's waiting-list answer. */
    public $baid;

    /** @var \stdClass full user record, needed by price::get_price() (P1: live lookup). */
    public $user;

    /**
     * Constructs one candidate.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $baid
     * @param \stdClass $user
     */
    public function __construct(int $optionid, int $userid, int $baid, \stdClass $user) {
        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->baid = $baid;
        $this->user = $user;
    }
}
