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
 * The state extracted from one legacy chain task record (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md
 * §7). Pure data holder - upgrade_step turns this into real booking_waitlist_offers rows, this
 * class only carries what was read out of the old rulejson/task shape.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\migration;

/**
 * Immutable value object for one extracted legacy chain state.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class legacy_chain_state {
    /** @var int */
    public $optionid;

    /** @var int */
    public $ruleid;

    /** @var int[] user ids already treated by the old chain, in treatment order. */
    public $usersalreadytreated;

    /** @var int the old repeat task's own scheduled run time - the deadline to preserve for the
     *  most recently treated user (WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §3.2). */
    public $nextruntime;

    /**
     * Constructs one immutable legacy chain state.
     *
     * @param int $optionid
     * @param int $ruleid
     * @param int[] $usersalreadytreated
     * @param int $nextruntime
     */
    public function __construct(
        int $optionid,
        int $ruleid,
        array $usersalreadytreated,
        int $nextruntime
    ) {
        $this->optionid = $optionid;
        $this->ruleid = $ruleid;
        $this->usersalreadytreated = $usersalreadytreated;
        $this->nextruntime = $nextruntime;
    }
}
