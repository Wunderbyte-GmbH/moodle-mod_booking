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
 * Trigger adapter (Phase 3, WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.3, T5): reconciles a
 * waitinglist candidate immediately when they join, in case capacity is already free at that
 * exact moment (e.g. waitforconfirmation routes everyone onto the waitinglist unconditionally,
 * independent of actual free capacity, or an earlier reconcile() pass already exhausted the older
 * candidates without this new one being considered). Without this adapter, only the T7
 * waitlist_heartbeat_task eventually catches this edge case (up to ~15 min delay instead of
 * immediately).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event\observer;

use mod_booking\local\waitlist\progression_factory;

/**
 * Reconciles an option immediately after a user newly joins its waitinglist.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class latejoiner_waitlist_adapter {
    /**
     * Reconciles the option's waiting list right after a new candidate joined it.
     *
     * @param int $optionid
     * @return void
     */
    public static function reconcile(int $optionid): void {
        progression_factory::get()->reconcile($optionid, 'waitinglist:joined');
    }
}
