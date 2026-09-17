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
 * Trigger adapter (Phase 3, WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.3, T1-T3): drives the new
 * reconciler for every "a seat may have become free" trigger. All four legacy call sites
 * (cancellation, maxanswers increase, campaign end, the generic case - booking_option.php:978/
 * 1789/5290, task/purge_campaign_caches.php:143) already funnel through the single function
 * booking_option::check_if_free_to_book_again(), so this adapter only needs one call site of its
 * own, not four. The bookingoption_freetobookagain event itself keeps firing unchanged (backward
 * compatibility for third-party rules/logs/integrations) - it is simply no longer the transport
 * mechanism for waitlist progression.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event\observer;

use mod_booking\local\waitlist\progression_factory;

/**
 * Reconciles an option whenever it may have just become bookable again.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class freetobookagain_waitlist_adapter {
    /**
     * Reconciles the option's waiting list.
     *
     * @param int $optionid
     * @return void
     */
    public static function reconcile(int $optionid): void {
        progression_factory::get()->reconcile($optionid, 'freetobookagain');
    }
}
